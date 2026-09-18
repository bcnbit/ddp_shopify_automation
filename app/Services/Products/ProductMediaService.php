<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\ActivityEvent;
use App\Enums\MediaUploadStatus;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Support\Audit\ActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Alta, orden y borrado de medios (RFC-0002 y RFC-0005).
 *
 * El original se guarda siempre tal cual llega y nunca se borra al reordenar o
 * marcar portada. El checksum `sha256` es la identidad del archivo: evita
 * duplicados en la ficha y permite no volver a subirlo a Shopify (RFC-0004).
 */
class ProductMediaService
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * Guarda un archivo subido y crea su fila.
     *
     * @return array{media: ProductMedia|null, duplicate: bool}
     */
    public function store(Product $product, UploadedFile $file, User $author): array
    {
        $disk = (string) config('media.disks.originals', 'media');

        // El hash y las dimensiones se calculan **sobre el flujo** del archivo, no
        // sobre una ruta del disco local: con el almacenamiento temporal en S3 el
        // archivo no existe como fichero en el servidor y `getRealPath()` fallaría.
        $sha256 = hash('sha256', $this->contentsOf($file));

        $existing = $product->media()->where('sha256', $sha256)->first();

        if ($existing !== null) {
            // Mismo archivo en la misma ficha: no se duplica el original. Hay
            // que descartar igualmente el temporal, o el archivo se quedaría
            // ocupando disco en cada intento repetido.
            $this->discardTemporaryFile($file);

            return ['media' => $existing, 'duplicate' => true];
        }

        $directory = trim((string) config('media.paths.originals', 'products/originals'), '/');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if ($path === false) {
            throw new RuntimeException('No se ha podido guardar la imagen original.');
        }

        [$width, $height] = $this->dimensionsOf($file);

        return DB::transaction(function () use ($product, $file, $path, $disk, $sha256, $width, $height, $author): array {
            $media = new ProductMedia([
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'bytes' => $file->getSize() ?: 0,
                'sha256' => $sha256,
                'width' => $width,
                'height' => $height,
                'upload_status' => MediaUploadStatus::Pending,
                'sort_order' => (int) $product->media()->max('sort_order') + 1,
            ]);

            $media->product_id = $product->getKey();
            // La primera imagen entra como principal para que la ficha nunca
            // quede sin portada mientras la persona termina de ordenar.
            $media->is_primary = ! $product->media()->exists();
            $media->save();

            $this->recorder->record(
                ActivityEvent::MediaUploaded,
                $product,
                "Imagen «{$media->original_filename}» añadida.",
                ['media_id' => $media->getKey(), 'sha256' => $sha256],
                actor: $author,
            );

            return ['media' => $media, 'duplicate' => false];
        });
    }

    public function delete(ProductMedia $media, User $author): bool
    {
        if ($media->shopify_media_gid !== null) {
            throw new RuntimeException('Esta imagen ya está en Shopify: no se elimina localmente.');
        }

        $product = $media->product;
        $wasPrimary = (bool) $media->is_primary;

        return DB::transaction(function () use ($media, $product, $wasPrimary, $author): bool {
            // El original tampoco se borra del disco: se conserva siempre (RFC-0000).
            $media->delete();

            if ($wasPrimary) {
                $next = $product->media()->orderBy('sort_order')->first();

                if ($next !== null) {
                    $next->is_primary = true;
                    $next->save();
                }
            }

            $this->recorder->record(
                ActivityEvent::MediaDeleted,
                $product,
                'Imagen eliminada de la ficha.',
                ['media_id' => $media->getKey()],
                actor: $author,
            );

            return true;
        });
    }

    /**
     * Persiste el orden que la persona ha definido arrastrando.
     *
     * @param  list<int|string>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds, User $author): void
    {
        DB::transaction(function () use ($product, $orderedIds, $author): void {
            $owned = $product->media()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $position = 0;

            foreach ($orderedIds as $id) {
                $id = (int) $id;

                if (! in_array($id, $owned, true)) {
                    continue;
                }

                ProductMedia::whereKey($id)->update(['sort_order' => $position]);
                $position++;
            }

            $this->recorder->record(
                ActivityEvent::Updated,
                $product,
                'Orden de imágenes actualizado.',
                ['order' => $orderedIds],
                actor: $author,
            );
        });
    }

    /**
     * Marca una imagen como principal. Sólo puede haber una.
     */
    public function markAsPrimary(Product $product, ProductMedia $media, User $author): void
    {
        if ((int) $media->product_id !== (int) $product->getKey()) {
            throw new RuntimeException('La imagen no pertenece a esta ficha.');
        }

        DB::transaction(function () use ($product, $media, $author): void {
            $product->media()->update(['is_primary' => false]);

            $media->is_primary = true;
            $media->save();

            $this->recorder->record(
                ActivityEvent::Updated,
                $product,
                'Foto principal actualizada.',
                ['media_id' => $media->getKey()],
                actor: $author,
            );
        });
    }

    public function updateAltText(ProductMedia $media, ?string $altText, User $author): void
    {
        $media->alt_text = $altText;
        $media->save();

        $this->recorder->record(
            ActivityEvent::Updated,
            $media->product,
            'Texto ALT actualizado.',
            ['media_id' => $media->getKey()],
            actor: $author,
        );
    }

    /**
     * Descarta el archivo temporal de una subida que no se va a conservar.
     *
     * Sólo aplica a subidas gestionadas por Filament (`TemporaryUploadedFile`),
     * que no se borran por sí solas cuando la acción no llega a guardarlas.
     */
    private function discardTemporaryFile(UploadedFile $file): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $file->delete();
        } catch (\Throwable) {
            // Que no se pueda borrar el temporal no debe impedir responder a la
            // persona: el dato importante (no duplicar) ya está resuelto.
        }
    }

    /**
     * Dimensiones reales de la imagen.
     *
     * `getimagesize()` necesita una ruta **local**. Con el almacenamiento temporal
     * en S3 no la hay, así que se escribe el contenido en un fichero temporal del
     * sistema y se mide ahí. Se hace en el momento de guardar, cuando el archivo ya
     * está en memoria, y no en cada lectura.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensionsOf(UploadedFile $file): array
    {
        $path = null;
        $temporary = null;

        try {
            $path = $file->getRealPath();

            // Con un bucket, `getRealPath()` **sí devuelve una cadena**, pero esa
            // ruta no existe en el servidor: hay que comprobar que sea legible, no
            // sólo que no sea `false`. Sin esta comprobación `getimagesize()`
            // devolvía `false` en silencio y las dimensiones quedaban vacías.
            if ($path === false || ! is_readable($path)) {
                $temporary = tempnam(sys_get_temp_dir(), 'media-');

                if ($temporary === false) {
                    return [null, null];
                }

                file_put_contents($temporary, $this->contentsOf($file));
                $path = $temporary;
            }

            $size = @getimagesize($path);
        } catch (\Throwable) {
            return [null, null];
        } finally {
            // El temporal se borra siempre, también si la medición falla.
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Contenido binario del archivo subido, venga de donde venga.
     *
     * Se prefiere la ruta local cuando existe (es lo más barato en disco) y se cae
     * al flujo cuando el archivo vive en un almacenamiento externo. Es la pieza que
     * hace que el mismo código funcione con el temporal en local y en S3.
     */
    private function contentsOf(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path !== false && is_readable($path)) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                return $contents;
            }
        }

        $stream = $file->readStream();

        if ($stream === false) {
            throw new RuntimeException('No se ha podido leer la imagen subida.');
        }

        try {
            $contents = stream_get_contents($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($contents === false || $contents === '') {
            throw new RuntimeException('No se ha podido leer la imagen subida.');
        }

        return $contents;
    }

    public static function exists(ProductMedia $media): bool
    {
        try {
            return Storage::disk($media->disk)->exists($media->path);
        } catch (\Throwable) {
            return false;
        }
    }
}

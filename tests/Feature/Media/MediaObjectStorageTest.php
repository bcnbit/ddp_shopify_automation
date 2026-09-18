<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Products\ProductMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * Medios sobre un almacenamiento de objetos (RFC-0007).
 *
 * El motivo de estas pruebas: con el temporal en S3 el archivo **no existe como
 * fichero en el servidor**. El código que se apoye en una ruta local
 * (`getRealPath()`, `getimagesize()`) funciona en desarrollo —donde el disco es
 * local— y falla sólo en producción. Eso es exactamente lo que hay que atrapar.
 *
 * Por eso el doble central no es un disco, sino un **archivo sin ruta local**:
 * `getRealPath()` devuelve `false`, como con un bucket. Es la condición que
 * distingue los dos entornos y la que hace que estas pruebas fallen si alguien
 * vuelve a apoyarse en el sistema de archivos.
 */
class MediaObjectStorageTest extends TestCase
{
    /**
     * Archivo subido que se comporta como uno alojado en un bucket.
     *
     * Conserva los bytes reales de una imagen válida, pero **no ofrece ruta local**.
     * `readStream()` es lo único que un bucket puede dar.
     */
    private function objectStorageUpload(string $name = 'camiseta.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefilledrectangle($image, 0, 0, 1200, 900, imagecolorallocate($image, 10, 20, 30));

        $path = tempnam(sys_get_temp_dir(), 'img-').'.jpg';
        imagejpeg($image, $path, 80);
        imagedestroy($image);

        // Se imita lo que hace Livewire con un archivo en S3: `getRealPath()` no
        // existe y el guardado va por **stream**. Si sólo se anulara `getRealPath()`
        // sin más, `storeAs()` de Laravel usaría su propia ruta basada en rutas
        // locales y la prueba fallaría por un motivo irreal.
        return new class($path, $name) extends TemporaryUploadedFile
        {
            private readonly string $name;

            public function __construct(private readonly string $realFile, string $name)
            {
                $this->name = $name;

                // `TemporaryUploadedFile` espera (path, disk): se le da el disco de
                // objetos para que su propio `storeAs()` escriba donde corresponde.
                parent::__construct('objeto-en-bucket.jpg', 'media-s3');
            }

            /**
             * Clave de la prueba.
             *
             * Con un bucket, `getRealPath()` **sí devuelve una cadena** (la ruta
             * interna del objeto, del estilo `livewire-tmp/abc.jpg`), pero esa ruta
             * **no existe en el sistema de archivos**. Es lo que hace que
             * `hash_file()` o `getimagesize()` fallen aunque no den error de tipo:
             * es el fallo silencioso que se coló en producción.
             */
            public function getRealPath(): string
            {
                return 'livewire-tmp/objeto-en-bucket.jpg';
            }

            public function readStream()
            {
                return fopen($this->realFile, 'rb');
            }

            public function getSize(): int
            {
                return (int) filesize($this->realFile);
            }

            public function getClientOriginalName(): string
            {
                return $this->name;
            }

            public function getMimeType(): string
            {
                return 'image/jpeg';
            }

            /**
             * Igual que Livewire: guarda por stream, sin ruta local.
             */
            public function storeAs($path, $name = null, $options = [])
            {
                $options = is_array($options) ? $options : [];
                $disk = $options['disk'] ?? config('filesystems.default');
                $newPath = trim($path.'/'.$name, '/');

                Storage::disk($disk)->put($newPath, $this->readStream(), $options);

                return $newPath;
            }
        };
    }

    /**
     * Bytes esperados, para calcular el hash de referencia en la propia prueba.
     */
    private function bytesOf(UploadedFile $file): string
    {
        $stream = $file->readStream();
        $contents = stream_get_contents($stream);
        fclose($stream);

        return (string) $contents;
    }

    private function objectDisk(string $name = 'media-s3'): void
    {
        config()->set("filesystems.disks.{$name}", [
            'driver' => 's3',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'region' => 'eu-west-1',
            'bucket' => 'bucket-de-prueba',
            'visibility' => 'private',
        ]);

        Storage::fake($name);
    }

    // ------------------------------------------------------- guardado

    public function test_guarda_el_original_aunque_no_tenga_ruta_local(): void
    {
        $this->objectDisk();
        config()->set('media.disks.originals', 'media-s3');

        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $result = app(ProductMediaService::class)->store($product, $this->objectStorageUpload(), $operadora);

        $this->assertNotNull($result['media']);
        $this->assertSame('media-s3', $result['media']->disk);
        Storage::disk('media-s3')->assertExists($result['media']->path);
    }

    public function test_calcula_el_sha256_sin_ruta_local(): void
    {
        $this->objectDisk();
        config()->set('media.disks.originals', 'media-s3');

        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $file = $this->objectStorageUpload();
        $expected = hash('sha256', $this->bytesOf($file));

        $result = app(ProductMediaService::class)->store($product, $file, $operadora);

        // Si se calculara sobre una ruta inexistente saldría el hash de la cadena
        // vacía: todos los archivos parecerían el mismo y la ficha los trataría como
        // duplicados entre sí.
        $this->assertSame($expected, $result['media']->sha256);
        $this->assertNotSame(hash('sha256', ''), $result['media']->sha256);
    }

    public function test_mide_las_dimensiones_sin_ruta_local(): void
    {
        $this->objectDisk();
        config()->set('media.disks.originals', 'media-s3');

        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $result = app(ProductMediaService::class)->store($product, $this->objectStorageUpload(), $operadora);

        // Las dimensiones alimentan los avisos de resolución de RFC-0005: sin ellas,
        // una imagen de baja calidad pasaría sin avisar.
        $this->assertSame(1200, $result['media']->width);
        $this->assertSame(900, $result['media']->height);
    }

    public function test_sigue_detectando_duplicados_sin_ruta_local(): void
    {
        $this->objectDisk();
        config()->set('media.disks.originals', 'media-s3');

        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $service = app(ProductMediaService::class);

        $first = $service->store($product, $this->objectStorageUpload(), $operadora);
        $second = $service->store($product, $this->objectStorageUpload(), $operadora);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, $product->media()->count());
    }

    // ------------------------------------------------------- lectura

    public function test_un_bucket_privado_se_lee_con_url_firmada(): void
    {
        $this->objectDisk();

        $media = ProductMedia::factory()->create([
            'disk' => 'media-s3',
            'path' => 'products/originals/foto.jpg',
        ]);

        $this->assertTrue(ProductMedia::isPrivateObjectStorage('media-s3'));

        $url = (string) $media->url();

        // Con un bucket privado, la URL directa existe pero da 403 al abrirla: lo
        // que distingue una respuesta correcta es que esté **firmada**.
        $this->assertStringContainsString('expiration=', $url);
    }

    public function test_un_disco_local_no_se_trata_como_bucket(): void
    {
        // El disco local de medios es privado, pero lo sirve la aplicación con su
        // propia ruta: no hay que firmar nada.
        $this->assertFalse(ProductMedia::isPrivateObjectStorage('media'));
        $this->assertFalse(ProductMedia::isPrivateObjectStorage('media-derived'));
        $this->assertFalse(ProductMedia::isPrivateObjectStorage('local'));
    }

    public function test_un_bucket_publico_no_necesita_firma(): void
    {
        config()->set('filesystems.disks.cdn', [
            'driver' => 's3',
            'visibility' => 'public',
        ]);

        // La comprobación mira el driver Y la visibilidad, no el nombre del disco.
        $this->assertFalse(ProductMedia::isPrivateObjectStorage('cdn'));
    }

    public function test_un_disco_inexistente_no_revienta(): void
    {
        $this->assertFalse(ProductMedia::isPrivateObjectStorage('no-existe'));
    }

    public function test_lo_ya_subido_en_local_sigue_leyendose(): void
    {
        Storage::fake('media');

        $media = ProductMedia::factory()->create([
            'disk' => 'media',
            'path' => 'products/originals/antigua.jpg',
        ]);

        // Cada fila guarda su disco: mover los originales a un bucket no deja
        // huérfanas las imágenes que ya estaban en local.
        $this->assertSame('media', $media->disk);
        $this->assertFalse(ProductMedia::isPrivateObjectStorage($media->disk));
    }
}

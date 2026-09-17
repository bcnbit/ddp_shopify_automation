<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Prepara las imágenes que se envían a la IA (RFC-0003).
 *
 * El contrato de entrada permite «hasta cuatro fotos representativas
 * redimensionadas». Redimensionar aquí no es una optimización: enviar originales
 * de 4000 px dispara el coste y el tiempo de respuesta, así que se reduce a un
 * tamaño suficiente para que el modelo describa la prenda.
 *
 * Si no se puede leer una imagen, se omite en lugar de fallar: la descripción
 * puede generarse con el texto confirmado.
 */
class ImagePreparer
{
    /** Máximo de imágenes que se adjuntan (RFC-0003). */
    public const MAX_IMAGES = 4;

    /**
     * Devuelve data URIs listas para el proveedor.
     *
     * @return list<string>
     */
    public function forProduct(Product $product): array
    {
        $media = $product->media;

        if ($media->isEmpty()) {
            return [];
        }

        // Las imágenes con ALT ya aprobado son las más representativas; después
        // la principal, y por último el resto en su orden.
        $ordered = $media
            ->sortBy([
                fn (ProductMedia $item): int => $item->is_primary ? 0 : 1,
                fn (ProductMedia $item): int => $item->sort_order,
            ])
            ->take(self::MAX_IMAGES);

        $dataUris = [];

        foreach ($ordered as $item) {
            $dataUri = $this->toDataUri($item);

            if ($dataUri !== null) {
                $dataUris[] = $dataUri;
            }
        }

        return $dataUris;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return list<string>
     */
    public function forMediaIds(Product $product, array $mediaIds): array
    {
        $selected = $product->media->whereIn('id', $mediaIds)->take(self::MAX_IMAGES);
        $dataUris = [];

        foreach ($selected as $item) {
            $dataUri = $this->toDataUri($item);

            if ($dataUri !== null) {
                $dataUris[] = $dataUri;
            }
        }

        return $dataUris;
    }

    private function toDataUri(ProductMedia $media): ?string
    {
        try {
            $disk = Storage::disk($media->disk);

            if (! $disk->exists($media->path)) {
                return null;
            }

            $contents = $disk->get($media->path);
        } catch (\Throwable) {
            return null;
        }

        if ($contents === null || $contents === '') {
            return null;
        }

        $resized = $this->resize($contents, $media->mime_type);
        $mime = $resized['mime'] ?? $media->mime_type;

        return 'data:'.$mime.';base64,'.base64_encode($resized['bytes'] ?? $contents);
    }

    /**
     * Reduce la imagen al lado máximo configurado conservando la proporción.
     *
     * Si no hay extensión GD disponible, se devuelve el original: es preferible
     * enviar una imagen grande que no enviar ninguna.
     *
     * @return array{bytes: string, mime: string}
     */
    private function resize(string $contents, string $mime): array
    {
        $maxSide = (int) config('product-studio.ai.image_max_side', 1024);
        $quality = (int) config('product-studio.ai.image_quality', 80);

        if (! function_exists('imagecreatefromstring')) {
            return ['bytes' => $contents, 'mime' => $mime];
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return ['bytes' => $contents, 'mime' => $mime];
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width <= $maxSide && $height <= $maxSide) {
                return ['bytes' => $contents, 'mime' => $mime];
            }

            $scale = min($maxSide / max($width, 1), $maxSide / max($height, 1));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            // Fondo blanco para que un PNG con transparencia no salga en negro
            // al recomprimir como JPEG.
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            imagejpeg($target, null, $quality);
            $bytes = (string) ob_get_clean();

            imagedestroy($target);

            if ($bytes === '') {
                return ['bytes' => $contents, 'mime' => $mime];
            }

            return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
        } finally {
            imagedestroy($source);
        }
    }
}

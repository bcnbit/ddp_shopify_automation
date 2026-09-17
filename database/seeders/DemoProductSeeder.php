<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Datos ficticios para desarrollo local (RFC-0007).
 *
 * Sólo se ejecuta con `--env=local`. Nunca debe ejecutarse en producción.
 */
class DemoProductSeeder extends Seeder
{
    public function run(): void
    {
        $operadora = User::whereHas('roles', fn ($q) => $q->where('name', 'operadora'))->first()
            ?? User::factory()->operadora()->create();

        $product = Product::factory()
            ->withConfirmedFacts()
            ->inReview()
            ->create(['created_by' => $operadora->getKey()]);

        ProductContent::factory()
            ->aiGenerated()
            ->forProduct($product)
            ->create();

        $this->createDemoImage($product);

        $combinations = [
            ['Blanco', 'S'],
            ['Blanco', 'M'],
            ['Negro', 'M'],
            ['Negro', 'L'],
        ];

        foreach ($combinations as $index => [$color, $size]) {
            ProductVariant::factory()
                ->forProduct($product)
                ->combination($color, $size)
                ->create([
                    'sku' => $product->internal_reference.'-'.strtoupper($color[0]).'-'.$size,
                    'position' => $index,
                ]);
        }

        $this->command?->info('Ficha de demostración creada: '.$product->internal_reference);
    }

    /**
     * Crea una imagen real en disco, no sólo la fila en base de datos.
     *
     * El factory generaba la fila con una ruta inventada, así que el archivo no
     * existía. La ficha parecía completa en el panel, pero al enviarla a Shopify
     * fallaba con «no se encuentra el archivo de imagen». Una ficha de
     * demostración tiene que poder enviarse de verdad.
     */
    private function createDemoImage(Product $product): void
    {
        $disk = (string) config('media.disks.originals', 'media');
        $filename = Str::uuid()->toString().'.jpg';
        $path = 'products/originals/'.$filename;

        // Un JPEG mínimo válido, generado en memoria: no se versiona ningún
        // binario y la imagen existe de verdad para poder subirla.
        $image = imagecreatetruecolor(1200, 1200);
        $background = imagecolorallocate($image, 240, 236, 228);
        imagefilledrectangle($image, 0, 0, 1200, 1200, $background);
        $text = imagecolorallocate($image, 120, 120, 120);
        imagestring($image, 5, 40, 40, 'Demo — '.$product->internal_reference, $text);

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk($disk)->put($path, $bytes);

        ProductMedia::factory()
            ->forProduct($product)
            ->primary()
            ->withAltText()
            ->create([
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $filename,
                'bytes' => mb_strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'width' => 1200,
                'height' => 1200,
                'sort_order' => 0,
            ]);
    }
}

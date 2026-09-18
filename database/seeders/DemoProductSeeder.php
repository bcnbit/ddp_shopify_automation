<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
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

        // La ficha de demostración usa los mantenimientos de RFC-0008 en lugar
        // de textos sueltos: es la forma de que el ejemplo enseñe el flujo real.
        $product = Product::factory()
            ->inReview()
            ->create([
                'created_by' => $operadora->getKey(),
                'composition' => null,
                'fit' => null,
                'care_instructions' => null,
                'ai_base_description' => 'Inspirada en las tardes de agosto junto al mar: algodón fresco y acabado mate.',
                'technical_sheet_composition_id' => $this->idOf(TechnicalSheetComposition::class, 'COMP-ALG-100'),
                'technical_sheet_fit_id' => $this->idOf(TechnicalSheetFit::class, 'FIT-UNISEX-REG'),
                'technical_sheet_care_id' => $this->idOf(TechnicalSheetCare::class, 'CARE-ALG-BASICO'),
                'technical_sheet_size_guide_id' => $this->idOf(TechnicalSheetSizeGuide::class, 'TALLA-CAMISETA-ADULTO'),
            ]);

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
     * Identificador del mantenimiento por código, o `null` si no existe.
     *
     * El catálogo lo crea `TechnicalSheetMaintenanceSeeder`; si alguien ejecuta
     * este seeder por separado, la ficha se crea igualmente y sin mantenimiento
     * en lugar de fallar.
     *
     * @param  class-string  $model
     */
    private function idOf(string $model, string $code): ?int
    {
        $id = $model::query()->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\MediaUploadStatus;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMediaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permite_el_mismo_archivo_dos_veces_en_un_producto(): void
    {
        $product = Product::factory()->create();
        $sha = hash('sha256', 'contenido-de-prueba');

        ProductMedia::factory()->forProduct($product)->create(['sha256' => $sha]);

        $this->expectException(QueryException::class);

        ProductMedia::factory()->forProduct($product)->create(['sha256' => $sha]);
    }

    public function test_el_mismo_archivo_si_puede_repetirse_en_productos_distintos(): void
    {
        $sha = hash('sha256', 'contenido-de-prueba');

        ProductMedia::factory()->create(['sha256' => $sha]);
        ProductMedia::factory()->create(['sha256' => $sha]);

        $this->assertSame(2, ProductMedia::where('sha256', $sha)->count());
    }

    public function test_se_ordenan_por_sort_order(): void
    {
        $product = Product::factory()->create();
        ProductMedia::factory()->forProduct($product)->create(['sort_order' => 2, 'original_filename' => 'c.jpg']);
        ProductMedia::factory()->forProduct($product)->create(['sort_order' => 0, 'original_filename' => 'a.jpg']);

        $this->assertSame(['a.jpg', 'c.jpg'], $product->media()->pluck('original_filename')->all());
    }

    public function test_arrancan_pendientes_de_subir(): void
    {
        $media = ProductMedia::factory()->create();

        $this->assertSame(MediaUploadStatus::Pending, $media->upload_status);
        $this->assertFalse($media->isUploaded());
    }

    public function test_marcan_si_tienen_alt(): void
    {
        $this->assertFalse(ProductMedia::factory()->create()->hasAltText());

        $alt = 'Camiseta Dies de Platja en color arena';
        $this->assertTrue(ProductMedia::factory()->withAltText($alt)->create()->hasAltText());
    }

    public function test_registran_las_dimensiones_para_detectar_baja_resolucion(): void
    {
        $media = ProductMedia::factory()->lowResolution()->create();

        $this->assertSame(320, $media->width);
        $this->assertSame(320, $media->height);
    }

    public function test_formatean_el_tamano_en_unidades_legibles(): void
    {
        $this->assertSame('1,0 MB', ProductMedia::factory()->create(['bytes' => 1048576])->humanFileSize());
        $this->assertSame('512 KB', ProductMedia::factory()->create(['bytes' => 524288])->humanFileSize());
        $this->assertSame('900 B', ProductMedia::factory()->create(['bytes' => 900])->humanFileSize());
    }

    public function test_el_cascade_elimina_los_medios_con_el_producto(): void
    {
        $product = Product::factory()->create();
        ProductMedia::factory()->forProduct($product)->create();

        $product->delete();

        $this->assertSame(0, ProductMedia::count());
    }
}

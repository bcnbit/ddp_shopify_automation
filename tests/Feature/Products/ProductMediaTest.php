<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Products\ProductMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Medios de producto (RFC-0002 / RFC-0005).
 *
 * El original nunca se borra y un mismo archivo no se guarda dos veces en la
 * misma ficha. El checksum es la identidad del archivo.
 */
class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    private function service(): ProductMediaService
    {
        return app(ProductMediaService::class);
    }

    public function test_guarda_el_original_con_su_checksum_y_dimensiones(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $file = UploadedFile::fake()->image('camiseta.jpg', 1200, 1500);

        $result = $this->service()->store($product, $file, $operadora);
        $media = $result['media'];

        $this->assertFalse($result['duplicate']);
        $this->assertNotNull($media);
        $this->assertSame('camiseta.jpg', $media->original_filename);
        $this->assertSame(1200, $media->width);
        $this->assertSame(1500, $media->height);
        $this->assertSame(64, strlen($media->sha256));
        Storage::disk('media')->assertExists($media->path);
    }

    public function test_la_primera_imagen_es_la_principal(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $media = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];

        $this->assertTrue($media->is_primary);
    }

    public function test_las_siguientes_imagenes_no_desplazan_la_portada(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $first = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $second = $this->service()->store($product, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];

        $this->assertTrue($first->refresh()->is_primary);
        $this->assertFalse($second->is_primary);
    }

    public function test_no_guarda_dos_veces_el_mismo_archivo_en_la_misma_ficha(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        // Dos subidas distintas con contenido idéntico y nombre distinto: la
        // identidad es el checksum, no el nombre del archivo. `createWithContent`
        // permite controlar los bytes exactos; las imágenes de `fake()->image()`
        // del mismo tamaño son idénticas entre sí, lo que haría pasar la prueba
        // por el motivo equivocado.
        $bytes = (string) UploadedFile::fake()->image('origen.jpg', 900, 900)->get();

        $first = $this->service()->store(
            $product,
            UploadedFile::fake()->createWithContent('camiseta.jpg', $bytes),
            $operadora,
        );
        $second = $this->service()->store(
            $product,
            UploadedFile::fake()->createWithContent('camiseta-copia.jpg', $bytes),
            $operadora,
        );

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['media']->getKey(), $second['media']->getKey());
        $this->assertSame(1, $product->media()->count());
    }

    public function test_marcar_otra_imagen_como_principal_desplaza_la_anterior(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $first = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $second = $this->service()->store($product, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];

        $this->service()->markAsPrimary($product, $second, $operadora);

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
        $this->assertSame(1, $product->media()->where('is_primary', true)->count());
    }

    public function test_no_se_puede_marcar_como_principal_una_imagen_de_otra_ficha(): void
    {
        $operadora = $this->operadora();
        $uno = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $dos = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $media = $this->service()->store($dos, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];

        $this->expectException(RuntimeException::class);

        $this->service()->markAsPrimary($uno, $media, $operadora);
    }

    public function test_el_borrado_conserva_el_original_en_el_disco(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $media = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $path = $media->path;

        $this->service()->delete($media, $operadora);

        $this->assertSame(0, $product->media()->count());
        // El original se conserva: es un principio no negociable del RFC-0000.
        Storage::disk('media')->assertExists($path);
    }

    public function test_al_borrar_la_portada_otra_imagen_pasa_a_serlo(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $primary = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $other = $this->service()->store($product, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];

        $this->service()->delete($primary, $operadora);

        $this->assertTrue($other->refresh()->is_primary);
    }

    public function test_no_se_borra_una_imagen_ya_enviada_a_shopify(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $media = ProductMedia::factory()->forProduct($product)->uploaded()->create();

        $this->expectException(RuntimeException::class);

        $this->service()->delete($media, $operadora);
    }

    public function test_reordena_las_imagenes_y_persiste_el_orden(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $a = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $b = $this->service()->store($product, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];
        $c = $this->service()->store($product, UploadedFile::fake()->image('c.jpg', 1200, 1200), $operadora)['media'];

        $this->service()->reorder($product, [$c->getKey(), $a->getKey(), $b->getKey()], $operadora);

        $ordered = $product->media()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->assertSame([$c->getKey(), $a->getKey(), $b->getKey()], $ordered);
    }

    public function test_el_reordenado_ignora_identificadores_de_otras_fichas(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $other = Product::factory()->create(['created_by' => $operadora->getKey()]);

        $mine = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];
        $foreign = $this->service()->store($other, UploadedFile::fake()->image('b.jpg', 1100, 1100), $operadora)['media'];

        $foreignOrderBefore = $foreign->sort_order;

        $this->service()->reorder($product, [$foreign->getKey(), $mine->getKey()], $operadora);

        // La imagen ajena no pertenece a esta ficha: su orden no se toca.
        $this->assertSame($foreignOrderBefore, $foreign->refresh()->sort_order);
        // La propia acaba en la posición que permite el conjunto filtrado.
        $this->assertSame(0, $mine->refresh()->sort_order);
    }

    public function test_actualiza_el_alt(): void
    {
        $operadora = $this->operadora();
        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);
        $media = $this->service()->store($product, UploadedFile::fake()->image('a.jpg', 1000, 1000), $operadora)['media'];

        $this->service()->updateAltText($media, 'Camiseta blanca vista de frente', $operadora);

        $this->assertTrue($media->refresh()->hasAltText());
    }
}

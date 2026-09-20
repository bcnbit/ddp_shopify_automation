<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Enums\ProductStatus;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Services\Products\ProductService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Eliminar una ficha de la aplicación (RFC-0002).
 *
 * Tres reglas que fija esta función y que aquí quedan como prueba, para que un
 * cambio futuro no las revierta sin querer:
 *
 * 1. Se borran las filas **y** los ficheros, incluidos los derivados.
 * 2. **Shopify no se toca.** El producto de la tienda sigue donde estaba.
 * 3. El rastro queda en auditoría **con el GID remoto**, que es lo único que
 *    permite localizar el producto en Shopify después de perder la fila local.
 */
class DeleteProductTest extends TestCase
{
    private function productWithMedia(array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);

        ProductVariant::factory()->forProduct($product)->create(['sku' => 'DDP-5000-S']);
        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();

        return $product->refresh();
    }

    /**
     * Deja en disco el original y su derivado, que es lo que hay que borrar.
     */
    private function materialize(Product $product): void
    {
        foreach ($product->media as $media) {
            Storage::disk($media->disk)->put($media->path, 'original');
            Storage::disk((string) config('media.disks.derived'))->put($media->path, 'derivado');
        }
    }

    public function test_elimina_la_ficha_y_sus_filas_hijas(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $product = $this->productWithMedia();

        app(ProductService::class)->delete($product, $this->admin());

        $this->assertNull(Product::find($product->getKey()));
        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, ProductMedia::count());
    }

    public function test_borra_el_original_y_el_derivado_del_disco(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $product = $this->productWithMedia();
        $this->materialize($product);

        $media = $product->media->first();

        Storage::disk('media')->assertExists($media->path);
        Storage::disk('media-derived')->assertExists($media->path);

        $stats = app(ProductService::class)->delete($product, $this->admin());

        // Ninguno de los dos sobrevive a la ficha.
        Storage::disk('media')->assertMissing($media->path);
        Storage::disk('media-derived')->assertMissing($media->path);

        $this->assertSame(2, $stats['media_files']);
        $this->assertSame(0, $stats['media_failed']);
    }

    public function test_no_falla_si_el_derivado_no_existe(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $product = $this->productWithMedia();

        // Sólo el original: hoy no hay código que genere derivados.
        foreach ($product->media as $media) {
            Storage::disk($media->disk)->put($media->path, 'original');
        }

        $stats = app(ProductService::class)->delete($product, $this->admin());

        // Un derivado sin generar no es un error, y contarlo como tal haría
        // desconfiar de un borrado que ha ido bien.
        $this->assertSame(1, $stats['media_files']);
        $this->assertSame(0, $stats['media_failed']);
    }

    public function test_se_elimina_una_ficha_ya_sincronizada_sin_tocar_shopify(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $gid = 'gid://shopify/Product/4242';
        $product = $this->productWithMedia([
            'status' => ProductStatus::ShopifyDraft,
            'shopify_product_gid' => $gid,
        ]);

        app(ProductService::class)->delete($product, $this->admin());

        // La fila local desaparece, pero nada del código llama a Shopify: el
        // producto remoto se queda como estaba. Si esta prueba empieza a fallar
        // por una llamada saliente, es que alguien ha añadido un borrado remoto.
        $this->assertNull(Product::find($product->getKey()));
    }

    public function test_la_auditoria_conserva_el_gid_y_la_referencia(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $product = $this->productWithMedia([
            'status' => ProductStatus::ShopifyDraft,
            'shopify_product_gid' => 'gid://shopify/Product/4242',
        ]);

        app(ProductService::class)->delete($product, $this->admin());

        $log = ActivityLog::query()->where('event', 'deleted')->latest('id')->first();

        $this->assertNotNull($log);
        // Sin el GID en la auditoría no queda forma de localizar el producto en
        // Shopify una vez borrada la fila.
        $this->assertSame('gid://shopify/Product/4242', $log->properties['shopify_product_gid']);
        $this->assertSame($product->internal_reference, $log->properties['internal_reference']);
        $this->assertTrue($log->properties['shopify_left_untouched']);
    }

    public function test_una_ficha_sin_imagenes_se_elimina_sin_tocar_discos(): void
    {
        Storage::fake('media');
        Storage::fake('media-derived');

        $product = Product::factory()->create();

        $stats = app(ProductService::class)->delete($product, $this->admin());

        $this->assertNull(Product::find($product->getKey()));
        $this->assertSame(0, $stats['media_files']);
        $this->assertSame(0, $stats['media_failed']);
    }
}

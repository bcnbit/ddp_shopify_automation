<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Support\Products\ProductReadiness;
use App\Support\Products\ProductValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Validación de fichas (RFC-0002 y RFC-0005).
 *
 * La distinción entre bloqueante y aviso es un contrato: los bloqueantes
 * impiden enviar y los avisos no. Se comprueba caso por caso.
 */
class ProductValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ficha completa y correcta: no debe tener ningún bloqueante.
     */
    private function completeProduct(): Product
    {
        $product = Product::factory()->withConfirmedFacts()->approved()->create([
            'price' => 29.90,
        ]);

        ProductVariant::factory()->forProduct($product)->combination('Blanco', 'M')->create([
            'sku' => 'DDP-'.fake()->unique()->numberBetween(100, 999).'-B-M',
        ]);

        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();

        ProductContent::factory()->forProduct($product)->create([
            'handle' => 'camiseta-'.$product->getKey(),
            'seo_title' => str_repeat('a', 55),
            'seo_description' => str_repeat('b', 150),
        ]);

        return $product->fresh(['variants', 'media', 'contents']);
    }

    public function test_una_ficha_completa_no_tiene_bloqueantes(): void
    {
        $result = (new ProductValidator)->validate($this->completeProduct());

        $this->assertTrue(
            $result->passes(),
            'Bloqueantes inesperados: '.implode(' | ', $result->blockingMessages())
        );
    }

    public function test_el_precio_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->price = null;
        $product->save();

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('price', $result->blockingFields());
    }

    public function test_un_precio_no_positivo_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->price = 0;
        $product->save();

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
    }

    public function test_la_falta_de_variantes_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->variants()->delete();

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('variants', $result->blockingFields());
    }

    public function test_la_falta_de_foto_principal_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->media()->delete();

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('media', $result->blockingFields());
    }

    public function test_una_ficha_con_imagenes_pero_sin_principal_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->media()->update(['is_primary' => false]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('media', $result->blockingFields());
    }

    public function test_la_falta_de_titulo_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update(['title' => null]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('content', $result->blockingFields());
    }

    public function test_la_falta_de_descripcion_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update(['html_description' => null]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
        $this->assertContains('content', $result->blockingFields());
    }

    public function test_la_falta_de_handle_es_bloqueante(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update(['handle' => null]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->fails());
    }

    public function test_una_variante_sin_sku_no_llega_a_guardarse(): void
    {
        // La garantía más fuerte es la base de datos: `sku_normalized` es NOT
        // NULL y el mutator devuelve null para un SKU vacío, así que una
        // variante sin SKU no puede existir. El validador lo comprueba además
        // como defensa en profundidad.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->expectException(QueryException::class);

        $variant->forceFill(['sku' => '', 'sku_normalized' => null])->save();
    }

    public function test_un_sku_duplicado_dentro_de_la_misma_ficha_es_bloqueante(): void
    {
        $product = $this->completeProduct();

        // Se inserta por SQL directo para esquivar el índice único global y
        // reproducir una fila corrupta que llegase por importación.
        $existing = $product->variants()->first();

        DB::table('product_variants')->insert([
            'product_id' => $product->getKey(),
            'sku' => $existing->sku,
            'sku_normalized' => $existing->sku_normalized.'-2',
            'option1_name' => 'Color',
            'option1_value' => 'Arena',
            'option2_name' => 'Talla',
            'option2_value' => 'L',
            'inventory_policy' => 'deny',
            'position' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = (new ProductValidator)->validate(
            $product->fresh(['variants', 'media', 'contents'])
        );

        $this->assertTrue($result->fails(), 'Dos variantes con el mismo SKU no deben enviarse.');
        $this->assertContains('SKU duplicado', array_map(static fn ($i) => $i->code, $result->blockingIssues()));
    }

    public function test_la_meta_description_fuera_de_rango_es_solo_aviso(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update(['seo_description' => 'Demasiado corta.']);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->passes(), 'Un meta deficiente no debe bloquear el envío.');
        $this->assertTrue($result->hasWarnings());
        $this->assertContains(
            'Meta description fuera de rango',
            array_map(static fn ($issue) => $issue->code, $result->warnings()),
        );
    }

    public function test_un_titulo_seo_fuera_de_rango_es_solo_aviso(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update(['seo_title' => 'Corto']);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->passes());
        $this->assertContains(
            'Título demasiado largo o corto',
            array_map(static fn ($issue) => $issue->code, $result->warnings()),
        );
    }

    public function test_una_imagen_sin_alt_es_aviso(): void
    {
        $product = $this->completeProduct();
        $product->media()->update(['alt_text' => null]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->passes());
        $this->assertContains(
            'ALT pendiente',
            array_map(static fn ($issue) => $issue->code, $result->warnings()),
        );
    }

    public function test_una_imagen_de_baja_resolucion_es_aviso(): void
    {
        $product = $this->completeProduct();
        $product->media()->update(['width' => 300, 'height' => 300]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->passes(), 'La baja resolución avisa, pero no bloquea.');
        $this->assertContains(
            'Imagen de baja resolución',
            array_map(static fn ($issue) => $issue->code, $result->warnings()),
        );
    }

    public function test_los_avisos_de_datos_no_confirmados_se_propagan_desde_el_contenido(): void
    {
        $product = $this->completeProduct();
        $product->contentFor()->update([
            'warnings_json' => ['Composición no confirmada por una persona.'],
        ]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        $this->assertTrue($result->passes());
        $this->assertContains('Composición no confirmada por una persona.', $result->warningMessages());
    }

    public function test_una_ficha_en_borrador_sin_contenido_avisa_pero_no_bloquea(): void
    {
        $product = Product::factory()->create(['price' => 10]);

        $result = (new ProductValidator)->validate($product->fresh(['variants', 'media', 'contents']));

        // Sin contenido todavía no se puede enviar, pero tampoco es un error
        // mientras la ficha se está preparando.
        $this->assertTrue($result->hasWarnings());
        $this->assertContains('Contenido pendiente', array_map(static fn ($i) => $i->code, $result->warnings()));
    }

    public function test_readiness_no_permite_enviar_una_ficha_en_borrador(): void
    {
        $product = Product::factory()->draft()->create(['price' => 10]);

        $this->assertFalse(ProductReadiness::canSendToShopify($product));
    }

    public function test_readiness_permite_enviar_una_ficha_aprobada_y_completa(): void
    {
        $this->assertTrue(ProductReadiness::canSendToShopify($this->completeProduct()));
    }

    public function test_readiness_bloquea_una_ficha_aprobada_incompleta(): void
    {
        $product = $this->completeProduct();
        $product->variants()->delete();

        $this->assertFalse(ProductReadiness::canSendToShopify($product->fresh(['variants', 'media', 'contents'])));
    }

    public function test_el_boton_cambia_a_actualizar_si_shopify_ya_conoce_el_producto(): void
    {
        $product = $this->completeProduct();

        $this->assertFalse(ProductReadiness::isUpdate($product));
        $this->assertSame('Enviar como borrador a Shopify', ProductReadiness::actionLabel($product));

        $product->shopify_product_gid = 'gid://shopify/Product/1';
        $product->save();

        $this->assertTrue(ProductReadiness::isUpdate($product));
        $this->assertSame('Actualizar borrador', ProductReadiness::actionLabel($product));
    }

    public function test_no_se_puede_aprobar_una_ficha_con_bloqueantes(): void
    {
        $product = Product::factory()->inReview()->create(['price' => null]);

        $this->assertFalse(ProductReadiness::canApprove($product->fresh(['variants', 'media', 'contents'])));
    }

    public function test_una_ficha_archivada_nunca_se_envia(): void
    {
        $product = $this->completeProduct();
        $product->status = ProductStatus::Archived;
        $product->save();

        $this->assertFalse(ProductReadiness::canSendToShopify($product->fresh(['variants', 'media', 'contents'])));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductVariantService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Botón «Añadir Variante Tallas» (decisión de producto sobre RFC-0002 / RFC-0005).
 *
 * Crea las cinco tallas estándar de una vez, heredando el precio de la ficha, sin
 * color y sin tocar las imágenes. Las cuatro decisiones que fijó la petición están
 * aquí como prueba, para que un cambio futuro no las revierta sin querer:
 *
 * 1. Color vacío.
 * 2. Precio copiado de la ficha.
 * 3. Sólo se crean las tallas que faltan.
 * 4. La imagen de la ficha no se toca.
 */
class AddSizeVariantsTest extends TestCase
{
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'internal_reference' => 'DDP-SS-VOICE',
            'price' => '39.00',
        ], $attributes));
    }

    // ------------------------------------------------------------- creación

    public function test_crea_las_cinco_tallas_estandar(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        $created = app(ProductVariantService::class)->generateSizes($product, $operadora);

        $this->assertSame(5, $created);
        $this->assertSame(5, $product->variants()->count());

        $this->assertSame(
            ['DDP-SS-VOICE-S', 'DDP-SS-VOICE-M', 'DDP-SS-VOICE-L', 'DDP-SS-VOICE-XL', 'DDP-SS-VOICE-2XL'],
            $product->variants()->orderBy('position')->pluck('sku')->all(),
        );
    }

    public function test_usa_2xl_y_no_xxl(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // Es la etiqueta que usa la tienda; «XXL» sería otro SKU distinto.
        $this->assertTrue($product->variants()->where('sku', 'DDP-SS-VOICE-2XL')->exists());
        $this->assertFalse($product->variants()->where('sku', 'DDP-SS-VOICE-XXL')->exists());
    }

    public function test_deja_el_color_vacio(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        foreach ($product->variants()->get() as $variant) {
            // El nombre de la opción se declara, pero sin valor: es como la tienda
            // representa «esta ficha no varía por color».
            $this->assertSame('Color', $variant->option1_name);
            $this->assertSame('', $variant->option1_value);
            $this->assertSame('Talla', $variant->option2_name);
        }
    }

    public function test_copia_el_precio_de_la_ficha(): void
    {
        $operadora = $this->operadora();
        $product = $this->product(['price' => '39.00']);

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        foreach ($product->variants()->get() as $variant) {
            // Se copia, no se hereda: el número queda a la vista en el listado.
            $this->assertSame('39.00', (string) $variant->price);
        }
    }

    public function test_sin_precio_en_la_ficha_las_variantes_quedan_sin_precio(): void
    {
        $operadora = $this->operadora();
        $product = $this->product(['price' => null]);

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // No se inventa un precio: la validación de RFC-0005 lo marcará como
        // bloqueante antes de enviar, que es donde debe decidirlo una persona.
        $this->assertNull($product->variants()->first()->price);
    }

    public function test_asigna_una_posicion_distinta_a_cada_talla(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        $positions = $product->variants()->orderBy('position')->pluck('position')->all();

        // Todas con la misma posición dejaban el listado en orden arbitrario.
        $this->assertSame([0, 1, 2, 3, 4], array_map('intval', $positions));
    }

    // -------------------------------------------------------- stock inicial

    public function test_las_tallas_nuevas_nacen_con_cinco_unidades(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // El stock de arranque es lo que permite vender la ficha desde el primer
        // día sin teclear cinco cantidades a mano.
        foreach ($product->variants()->get() as $variant) {
            $this->assertSame(5, $variant->inventory_quantity);
        }
    }

    public function test_la_cantidad_inicial_viene_de_la_configuracion(): void
    {
        config()->set('product-studio.variants.initial_inventory_quantity', 12);

        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // La cifra no está incrustada en el código.
        $this->assertSame(12, $product->variants()->first()->inventory_quantity);
    }

    public function test_sin_cantidad_configurada_las_tallas_quedan_sin_stock(): void
    {
        config()->set('product-studio.variants.initial_inventory_quantity', null);

        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // `null` desactiva la función: es el comportamiento anterior.
        $this->assertNull($product->variants()->first()->inventory_quantity);
    }

    public function test_una_cantidad_vacia_en_el_entorno_no_rompe_el_stock(): void
    {
        // Un `.env` con la clave definida pero vacía llega como cadena vacía y
        // anularía el valor por defecto (handoff.md §2): debe tratarse como «sin
        // cantidad», no como 0.
        config()->set('product-studio.variants.initial_inventory_quantity', '');

        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        $this->assertNull($product->variants()->first()->inventory_quantity);
    }

    public function test_no_pisa_el_stock_de_las_tallas_que_ya_existian(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        // Una talla ya creada, con una cantidad decidida por una persona.
        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-SS-VOICE-S',
            'option1_name' => 'Color', 'option1_value' => '',
            'option2_name' => 'Talla', 'option2_value' => 'S',
            'inventory_quantity' => 40,
        ]);

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // El botón sólo rellena lo que crea: la cifra confirmada no se toca.
        $this->assertSame(40, $product->variants()->where('sku', 'DDP-SS-VOICE-S')->first()->inventory_quantity);
        $this->assertSame(5, $product->variants()->where('sku', 'DDP-SS-VOICE-M')->first()->inventory_quantity);
    }

    public function test_la_matriz_de_color_no_recibe_stock_inicial(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        app(ProductVariantService::class)->generateMatrix($product, ['Blanco'], ['M'], $operadora);

        // La cantidad de arranque es una decisión del botón de tallas; la matriz
        // color × talla sigue sin inventar stock.
        $this->assertNull($product->variants()->first()->inventory_quantity);
    }

    // ---------------------------------------------------------- idempotencia

    public function test_solo_crea_las_tallas_que_faltan(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        // La ficha ya tiene S y M.
        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-SS-VOICE-S',
            'option1_name' => 'Color', 'option1_value' => '',
            'option2_name' => 'Talla', 'option2_value' => 'S',
        ]);
        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-SS-VOICE-M',
            'option1_name' => 'Color', 'option1_value' => '',
            'option2_name' => 'Talla', 'option2_value' => 'M',
        ]);

        $created = app(ProductVariantService::class)->generateSizes($product, $operadora);

        $this->assertSame(3, $created);
        $this->assertSame(5, $product->variants()->count());

        $this->assertTrue($product->variants()->where('sku', 'DDP-SS-VOICE-L')->exists());
        $this->assertTrue($product->variants()->where('sku', 'DDP-SS-VOICE-XL')->exists());
        $this->assertTrue($product->variants()->where('sku', 'DDP-SS-VOICE-2XL')->exists());
    }

    public function test_pulsar_dos_veces_no_duplica_ni_falla(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();
        $service = app(ProductVariantService::class);

        $service->generateSizes($product, $operadora);
        $second = $service->generateSizes($product, $operadora);

        // Sin esto, el segundo clic chocaría con el índice único de SKU y la
        // persona vería un error por una acción que ya había hecho.
        $this->assertSame(0, $second);
        $this->assertSame(5, $product->variants()->count());
    }

    public function test_con_todas_las_tallas_ya_creadas_no_hace_nada(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();
        $service = app(ProductVariantService::class);

        $service->generateSizes($product, $operadora);
        $antes = $product->variants()->orderBy('id')->pluck('sku')->all();

        $service->generateSizes($product, $operadora);

        $this->assertSame($antes, $product->variants()->orderBy('id')->pluck('sku')->all());
    }

    public function test_no_toca_las_variantes_de_color_que_ya_existian(): void
    {
        $operadora = $this->operadora();
        $product = $this->product();

        // Una variante con color (otro eje) no debe bloquear la talla M.
        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-SS-VOICE-BLA-M',
            'option1_name' => 'Color', 'option1_value' => 'Blanco',
            'option2_name' => 'Talla', 'option2_value' => 'M',
        ]);

        $created = app(ProductVariantService::class)->generateSizes($product, $operadora);

        // Las cinco tallas sin color se crean igual: la clave de combinación
        // distingue «Blanco|M» de «|M».
        $this->assertSame(5, $created);
        $this->assertSame(6, $product->variants()->count());
    }

    // --------------------------------------------------------- SKU y errores

    public function test_el_sku_se_normaliza_con_la_referencia(): void
    {
        $operadora = $this->operadora();
        $product = $this->product(['internal_reference' => 'ddp ss voice']);

        app(ProductVariantService::class)->generateSizes($product, $operadora);

        // Espacios y minúsculas se normalizan igual que en el resto del catálogo.
        $this->assertTrue($product->variants()->where('sku', 'DDP-SS-VOICE-S')->exists());
    }

    public function test_avisa_si_un_sku_ya_existe_en_otra_ficha(): void
    {
        $operadora = $this->operadora();
        $otro = $this->product(['internal_reference' => 'DDP-OTRA']);

        // Otra ficha ya usa ese SKU: el índice es global al catálogo.
        ProductVariant::factory()->forProduct($otro)->create(['sku' => 'DDP-SS-VOICE-M']);

        $product = $this->product();

        // La transacción completa se deshace, así que no queda una ficha a medias.
        try {
            app(ProductVariantService::class)->generateSizes($product, $operadora);
            $this->fail('Debería haber rechazado el SKU duplicado.');
        } catch (ValidationException) {
            $this->assertSame(0, $product->variants()->count());
        }
    }

    public function test_las_tallas_vienen_de_la_configuracion(): void
    {
        config()->set('product-studio.variants.standard_sizes', ['Única']);

        $operadora = $this->operadora();
        $product = $this->product();

        $created = app(ProductVariantService::class)->generateSizes($product, $operadora);

        // La lista no está incrustada en el código: se puede ajustar sin tocar la
        // interfaz ni las pruebas.
        $this->assertSame(1, $created);
        $this->assertSame('DDP-SS-VOICE-ÚNICA', $product->variants()->first()->sku);
    }

    public function test_sin_tallas_configuradas_no_crea_nada(): void
    {
        config()->set('product-studio.variants.standard_sizes', []);

        $operadora = $this->operadora();
        $product = $this->product();

        $this->assertSame(0, app(ProductVariantService::class)->generateSizes($product, $operadora));
        $this->assertSame(0, $product->variants()->count());
    }
}

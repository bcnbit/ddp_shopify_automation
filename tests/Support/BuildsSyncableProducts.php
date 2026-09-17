<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Construye una ficha lista para sincronizar (RFC-0004).
 *
 * La sincronización sólo actúa sobre fichas sin bloqueantes, así que cada
 * prueba necesita una ficha completa: precio, variantes con SKU, foto principal
 * con ALT y contenido aprobado. Tenerlo en un único sitio evita que cada prueba
 * invente su propia versión de «ficha válida» y acaben discrepando.
 */
trait BuildsSyncableProducts
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function syncableProduct(array $attributes = [], ?User $owner = null): Product
    {
        // El disco de medios es privado y las pruebas no deben escribir en el
        // real: se aísla aquí para que cualquier prueba que construya una ficha
        // sincronizable tenga ya un disco limpio y sus archivos disponibles.
        Storage::fake((string) config('media.disks.originals', 'media'));
        $product = Product::factory()
            ->withConfirmedFacts()
            ->approved()
            ->create(array_merge([
                'created_by' => $owner?->getKey() ?? User::factory(),
            ], $attributes));

        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-1001-ROJO-M',
            'option1_name' => 'Color',
            'option1_value' => 'Rojo',
            'option2_name' => 'Talla',
            'option2_value' => 'M',
            'position' => 0,
        ]);

        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DDP-1001-ROJO-L',
            'option1_name' => 'Color',
            'option1_value' => 'Rojo',
            'option2_name' => 'Talla',
            'option2_value' => 'L',
            'position' => 1,
        ]);

        ProductMedia::factory()->forProduct($product)->primary()->withAltText()->create();

        $product->refresh();

        // El gateway necesita los bytes reales para subirlos: se materializan
        // los originales en el disco aislado.
        $this->materializeMedia($product);

        ProductContent::factory()->forProduct($product)->create([
            'locale' => Locale::Es,
            'version' => 1,
            'title' => 'Camiseta Marina de algodón',
            'handle' => 'camiseta-marina',
            'html_description' => '<p>Camiseta de corte regular para el día a día.</p>',
            'seo_title' => 'Camiseta Marina de algodón | Dies de Platja',
            'seo_description' => 'Camiseta Marina de algodón peinado, corte regular y suave al tacto. Una prenda sencilla para el día a día junto al mar.',
            'tags_json' => ['camiseta', 'algodón', 'marina', 'verano', 'dies de platja'],
        ]);
        return $product->refresh();
    }

    /**
     * Configura el conector con valores de prueba. No es un secreto real.
     */
    protected function configureShopify(): void
    {
        config()->set('product-studio.shopify.shop_domain', 'dies-de-platja.myshopify.com');
        config()->set('product-studio.shopify.access_token', 'shpat_token-de-prueba');
        config()->set('product-studio.shopify.api_version', '2026-07');
        // Sin reintentos: se comprueba la traducción del error, no el backoff.
        config()->set('product-studio.shopify.retry_times', 0);
        config()->set('product-studio.shopify.media_poll_sleep_ms', 0);
        config()->set('product-studio.shopify.media_poll_attempts', 1);
    }

    /**
     * Doble de HTTP que responde según la operación GraphQL solicitada.
     *
     * Se enruta por el contenido de la consulta en lugar de por una secuencia
     * posicional: así una prueba no se rompe porque el conector haga una
     * comprobación de idempotencia más o menos, que es un detalle interno y no
     * parte del contrato que se está verificando.
     *
     * @param  array<string, mixed>  $overrides  clave: nombre de operación
     */
    protected function fakeShopify(array $overrides = []): void
    {
        $responses = array_merge([
            'FindProductByStudioId' => $this->emptyProductSearch(),
            'StagedUploadsCreate' => $this->stagedUploadResponse(),
            'FileCreate' => $this->fileCreateResponse(),
            'FilesStatus' => $this->filesStatusResponse(['gid://shopify/MediaImage/999']),
            'ProductSet' => $this->productSetResponse(),
        ], $overrides);

        // Ninguna prueba debe salir a la red: una petición sin doble es un fallo
        // de la prueba, no una llamada real a Shopify.
        Http::preventStrayRequests();

        Http::fake([
            // La subida de los bytes va al almacenamiento temporal de Shopify,
            // no a la Admin API, y no es una consulta GraphQL.
            'https://shopify-staged-uploads.storage.googleapis.com/*' => Http::response('', 201),
            '*graphql.json' => function (Request $request) use ($responses) {
                $query = (string) ($request->data()['query'] ?? '');

                foreach ($responses as $operation => $response) {
                    if (str_contains($query, $operation)) {
                        return Http::response($response);
                    }
                }

                return Http::response($this->emptyProductSearch());
            },
        ]);
    }
    /**
     * Variables GraphQL de una petición, como array.
     *
     * `Request::data()` devuelve `stdClass` en los objetos anidados porque el
     * cliente serializa `variables` como objeto JSON (que es lo que espera
     * GraphQL). Para inspeccionarlas en una prueba hay que decodificar el cuerpo.
     *
     * @return array<string, mixed>
     */
    protected function graphQlVariables(Request $request): array
    {
        $body = json_decode($request->body(), true);

        return is_array($body['variables'] ?? null) ? $body['variables'] : [];
    }

    /**
     * Deja los bytes reales de cada medio en el disco aislado.
     *
     * El gateway lee el original para subirlo: sin esto, la sincronización
     * fallaría con «no se encuentra el archivo», que no es lo que se está
     * probando.
     */
    protected function materializeMedia(Product $product): void
    {
        foreach ($product->media as $media) {
            Storage::disk($media->disk)->put($media->path, 'contenido-de-imagen-de-prueba');
        }
    }

    /**
     * Respuesta de `products(query:)` sin coincidencias.
     *
     * @return array<string, mixed>
     */
    protected function emptyProductSearch(): array
    {
        return ['data' => ['products' => ['nodes' => []]]];
    }

    /**
     * Respuesta correcta de `productSet`.
     *
     * @param  list<array{sku: string, id: string}>  $variants
     * @return array<string, mixed>
     */
    protected function productSetResponse(string $gid = 'gid://shopify/Product/1234567890', string $handle = 'camiseta-marina', array $variants = []): array
    {
        if ($variants === []) {
            $variants = [
                ['sku' => 'DDP-1001-ROJO-M', 'id' => 'gid://shopify/ProductVariant/111'],
                ['sku' => 'DDP-1001-ROJO-L', 'id' => 'gid://shopify/ProductVariant/222'],
            ];
        }

        return [
            'data' => [
                'productSet' => [
                    'product' => [
                        'id' => $gid,
                        'handle' => $handle,
                        'status' => 'DRAFT',
                        'variants' => ['nodes' => $variants],
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * Respuesta correcta de `stagedUploadsCreate`.
     *
     * @return array<string, mixed>
     */
    protected function stagedUploadResponse(string $resourceUrl = 'https://shopify-staged-uploads.storage.googleapis.com/tmp/abc'): array
    {
        return [
            'data' => [
                'stagedUploadsCreate' => [
                    'stagedTargets' => [[
                        'url' => 'https://shopify-staged-uploads.storage.googleapis.com/tmp/abc',
                        'resourceUrl' => $resourceUrl,
                        'parameters' => [
                            ['name' => 'key', 'value' => 'tmp/abc'],
                            ['name' => 'policy', 'value' => 'policy-value'],
                        ],
                    ]],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * Respuesta correcta de `fileCreate`.
     *
     * @return array<string, mixed>
     */
    protected function fileCreateResponse(string $gid = 'gid://shopify/MediaImage/999'): array
    {
        return [
            'data' => [
                'fileCreate' => [
                    'files' => [[
                        'id' => $gid,
                        'fileStatus' => 'READY',
                        'image' => ['width' => 2000, 'height' => 2000],
                    ]],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * Respuesta de `nodes(ids:)` con los ficheros ya procesados.
     *
     * @param  list<string>  $gids
     * @return array<string, mixed>
     */
    protected function filesStatusResponse(array $gids): array
    {
        return [
            'data' => [
                'nodes' => array_map(
                    static fn (string $gid): array => ['id' => $gid, 'fileStatus' => 'READY'],
                    $gids,
                ),
            ],
        ];
    }
}
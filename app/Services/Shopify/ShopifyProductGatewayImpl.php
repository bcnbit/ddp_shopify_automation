<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\DataObjects\Shopify\ShopifyProductPayload;
use App\DataObjects\Shopify\ShopifySyncResult;
use App\DataObjects\Shopify\ShopifyUploadedFile;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use Illuminate\Support\Facades\Log;

/**
 * Conector con Shopify sobre Admin GraphQL (RFC-0004).
 *
 * Traduce el dominio de la aplicación a las mutaciones vigentes de Shopify. La
 * ficha local es la fuente de verdad y Shopify recibe siempre una copia en
 * estado DRAFT: el estado no es un parámetro que la interfaz pueda cambiar.
 *
 * Idempotencia, en tres capas:
 *
 * 1. Si ya se conoce el GID, se actualiza ese producto (`identifier.id`).
 * 2. Si no, se busca por metafield privado y, como comprobación secundaria, por
 *    handle; así un doble clic no crea un segundo producto.
 * 3. Un medio cuyo `sha256` ya tiene GID no se vuelve a subir.
 */
class ShopifyProductGatewayImpl implements ShopifyProductGateway
{
    public function __construct(
        private readonly ShopifyGraphQlClient $client,
        private readonly ShopifyFileUploader $uploader,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @throws ShopifyRequestFailed
     */
    public function createOrUpdateDraft(ShopifyProductPayload $payload, ?string $productGid = null): ShopifySyncResult
    {
        // Se comprueba lo primero para fallar con un mensaje útil en lugar de
        // llegar a un error de lectura de archivo o de red.
        if (! $this->isConfigured()) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no está configurado. Avisa al administrador técnico.',
                'not_configured',
            );
        }

        // Principio no negociable (RFC-0000): nunca se envía algo publicable.
        if (! $payload->isDraft()) {
            throw ShopifyRequestFailed::permanent(
                'Por seguridad, sólo se envían productos como borrador.',
                'not_draft',
            );
        }

        $productGid ??= $this->findProductGid($payload);

        $media = $this->prepareMedia($payload);
        $input = $this->buildInput($payload, $media['byMedia']);
        $identifier = $this->buildIdentifier($productGid, $payload);

        $data = $this->client->query('ProductSet', ShopifyOperations::PRODUCT_SET, [
            'input' => $input,
            'identifier' => $identifier,
            'synchronous' => true,
        ]);

        $result = $data['productSet'] ?? null;

        if (! is_array($result)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha confirmado la operación sobre el producto.',
                'product_set_missing',
            );
        }

        $this->client->throwIfUserErrors('crear o actualizar el producto', $this->userErrors($result));

        $product = $result['product'] ?? null;

        if (! is_array($product) || ! is_string($product['id'] ?? null)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha devuelto el producto creado.',
                'product_set_invalid',
            );
        }

        return new ShopifySyncResult(
            productGid: $product['id'],
            handle: is_string($product['handle'] ?? null) ? $product['handle'] : null,
            variantGids: $this->variantGids($product),
            mediaGids: $media['byChecksum'],
        );
    }

    /**
     * @throws ShopifyRequestFailed
     */
    public function findByStudioId(string $studioId): ?ShopifySyncResult
    {
        if (! $this->client->isConfigured()) {
            return null;
        }

        $namespace = (string) config('product-studio.shopify.metafield_namespace', 'product_studio');

        // El filtro de servidor sólo es fiable si el metafield está declarado
        // como `adminFilterable`; si no lo está, Shopify ignora el filtro y
        // devuelve productos cualesquiera. Por eso se pide el metafield en la
        // propia consulta y se compara el valor aquí: un falso positivo haría
        // que una ficha sobrescribiese el borrador de otra.
        $data = $this->client->query('FindProductByStudioId', ShopifyOperations::FIND_PRODUCT_BY_STUDIO_ID, [
            'query' => sprintf('metafield:%s.product_studio_id:"%s"', $namespace, $studioId),
            'namespace' => $namespace,
        ]);

        foreach ((array) ($data['products']['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $value = $node['metafield']['value'] ?? null;

            if (! is_string($value) || $value !== $studioId) {
                continue;
            }

            return new ShopifySyncResult(
                productGid: $node['id'],
                handle: is_string($node['handle'] ?? null) ? $node['handle'] : null,
            );
        }

        return null;
    }

    /**
     * Resuelve el GID del producto que corresponde a esta ficha, si existe.
     */
    private function findProductGid(ShopifyProductPayload $payload): ?string
    {
        $found = $this->findByStudioId($payload->studioId);

        // Comprobación secundaria por handle: cubre el caso en que el metafield
        // todavía no existe porque la creación anterior no llegó a completarse,
        // pero el producto sí quedó creado con ese handle.
        $found ??= $payload->handle === '' ? null : $this->findByHandle($payload->handle);

        if ($found !== null) {
            Log::info('Sincronización con Shopify: producto ya existente reutilizado.', [
                'product_studio_id' => $payload->studioId,
            ]);

            return $found->productGid;
        }

        return null;
    }

    /**
     * Comprobación secundaria por handle, verificando el valor devuelto.
     */
    private function findByHandle(string $handle): ?ShopifySyncResult
    {
        if ($handle === '') {
            return null;
        }

        $namespace = (string) config('product-studio.shopify.metafield_namespace', 'product_studio');

        $data = $this->client->query('FindProductByStudioId', ShopifyOperations::FIND_PRODUCT_BY_STUDIO_ID, [
            'query' => 'handle:"'.$handle.'"',
            'namespace' => $namespace,
        ]);

        foreach ((array) ($data['products']['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $node['id'] ?? null;

            if (! is_string($id) || ($node['handle'] ?? null) !== $handle) {
                continue;
            }

            return new ShopifySyncResult(productGid: $id, handle: $handle);
        }

        return null;
    }
    /**
     * Sube los medios que aún no tienen GID y espera a que estén listos.
     *
     * Devuelve dos vistas del mismo trabajo: por `mediaId`, que es lo que
     * necesita la construcción de `files`, y por `sha256`, que es la clave con
     * la que se persiste el GID en local para no volver a subir la imagen.
     *
     * @return array{byMedia: array<int, ShopifyUploadedFile>, byChecksum: array<string, string>}
     */
    private function prepareMedia(ShopifyProductPayload $payload): array
    {
        $uploaded = [];
        $byChecksum = [];
        $pending = [];

        foreach ($payload->media as $item) {
            $mediaId = (int) ($item['mediaId'] ?? 0);
            $checksum = (string) ($item['sha256'] ?? '');
            $existingGid = $item['shopifyMediaGid'] ?? null;

            // Nunca se repite una carga con el mismo sha256 para el mismo
            // producto: si ya tiene GID, se reutiliza (RFC-0004).
            if (is_string($existingGid) && $existingGid !== '') {
                $uploaded[$mediaId] = new ShopifyUploadedFile(
                    gid: $existingGid,
                    filename: (string) ($item['originalFilename'] ?? 'imagen'),
                );

                if ($checksum !== '') {
                    $byChecksum[$checksum] = $existingGid;
                }

                continue;
            }

            $file = $this->uploader->upload(
                disk: (string) $item['disk'],
                path: (string) $item['path'],
                filename: (string) $item['originalFilename'],
                mimeType: (string) $item['mimeType'],
            );

            $uploaded[$mediaId] = $file;
            $pending[] = $file->gid;

            if ($checksum !== '') {
                $byChecksum[$checksum] = $file->gid;
            }
        }

        if ($pending !== []) {
            $this->uploader->awaitReady($pending);
        }

        return ['byMedia' => $uploaded, 'byChecksum' => $byChecksum];
    }

    /**
     * Construye el `ProductSetInput`.
     *
     * `productSet` **sustituye** los campos de lista, no los fusiona: hay que
     * enviar siempre todas las variantes y todos los medios, o Shopify borraría
     * los que falten.
     *
     * @param  array<int, ShopifyUploadedFile>  $media
     * @return array<string, mixed>
     */
    private function buildInput(ShopifyProductPayload $payload, array $media): array
    {
        $input = [
            'title' => $payload->title,
            // El estado se fija aquí, no se recibe de fuera: es la garantía de
            // que ninguna ruta puede publicar por accidente (RFC-0000).
            'status' => 'DRAFT',
        ];

        if ($payload->handle !== '') {
            $input['handle'] = $payload->handle;
        }

        if ($payload->descriptionHtml !== '') {
            $input['descriptionHtml'] = $payload->descriptionHtml;
        }

        if ($payload->vendor !== '') {
            $input['vendor'] = $payload->vendor;
        }

        if ($payload->productType !== '') {
            $input['productType'] = $payload->productType;
        }

        if ($payload->tags !== []) {
            $input['tags'] = array_values($payload->tags);
        }

        if ($payload->categoryTaxonomyId !== null && $payload->categoryTaxonomyId !== '') {
            $input['category'] = $payload->categoryTaxonomyId;
        }

        if ($payload->metafields !== []) {
            $input['metafields'] = array_values($payload->metafields);
        }

        $seo = $this->buildSeo($payload);

        if ($seo !== []) {
            $input['seo'] = $seo;
        }

        $options = $this->buildOptions($payload->variants);

        if ($options !== []) {
            $input['productOptions'] = $options;
        }

        $variants = $this->buildVariants($payload->variants);

        if ($variants !== []) {
            $input['variants'] = $variants;
        }

        $files = $this->buildFiles($payload, $media);

        if ($files !== []) {
            $input['files'] = $files;
        }

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private function buildSeo(ShopifyProductPayload $payload): array
    {
        $seo = [];

        if (filled($payload->seoTitle)) {
            $seo['title'] = (string) $payload->seoTitle;
        }

        if (filled($payload->seoDescription)) {
            $seo['description'] = (string) $payload->seoDescription;
        }

        return $seo;
    }

    /**
     * Declara las opciones (Color, Talla) a partir de las variantes locales.
     *
     * Sin declarar las opciones, Shopify no sabe interpretar `optionValues` de
     * cada variante y las crea sueltas.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function buildOptions(array $variants): array
    {
        $names = [];

        foreach ($variants as $variant) {
            foreach ([1, 2] as $index) {
                $name = trim((string) ($variant["option{$index}Name"] ?? ''));
                $value = trim((string) ($variant["option{$index}Value"] ?? ''));

                if ($name === '' || $value === '') {
                    continue;
                }

                $names[$name] ??= [];
                $names[$name][] = $value;
            }
        }

        $options = [];

        foreach ($names as $name => $values) {
            // Se deduplica por el texto, no por el array completo: `array_unique`
            // compara cadenas y convertir cada valor en un array provocaría un
            // aviso de conversión.
            $unique = array_values(array_unique($values));

            $options[] = [
                'name' => $name,
                'position' => count($options) + 1,
                'values' => array_map(
                    static fn (string $value): array => ['name' => $value],
                    $unique,
                ),
            ];
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function buildVariants(array $variants): array
    {
        $result = [];

        foreach ($variants as $variant) {
            $entry = [];

            $optionValues = $this->optionValues($variant);

            if ($optionValues !== []) {
                $entry['optionValues'] = $optionValues;
            }

            if (filled($variant['sku'] ?? null)) {
                $entry['sku'] = (string) $variant['sku'];
            }

            if (filled($variant['barcode'] ?? null)) {
                $entry['barcode'] = (string) $variant['barcode'];
            }

            if (filled($variant['price'] ?? null)) {
                $entry['price'] = (string) $variant['price'];
            }

            if (filled($variant['compareAtPrice'] ?? null)) {
                $entry['compareAtPrice'] = (string) $variant['compareAtPrice'];
            }

            if (filled($variant['inventoryPolicy'] ?? null)) {
                $entry['inventoryPolicy'] = (string) $variant['inventoryPolicy'];
            }

            if (filled($variant['position'] ?? null)) {
                $entry['position'] = (int) $variant['position'];
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return list<array{optionName: string, name: string}>
     */
    private function optionValues(array $variant): array
    {
        $values = [];

        foreach ([1, 2] as $index) {
            $name = trim((string) ($variant["option{$index}Name"] ?? ''));
            $value = trim((string) ($variant["option{$index}Value"] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $values[] = ['optionName' => $name, 'name' => $value];
        }

        return $values;
    }

    /**
     * Asocia los medios ya subidos al producto, conservando orden y ALT.
     *
     * @param  array<int, ShopifyUploadedFile>  $media
     * @return list<array<string, mixed>>
     */
    private function buildFiles(ShopifyProductPayload $payload, array $media): array
    {
        $files = [];

        foreach ($payload->media as $item) {
            $mediaId = (int) ($item['mediaId'] ?? 0);
            $file = $media[$mediaId] ?? null;

            if ($file === null) {
                continue;
            }

            $entry = ['originalSource' => $file->gid];

            if (filled($item['altText'] ?? null)) {
                $entry['alt'] = (string) $item['altText'];
            }

            $files[] = $entry;
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function buildIdentifier(?string $productGid, ShopifyProductPayload $payload): array
    {
        if ($productGid !== null && $productGid !== '') {
            return ['id' => $productGid];
        }

        // Sin GID conocido se usa el handle, que es estable y único en Shopify.
        // Shopify lo normaliza y devuelve el producto existente si ya lo tenía.
        return $payload->handle !== '' ? ['handle' => $payload->handle] : [];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, string> por `sku`
     */
    private function variantGids(array $product): array
    {
        $gids = [];

        foreach ((array) ($product['variants']['nodes'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $id = $variant['id'] ?? null;
            $sku = $variant['sku'] ?? null;

            if (is_string($id) && is_string($sku) && $sku !== '') {
                $gids[$sku] = $id;
            }
        }

        return $gids;
    }


    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function userErrors(array $payload): array
    {
        $errors = $payload['userErrors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
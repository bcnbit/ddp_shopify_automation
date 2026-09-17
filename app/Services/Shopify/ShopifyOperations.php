<?php

declare(strict_types=1);

namespace App\Services\Shopify;

/**
 * Documentos GraphQL de la Admin API (RFC-0004).
 *
 * Todas las mutaciones viven aquí y en ningún otro sitio. El RFC pide
 * expresamente encapsular las operaciones «para que un cambio de API no afecte
 * al dominio»: cuando Shopify cambie un campo, se cambia este archivo y sus
 * pruebas, no los servicios ni la interfaz.
 *
 * Versión de API verificada: 2026-07.
 */
final class ShopifyOperations
{
    /**
     * Busca el producto por el metafield privado `product_studio_id`.
     *
     * Es la comprobación primaria de idempotencia: si una ejecución anterior
     * creó el producto pero se perdió la respuesta, esto evita duplicarlo.
     */
    public const FIND_PRODUCT_BY_STUDIO_ID = <<<'GQL'
    query FindProductByStudioId($query: String!, $namespace: String!) {
      products(first: 5, query: $query) {
        nodes {
          id
          handle
          status
          metafield(namespace: $namespace, key: "product_studio_id") {
            value
          }
        }
      }
    }
    GQL;

    /**
     * Crea o actualiza el producto completo en una sola operación.
     *
     * `productSet` es la mutación vigente recomendada: sustituye al conjunto
     * `productCreate` + `productUpdate` + `productVariantsBulkCreate`, y aplica
     * semántica de *upsert* sobre opciones, variantes y ficheros. Se usa con
     * `synchronous: true` para que los medios queden procesados y sus GID sean
     * utilizables al terminar la llamada.
     *
     * El estado viaja en `$input.status` y el gateway lo fija siempre a DRAFT.
     *
     * `$identifier` es lo que hace la operación idempotente sin una consulta
     * previa: `{id: ...}` actualiza el producto ya conocido y `{handle: ...}`
     * resuelve el caso en que se perdió el GID pero el handle ya existe. Si no
     * se envía, la mutación crea un producto nuevo.
     */
    public const PRODUCT_SET = <<<'GQL'
    mutation ProductSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers, $synchronous: Boolean!) {
      productSet(input: $input, identifier: $identifier, synchronous: $synchronous) {
        product {
          id
          handle
          status
          variants(first: 100) {
            nodes {
              id
              sku
            }
          }
        }
        userErrors {
          field
          message
          code
        }
      }
    }
    GQL;

    /**
     * Primer paso de la subida de un medio: reserva una URL temporal.
     *
     * Los originales viven en un disco privado, así que Shopify no puede
     * descargarlos por URL: hay que subirlos en dos pasos.
     */
    public const STAGED_UPLOADS_CREATE = <<<'GQL'
    mutation StagedUploadsCreate($input: [StagedUploadInput!]!) {
      stagedUploadsCreate(input: $input) {
        stagedTargets {
          url
          resourceUrl
          parameters {
            name
            value
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GQL;

    /**
     * Segundo paso: convierte el archivo subido en un recurso de la tienda.
     *
     * El GID devuelto es el que después se asocia al producto mediante el campo
     * `files` de `productSet`. Guardarlo en local es lo que permite reanudar una
     * sincronización sin volver a subir la misma imagen.
     */
    public const FILE_CREATE = <<<'GQL'
    mutation FileCreate($files: [FileCreateInput!]!) {
      fileCreate(files: $files) {
        files {
          id
          fileStatus
          ... on MediaImage {
            image {
              width
              height
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GQL;

    /**
     * Consulta el estado de procesado de unos ficheros concretos.
     *
     * Shopify procesa los medios de forma asíncrona: si `fileCreate` los deja en
     * `UPLOADED`, hay que esperar a `READY` antes de poder asociarlos.
     */
    public const FILES_STATUS = <<<'GQL'
    query FilesStatus($ids: [ID!]!) {
      nodes(ids: $ids) {
        id
        ... on File {
          fileStatus
        }
      }
    }
    GQL;

    /** No instanciable: sólo agrupa documentos. */
    private function __construct() {}
}

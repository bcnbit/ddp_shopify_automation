<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Enums\ProductStatus;
use App\Models\Product;

/**
 * Responde a las preguntas de aprobación de RFC-0002.
 *
 * Centraliza las reglas para que el botón, la validación y la interfaz no
 * puedan discrepar: si el botón se oculta pero la acción sigue permitida, la
 * regla está mal aplicada.
 */
final class ProductReadiness
{
    /**
     * Una ficha puede enviarse como borrador si no tiene bloqueantes y está en
     * un estado desde el que tiene sentido enviarla.
     */
    public static function canSendToShopify(Product $product): bool
    {
        if ($product->status->isTerminal()) {
            return false;
        }

        if (! in_array($product->status, [
            ProductStatus::Approved,
            ProductStatus::ShopifyDraft,
            ProductStatus::SyncFailed,
            ProductStatus::ValidationFailed,
        ], true)) {
            return false;
        }

        return self::validation($product)->passes();
    }

    /**
     * Si Shopify ya conoce el producto, el botón pasa a «Actualizar borrador»
     * para que no se cree un producto nuevo (RFC-0002 / RFC-0004).
     */
    public static function isUpdate(Product $product): bool
    {
        return $product->isSyncedWithShopify();
    }

    public static function actionLabel(Product $product): string
    {
        return self::isUpdate($product) ? 'Actualizar borrador' : 'Enviar como borrador a Shopify';
    }

    public static function validation(Product $product): ValidationResult
    {
        return (new ProductValidator)->validate($product);
    }

    /**
     * ¿Se puede aprobar la ficha? Requiere contenido y datos suficientes para
     * que un responsable dé el visto bueno sin sorpresas.
     */
    public static function canApprove(Product $product): bool
    {
        if (! in_array($product->status, [ProductStatus::Review, ProductStatus::ValidationFailed], true)) {
            return false;
        }

        return self::validation($product)->passes();
    }
}

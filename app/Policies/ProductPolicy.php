<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Autorización de fichas (RFC-0001).
 *
 * La operadora sólo ve y edita sus propias fichas; el responsable de catálogo y
 * el administrador ven todo. La publicación exige permiso propio (RFC-0000,
 * criterio de aceptación: "Operadora no puede configurar conexiones ni publicar").
 */
class ProductPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ProductsView);
    }

    public function view(User $user, Product $product): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $this->canSeeAll($user) || $this->owns($user, $product);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ProductsCreate);
    }

    public function update(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::ProductsUpdate)) {
            return false;
        }

        if ($product->status->isTerminal()) {
            return false;
        }

        return $this->canSeeAll($user) || $this->owns($user, $product);
    }

    /**
     * Eliminar la ficha de la aplicación.
     *
     * **Sí se permite en fichas ya enviadas a Shopify**, y es deliberado: borrar
     * aquí sólo desvincula la copia local y **no toca la tienda** (ver
     * `ProductService::delete`). La regla anterior —«una ficha sincronizada no se
     * borra localmente, primero se archiva»— existía para no perder el vínculo con
     * Shopify; cuando lo que se pide es precisamente romperlo, esa protección
     * sobra. Archivar sigue siendo la vía para conservar el vínculo.
     */
    public function delete(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::ProductsDelete)) {
            return false;
        }

        return $this->canSeeAll($user) || $this->owns($user, $product);
    }

    /**
     * Aprobar habilita el envío como borrador, no la publicación.
     */
    public function approve(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::ProductsApprove)) {
            return false;
        }

        if ($product->status->isTerminal()) {
            return false;
        }

        return in_array($product->status, [ProductStatus::Review, ProductStatus::ValidationFailed], true);
    }

    public function sync(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::ProductsSync)) {
            return false;
        }

        if ($product->status->isTerminal()) {
            return false;
        }

        return $this->canSeeAll($user) || $this->owns($user, $product);
    }

    /**
     * Publicar queda expresamente fuera del MVP y exige permiso de responsable.
     */
    public function publish(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::ProductsPublish)) {
            return false;
        }

        return $product->status === ProductStatus::ShopifyDraft;
    }

    public function archive(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::ProductsDelete) && ! $product->status->isTerminal();
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::ProductsDelete);
    }

    public function viewAudit(User $user, Product $product): bool
    {
        if (! $this->allows($user, Permission::AuditView)) {
            return false;
        }

        return $this->canSeeAll($user) || $this->owns($user, $product);
    }

    public function retrySync(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::SyncRetry) && ! $product->status->isTerminal();
    }

    private function canSeeAll(User $user): bool
    {
        return $this->allows($user, Permission::ProductsViewAll);
    }

    private function owns(User $user, Product $product): bool
    {
        return $product->created_by !== null && $user->getKey() === $product->created_by;
    }
}

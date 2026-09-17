<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ProductMediaPolicy
{
    use ChecksPermissions;

    public function view(User $user, ProductMedia $media): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $user->can('view', $media->product);
    }

    /**
     * Filament comprueba esta habilidad sin instancia cuando decide si mostrar
     * el botón «Añadir imagen». En ese caso sólo se valida el permiso; el
     * acceso a la ficha concreta lo limita la consulta del RelationManager.
     */
    public function create(User $user, ?Product $product = null): bool
    {
        if (! $this->allows($user, Permission::MediaUpload)) {
            return false;
        }

        return $product === null || $user->can('update', $product);
    }

    public function update(User $user, ProductMedia $media): bool
    {
        if (! $this->allows($user, Permission::MediaUpload)) {
            return false;
        }

        return $user->can('update', $media->product);
    }

    public function delete(User $user, ProductMedia $media): bool
    {
        if (! $this->allows($user, Permission::MediaDelete)) {
            return false;
        }

        // Una imagen ya enviada a Shopify no se elimina localmente sin resincronizar.
        if ($media->shopify_media_gid !== null) {
            return false;
        }

        return $user->can('update', $media->product);
    }
}

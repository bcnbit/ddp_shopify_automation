<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ProductVariantPolicy
{
    use ChecksPermissions;

    public function view(User $user, ProductVariant $variant): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $user->can('view', $variant->product);
    }

    /**
     * Igual que en los medios, Filament evalúa esta habilidad sin instancia
     * para decidir si muestra el botón de alta. Sin ficha sólo se comprueba el
     * permiso; con ficha se comprueba además el acceso a esa ficha.
     */
    public function create(User $user, ?Product $product = null): bool
    {
        if (! $this->allows($user, Permission::ProductsUpdate)) {
            return false;
        }

        return $product === null || $user->can('update', $product);
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->allows($user, Permission::ProductsUpdate) && $user->can('update', $variant->product);
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        if (! $this->allows($user, Permission::ProductsUpdate)) {
            return false;
        }

        // La última variante vendible no se elimina sin sustituirla (RFC-0002).
        if ($variant->product->variants()->count() <= 1) {
            return false;
        }

        return $user->can('update', $variant->product);
    }
}

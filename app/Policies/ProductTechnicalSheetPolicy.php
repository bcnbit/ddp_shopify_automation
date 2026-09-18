<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProductTechnicalSheet;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Autorización de las copias congeladas (RFC-0008).
 *
 * Una copia no se gestiona por sí sola: se escribe como efecto de guardar la
 * ficha, y quien puede editar la ficha es quien puede cambiar su ficha técnica.
 * Por eso la Policy delega en la de la ficha, igual que hacen las de variantes,
 * medios y contenido.
 */
class ProductTechnicalSheetPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll);
    }

    public function view(User $user, ProductTechnicalSheet $sheet): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $user->can('view', $sheet->product);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;

/**
 * Comprobación común de permisos para las Policies.
 *
 * Un usuario inactivo nunca autoriza, ni siquiera con el permiso asignado.
 */
trait ChecksPermissions
{
    protected function allows(User $user, Permission $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermissionTo($permission->value);
    }

    protected function allowsAny(User $user, Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }
}

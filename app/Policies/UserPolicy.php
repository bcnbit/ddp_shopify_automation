<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class UserPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::UsersManage);
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $this->allows($user, Permission::UsersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::UsersManage);
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, Permission::UsersManage);
    }

    public function delete(User $user, User $model): bool
    {
        // Un administrador no puede eliminarse a sí mismo y evitar quedarse sin acceso.
        if ($user->is($model)) {
            return false;
        }

        // No se permite dejar el sistema sin ningún administrador técnico.
        if ($model->hasRole(Role::AdminTecnico->value)
            && User::role(Role::AdminTecnico->value)->count() <= 1) {
            return false;
        }

        return $this->allows($user, Permission::UsersManage);
    }

    public function manageRoles(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        if ($model->hasRole(Role::AdminTecnico->value)
            && User::role(Role::AdminTecnico->value)->count() <= 1) {
            return false;
        }

        return $this->allows($user, Permission::UsersManage);
    }
}

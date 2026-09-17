<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles y permisos del sistema (RFC-0000).
 *
 * Idempotente: se puede ejecutar tantas veces como haga falta. Los permisos
 * existentes se re-sincronizan para reflejar cambios de `Permission::byRole()`.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        $map = Permission::byRole();

        foreach (Role::cases() as $role) {
            $roleModel = RoleModel::findOrCreate($role->value, 'web');
            $roleModel->syncPermissions($map[$role->value] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

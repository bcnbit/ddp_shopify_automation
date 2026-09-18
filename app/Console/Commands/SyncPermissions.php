<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Comprueba que los permisos del código existen en la base de datos (RFC-0008).
 *
 * `Permission::byRole()` es la única fuente de verdad, pero es código: añadir un
 * permiso al enum no llega a la base de datos hasta sembrar. Entre esos dos
 * momentos la autorización no encuentra el permiso y, como Filament consulta las
 * Policies al pintar la navegación, el panel entero fallaba para todo el mundo.
 *
 * Este comando convierte ese desfase en algo visible y reparable en un paso:
 *
 *     php artisan studio:permissions           # sólo informa
 *     php artisan studio:permissions --sync    # aplica el reparto del enum
 *
 * `--sync` ejecuta el seeder real, no una copia de su lógica: el reparto vive en
 * un único sitio y este comando no puede divergir de él.
 */
class SyncPermissions extends Command
{
    protected $signature = 'studio:permissions {--sync : Aplica el reparto de Permission::byRole() y corrige el desfase}';

    protected $description = 'Comprueba que los roles y permisos del código existen en la base de datos';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $expected = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        $existing = PermissionModel::query()->pluck('name')->all();

        $missing = array_values(array_diff($expected, $existing));
        $unknown = array_values(array_diff($existing, $expected));

        $this->newLine();
        $this->line('  Permisos en el código : '.count($expected));
        $this->line('  Permisos en la base   : '.count($existing));

        // Los permisos pueden existir todos y el reparto estar vacío o a medias:
        // comparar sólo nombres daría por bueno un estado en el que nadie tiene
        // permisos, que es justo lo que hay que detectar.
        $driftedRoles = [];

        foreach (Role::cases() as $role) {
            $roleModel = RoleModel::query()->where('name', $role->value)->first();

            if ($roleModel === null) {
                $this->line("  Rol {$role->value}: AUSENTE");
                $driftedRoles[] = $role->value;

                continue;
            }

            $expectedForRole = Permission::byRole()[$role->value] ?? [];
            $actualForRole = $roleModel->permissions()->pluck('name')->all();

            $diff = array_merge(
                array_diff($expectedForRole, $actualForRole),
                array_diff($actualForRole, $expectedForRole),
            );

            if ($diff !== []) {
                $this->line("  Rol {$role->value}: desviado (".count($diff).' permisos)');
                $driftedRoles[] = $role->value;

                continue;
            }

            $this->line("  Rol {$role->value}: correcto (".count($actualForRole).' permisos)');
        }

        if ($missing === [] && $unknown === [] && $driftedRoles === []) {
            $this->newLine();
            $this->info('  Roles y permisos sincronizados.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($missing !== []) {
            $this->error('  Faltan en la base de datos: '.implode(', ', $missing));
        }

        if ($unknown !== []) {
            // No es un error: un permiso puede retirarse del enum y sobrevivir
            // en la base. Se informa porque conviene limpiarlo a mano.
            $this->warn('  Sobran respecto al código: '.implode(', ', $unknown));
        }

        if ($driftedRoles !== []) {
            $this->error('  Roles con el reparto desviado: '.implode(', ', $driftedRoles));
        }

        if (! $this->option('sync')) {
            $this->newLine();
            $this->line('  El panel puede fallar mientras falten permisos.');
            $this->line('  Ejecuta: php artisan studio:permissions --sync');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();

        // Se delega en `db:seed` en lugar de reimplementar el reparto: la
        // lógica vive en `RoleAndPermissionSeeder`, único origen de verdad, así
        // que este comando no puede divergir de él.
        $this->call('db:seed', ['--class' => 'RoleAndPermissionSeeder', '--force' => true]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info('  Reparto aplicado desde Permission::byRole().');
        $this->newLine();

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles y permisos son obligatorios en todos los entornos: sin ellos no hay
     * acceso al panel. Los datos de demostración son sólo locales.
     */
    public function run(): void
    {
        $this->call(RoleAndPermissionSeeder::class);
        $this->call(AdminUserSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoProductSeeder::class);
        }
    }
}

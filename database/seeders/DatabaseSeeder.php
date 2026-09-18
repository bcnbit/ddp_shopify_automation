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

        // Los mantenimientos de ficha técnica son catálogo, no datos de
        // demostración: sin ellos los selectores de la ficha estarían vacíos y
        // el flujo parecería roto (RFC-0008).
        $this->call(TechnicalSheetMaintenanceSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoProductSeeder::class);
        }
    }
}

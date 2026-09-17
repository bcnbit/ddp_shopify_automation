<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Crea el primer administrador técnico (RFC-0007).
 *
 * No se versiona ninguna credencial: la contraseña se toma de
 * `PRODUCT_STUDIO_ADMIN_PASSWORD` o se genera una aleatoria que se muestra una
 * única vez por consola. Si la variable no está definida y ya existe un
 * administrador, el seeder no hace nada.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('PRODUCT_STUDIO_ADMIN_EMAIL', 'admin@diesdeplatja.test');

        $password = (string) env('PRODUCT_STUDIO_ADMIN_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            if (User::role(Role::AdminTecnico->value)->exists()) {
                $this->command?->info('Ya existe un administrador técnico; no se genera otro.');

                return;
            }

            $password = Str::password(20);
            $generated = true;
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('PRODUCT_STUDIO_ADMIN_PASSWORD debe tener al menos 12 caracteres.');
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('PRODUCT_STUDIO_ADMIN_NAME', 'Administrador técnico'),
                'password' => Hash::make($password),
                'is_active' => true,
                'locale' => 'es',
                'email_verified_at' => now(),
            ],
        );

        if (! $admin->hasRole(Role::AdminTecnico->value)) {
            $admin->assignRole(Role::AdminTecnico->value);
        }

        if ($generated) {
            $this->command?->newLine();
            $this->command?->warn('Credencial generada para '.$email.':');
            $this->command?->line('  '.$password);
            $this->command?->warn('Guárdala ahora: no se vuelve a mostrar y activa el 2FA tras el primer acceso.');
            $this->command?->newLine();
        }
    }
}

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
 * única vez por consola. Si no hay contraseña configurada y ya existe un
 * administrador, el seeder no hace nada.
 *
 * ## Por qué lee `config()` y no `env()`
 *
 * Antes leía `env()` directamente, y eso lo rompía justo en el escenario de
 * producción: Laravel **no carga el `.env` cuando la configuración está
 * cacheada** (`LoadEnvironmentVariables` sale antes de leerlo). Con
 * `config:cache` activo —lo que recomienda RFC-0010— `env()` devolvía `null`, el
 * seeder interpretaba «no me han dado contraseña» y, como el administrador ya
 * existía, terminaba sin cambiar nada **y sin avisar**. Quien lo ejecutaba se
 * quedaba creyendo que su contraseña estaba puesta.
 *
 * Los valores viven en `product-studio.admin` y se resuelven al cachear la
 * configuración, así que funcionan igual con caché y sin ella.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('product-studio.admin.email');
        $name = (string) config('product-studio.admin.name');
        $minLength = (int) config('product-studio.admin.min_password_length', 12);

        $password = (string) (config('product-studio.admin.password') ?? '');
        $generated = false;

        if ($password === '') {
            // Sin contraseña configurada y con un administrador ya existente no
            // se toca nada: generar otra credencial aquí dejaría al administrador
            // actual sin poder entrar y sin que nadie lo haya pedido.
            if (User::role(Role::AdminTecnico->value)->exists()) {
                $this->command?->info('Ya existe un administrador técnico y no se ha indicado contraseña nueva; no se cambia nada.');
                $this->command?->line('Para fijar una contraseña: define PRODUCT_STUDIO_ADMIN_PASSWORD y vuelve a ejecutar este seeder.');

                return;
            }

            $password = Str::password(20);
            $generated = true;
        }

        // Una contraseña demasiado corta se rechaza en lugar de aceptarse en
        // silencio: es la credencial de la cuenta con más privilegios.
        if (mb_strlen($password) < $minLength) {
            throw new RuntimeException("PRODUCT_STUDIO_ADMIN_PASSWORD debe tener al menos {$minLength} caracteres.");
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
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

            return;
        }

        // Cuando la contraseña viene de configuración se confirma el cambio sin
        // imprimirla: es el caso de un reseteo, y la persona ya la conoce.
        $this->command?->info('Contraseña del administrador técnico actualizada ('.$email.').');
    }
}

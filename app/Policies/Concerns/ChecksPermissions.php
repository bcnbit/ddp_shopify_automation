<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Comprobación común de permisos para las Policies.
 *
 * Un usuario inactivo nunca autoriza, ni siquiera con el permiso asignado.
 *
 * ## Sobre un permiso que no existe en la base de datos
 *
 * `Permission::byRole()` es la única fuente de verdad de los permisos, así que
 * añadir uno nuevo al enum es un cambio de código que **no** llega a la base de
 * datos hasta que se ejecuta `RoleAndPermissionSeeder`. Entre esos dos momentos
 * `hasPermissionTo()` lanzaría `PermissionDoesNotExist`, y como las Policies se
 * consultan al pintar la barra lateral, ese fallo **tumbaba el panel entero para
 * todos los usuarios** —incluido el propio login— hasta resembrar.
 *
 * Un permiso que no consta se trata como no concedido: es la respuesta segura
 * (denegar) y, sobre todo, deja el panel en pie para que la persona pueda
 * trabajar en lo demás. El aviso queda en el log para que el desfase se detecte
 * en lugar de quedarse en silencio, y `php artisan studio:permissions` lo
 * informa y lo corrige.
 *
 * Denegar en silencio sería peligroso si el permiso **sí** existiera y sólo
 * faltara en el rol; eso no ocurre aquí, porque ese caso lo resuelve spatie
 * comparando contra el reparto de `byRole()`.
 */
trait ChecksPermissions
{
    protected function allows(User $user, Permission $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value);
        } catch (PermissionDoesNotExist $exception) {
            Log::warning('El permiso no existe en la base de datos: se deniega el acceso.', [
                'permission' => $permission->value,
                'hint' => 'Ejecuta php artisan studio:permissions --sync para sincronizar el reparto.',
            ]);

            return false;
        }
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

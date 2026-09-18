<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Autorización de la conexión con Shopify (RFC-0009).
 *
 * Conectar la tienda es una acción de configuración: hoy sólo el administrador
 * técnico tiene `settings.manage`. La operadora no debe poder reinstalar la
 * aplicación ni ver el estado de la credencial.
 *
 * La pantalla se oculta del menú si no hay permiso, pero la barrera real es esta
 * Policy: Filament la consulta al resolver la página y al ejecutar sus acciones.
 */
class ShopifyInstallationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }
}

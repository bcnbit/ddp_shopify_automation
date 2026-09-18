<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TechnicalSheetEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Autorización de los mantenimientos de ficha técnica (RFC-0008).
 *
 * Sirve a los cuatro modelos porque todos heredan de `TechnicalSheetEntry` y
 * comparten el mismo reparto de permisos: consultar el catálogo y gestionarlo.
 *
 * Los tres roles gestionan el catálogo (`technical_sheets.manage`). La operadora
 * también, y no es una concesión de conveniencia: es quien prepara las fichas y
 * quien detecta que falta una composición o un perfil de cuidados, así que
 * obligarla a pedirlo a otra persona sólo añadía una espera. Antes eran de sólo
 * lectura para ella.
 *
 * Que editarlo no sea peligroso **no** depende de quién lo edite, sino de la
 * copia congelada: cambiar un mantenimiento nunca altera una ficha que ya lo usa
 * (ver `TechnicalSheetSnapshotService`). Desactivar un mantenimiento lo retira de
 * las fichas nuevas sin tocar las existentes.
 *
 * Ocultar el recurso del menú no autoriza: esta Policy es la barrera real.
 */
class TechnicalSheetEntryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, Permission::TechnicalSheetsView, Permission::TechnicalSheetsManage);
    }

    public function view(User $user, TechnicalSheetEntry $entry): bool
    {
        return $this->allowsAny($user, Permission::TechnicalSheetsView, Permission::TechnicalSheetsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::TechnicalSheetsManage);
    }

    public function update(User $user, TechnicalSheetEntry $entry): bool
    {
        return $this->allows($user, Permission::TechnicalSheetsManage);
    }

    public function delete(User $user, TechnicalSheetEntry $entry): bool
    {
        return $this->allows($user, Permission::TechnicalSheetsManage);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, Permission::TechnicalSheetsManage);
    }
}

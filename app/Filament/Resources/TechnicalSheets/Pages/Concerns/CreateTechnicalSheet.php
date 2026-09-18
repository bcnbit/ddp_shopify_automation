<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages\Concerns;

use Filament\Resources\Pages\CreateRecord;

/**
 * Base del alta de un mantenimiento (RFC-0008).
 *
 * Se queda en la pantalla tras guardar: montar el catálogo se hace en tandas y
 * volver al listado obligaría a entrar de nuevo cada vez.
 */
abstract class CreateTechnicalSheet extends CreateRecord
{
    protected static bool $canCreateAnother = true;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Mantenimiento creado';
    }
}

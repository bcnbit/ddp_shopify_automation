<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\CareResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\EditTechnicalSheet;

/**
 * Edición de un mantenimiento de perfiles de cuidados (RFC-0008).
 */
class EditCare extends EditTechnicalSheet
{
    protected static string $resource = CareResource::class;
}

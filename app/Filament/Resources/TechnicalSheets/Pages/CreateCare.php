<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\CareResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\CreateTechnicalSheet;

/**
 * Alta de un mantenimiento de perfiles de cuidados (RFC-0008).
 */
class CreateCare extends CreateTechnicalSheet
{
    protected static string $resource = CareResource::class;
}

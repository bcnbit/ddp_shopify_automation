<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\FitResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\CreateTechnicalSheet;

/**
 * Alta de un mantenimiento de perfiles de ajuste (RFC-0008).
 */
class CreateFit extends CreateTechnicalSheet
{
    protected static string $resource = FitResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\FitResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\ListTechnicalSheets;

/**
 * Listado de perfiles de ajuste (RFC-0008).
 */
class ListFits extends ListTechnicalSheets
{
    protected static string $resource = FitResource::class;
}

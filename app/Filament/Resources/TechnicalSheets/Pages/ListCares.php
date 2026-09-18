<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\CareResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\ListTechnicalSheets;

/**
 * Listado de perfiles de cuidados (RFC-0008).
 */
class ListCares extends ListTechnicalSheets
{
    protected static string $resource = CareResource::class;
}

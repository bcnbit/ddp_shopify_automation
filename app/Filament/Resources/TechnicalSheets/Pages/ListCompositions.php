<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\CompositionResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\ListTechnicalSheets;

/**
 * Listado de composiciones (RFC-0008).
 */
class ListCompositions extends ListTechnicalSheets
{
    protected static string $resource = CompositionResource::class;
}

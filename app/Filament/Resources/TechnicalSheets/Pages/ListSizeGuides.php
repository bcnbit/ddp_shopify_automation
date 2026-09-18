<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\Pages\Concerns\ListTechnicalSheets;
use App\Filament\Resources\TechnicalSheets\SizeGuideResource;

/**
 * Listado de guías de tallas (RFC-0008).
 */
class ListSizeGuides extends ListTechnicalSheets
{
    protected static string $resource = SizeGuideResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\Pages\Concerns\CreateTechnicalSheet;
use App\Filament\Resources\TechnicalSheets\SizeGuideResource;

/**
 * Alta de un mantenimiento de guías de tallas (RFC-0008).
 */
class CreateSizeGuide extends CreateTechnicalSheet
{
    protected static string $resource = SizeGuideResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\Pages\Concerns\EditTechnicalSheet;
use App\Filament\Resources\TechnicalSheets\SizeGuideResource;

/**
 * Edición de un mantenimiento de guías de tallas (RFC-0008).
 */
class EditSizeGuide extends EditTechnicalSheet
{
    protected static string $resource = SizeGuideResource::class;
}

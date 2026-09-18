<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages;

use App\Filament\Resources\TechnicalSheets\CompositionResource;
use App\Filament\Resources\TechnicalSheets\Pages\Concerns\EditTechnicalSheet;

/**
 * Edición de un mantenimiento de composiciones (RFC-0008).
 */
class EditComposition extends EditTechnicalSheet
{
    protected static string $resource = CompositionResource::class;
}

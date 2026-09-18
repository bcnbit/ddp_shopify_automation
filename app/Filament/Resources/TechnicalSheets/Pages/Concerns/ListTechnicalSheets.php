<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages\Concerns;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Base del listado de mantenimientos (RFC-0008).
 *
 * Es abstracta y **no** declara `$resource`: Filament lo exige en la clase
 * concreta, así que cada mantenimiento aporta su página mínima. Compartir una
 * única página entre los cuatro recursos no es posible en Filament 4, y forzarlo
 * con una resolución dinámica haría que la URL de un recurso pudiera resolver el
 * modelo de otro.
 */
abstract class ListTechnicalSheets extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo mantenimiento'),
        ];
    }
}

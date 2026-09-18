<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets\Pages\Concerns;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Base de la edición de un mantenimiento (RFC-0008).
 *
 * Al cambiar el contenido se sube la versión. Las fichas que ya lo usan
 * conservan su copia congelada, y la versión es lo que permite detectar que
 * apuntan a una anterior.
 */
abstract class EditTechnicalSheet extends EditRecord
{
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Eliminar mantenimiento')
                ->modalHeading('¿Eliminar este mantenimiento?')
                ->modalDescription(
                    'Las fichas que ya lo usan conservan su copia congelada: seguirán enviando el mismo texto '
                    .'a Shopify. Sólo deja de ofrecerse en fichas nuevas.'
                )
                ->visible(fn (): bool => auth()->user()?->can('delete', $this->getRecord()) === true),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Mantenimiento actualizado';
    }
}

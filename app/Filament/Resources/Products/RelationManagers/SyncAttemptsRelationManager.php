<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\SyncStatus;
use App\Models\SyncAttempt;
use App\Services\Shopify\ProductSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * «Sincronizaciones con error» (RFC-0004 / RFC-0007).
 *
 * Muestra la causa, el último intento y la referencia de soporte, con un botón
 * de reintento autorizado. El reintento **continúa** desde donde se quedó: los
 * GID ya guardados se reutilizan y las imágenes con `sha256` conocido no se
 * vuelven a subir.
 */
class SyncAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncAttempts';

    protected static ?string $title = 'Sincronizaciones con Shopify';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->since()
                    ->tooltip(fn (SyncAttempt $record): string => $record->created_at?->toDateTimeString() ?? ''),

                TextColumn::make('operation')
                    ->label('Operación')
                    ->badge(),

                TextColumn::make('attempt_number')
                    ->label('Intento')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('error_message')
                    ->label('Causa')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('—'),

                TextColumn::make('support_reference')
                    ->label('Referencia')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('attemptedBy.name')
                    ->label('Solicitada por')
                    ->placeholder('Sistema'),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Reintentar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('¿Reintentar la sincronización?')
                    ->modalDescription(
                        'El reintento continúa desde donde se quedó: no se duplicará el producto '
                        .'ni se volverán a subir las imágenes que ya están en Shopify.'
                    )
                    ->visible(fn (SyncAttempt $record): bool => auth()->user()?->can('retry', $record) === true)
                    ->action(function (SyncAttempt $record): void {
                        try {
                            app(ProductSyncService::class)->retry($record, auth()->user());
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title('No se ha podido reintentar')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Reintento en marcha')
                            ->body('Se continuará desde el último paso completado.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Todavía no se ha enviado')
            ->emptyStateDescription('Cuando envíes la ficha a Shopify, cada intento quedará registrado aquí.')
            ->paginated([5, 10, 25]);
    }

    /**
     * El historial de sincronización no se crea ni se edita desde la interfaz:
     * lo escribe el servicio de sincronización.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('view', $ownerRecord);
    }

    public static function getModelLabel(): string
    {
        return 'intento de sincronización';
    }

    public static function getPluralModelLabel(): string
    {
        return 'intentos de sincronización';
    }
}
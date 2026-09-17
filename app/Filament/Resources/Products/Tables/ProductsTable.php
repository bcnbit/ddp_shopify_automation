<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\Audience;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de trabajo (RFC-0002).
 *
 * Filtros por estado, fecha, usuaria, tipo, colección y errores. El listado
 * está pensado para trabajarse en «Pendientes» durante el día, así que los
 * estados se muestran con color y el número de pendientes aparece en el menú.
 */
class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),

                TextColumn::make('internal_reference')
                    ->label('Referencia')
                    ->searchable()
                    ->copyable()
                    ->weight('Medium'),

                TextColumn::make('source_name')
                    ->label('Nombre')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('product_type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Precio')
                    ->money(fn (Product $record): string => $record->currency ?? 'EUR')
                    ->sortable()
                    ->placeholder('Sin precio'),

                TextColumn::make('variants_count')
                    ->label('Variantes')
                    ->counts('variants')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'gray'),

                IconColumn::make('primary_media')
                    ->label('Foto')
                    ->state(fn (Product $record): bool => $record->media()->where('is_primary', true)->exists())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('creator.name')
                    ->label('Creada por')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('collection_context')
                    ->label('Colección')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shopify_product_gid')
                    ->label('Shopify')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Sin enviar' : 'En Shopify')
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'success')
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->since()
                    ->sortable()
                    ->tooltip(fn (Product $record): string => $record->updated_at?->toDateTimeString() ?? ''),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(ProductStatus::class)
                    ->multiple(),

                SelectFilter::make('product_type')
                    ->label('Tipo de prenda')
                    ->options(ProductType::class)
                    ->multiple(),

                SelectFilter::make('audience')
                    ->label('Público')
                    ->options(Audience::class)
                    ->multiple(),

                SelectFilter::make('created_by')
                    ->label('Creada por')
                    ->relationship('creator', 'name')
                    ->visible(fn (): bool => auth()->user()?->can('products.view_all') === true),

                SelectFilter::make('collection_context')
                    ->label('Colección')
                    ->options(fn (): array => Product::query()
                        ->whereNotNull('collection_context')
                        ->distinct()
                        ->orderBy('collection_context')
                        ->pluck('collection_context', 'collection_context')
                        ->all()),

                TernaryFilter::make('has_errors')
                    ->label('Con errores')
                    ->placeholder('Todas')
                    ->trueLabel('Sólo con errores')
                    ->falseLabel('Sin errores')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('status', [
                            ProductStatus::GenerationFailed->value,
                            ProductStatus::ValidationFailed->value,
                            ProductStatus::SyncFailed->value,
                        ]),
                        false: fn (Builder $query): Builder => $query->whereNotIn('status', [
                            ProductStatus::GenerationFailed->value,
                            ProductStatus::ValidationFailed->value,
                            ProductStatus::SyncFailed->value,
                        ]),
                    ),

                TernaryFilter::make('ready_to_send')
                    ->label('Listas para enviar')
                    ->placeholder('Todas')
                    ->trueLabel('Sólo listas')
                    ->falseLabel('No listas')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('status', ProductStatus::Approved->value),
                        false: fn (Builder $query): Builder => $query->where('status', '!=', ProductStatus::Approved->value),
                    ),

                Filter::make('created_at')
                    ->label('Fecha de creación')
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),

                Filter::make('pending')
                    ->label('Sólo pendientes')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        ProductStatus::Draft->value,
                        ProductStatus::Review->value,
                    ])),
            ])
            ->recordActions([
                EditAction::make()->label('Abrir'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('products.delete') === true),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay fichas')
            ->emptyStateDescription('Crea la primera ficha para empezar a preparar productos.');
    }
}

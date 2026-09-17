<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\InventoryPolicy;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Matriz de variantes color × talla (RFC-0002 / RFC-0005).
 *
 * La tabla local es la fuente de verdad. El límite de dos opciones es una regla
 * del MVP; se hace explícita en la interfaz en lugar de dejar campos vacíos.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variantes';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Obligatorio y único en todo el catálogo.')
                    ->live(onBlur: true),

                TextInput::make('barcode')
                    ->label('Código de barras')
                    ->maxLength(64),

                Select::make('option1_name')
                    ->label('Opción 1')
                    ->options(['Color' => 'Color', '' => 'Sin opción'])
                    ->default('Color')
                    ->native(false),

                TextInput::make('option1_value')
                    ->label('Valor 1')
                    ->maxLength(64)
                    ->helperText('Por ejemplo: Blanco, Arena. Vacío = sin variante de color.'),

                Select::make('option2_name')
                    ->label('Opción 2')
                    ->options(['Talla' => 'Talla', '' => 'Sin opción'])
                    ->default('Talla')
                    ->native(false),

                TextInput::make('option2_value')
                    ->label('Valor 2')
                    ->maxLength(64),

                TextInput::make('price')
                    ->label('Precio propio')
                    ->numeric()
                    ->prefix('€')
                    ->helperText('Si se deja vacío, hereda el precio de la ficha.'),

                TextInput::make('compare_at_price')
                    ->label('Precio anterior')
                    ->numeric()
                    ->prefix('€'),

                Select::make('inventory_policy')
                    ->label('Política de inventario')
                    ->options(InventoryPolicy::class)
                    ->default(InventoryPolicy::Deny->value)
                    ->native(false)
                    ->helperText('El MVP no inventa stock.'),

                TextInput::make('inventory_quantity')
                    ->label('Cantidad inicial')
                    ->numeric()
                    ->helperText('Dejar vacío salvo que una persona confirme el stock.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('option1_value')
                    ->label('Color')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('option2_value')
                    ->label('Talla')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
                    ->weight('Medium'),

                TextColumn::make('price')
                    ->label('Precio')
                    ->money('EUR')
                    ->placeholder('Hereda'),

                TextColumn::make('inventory_policy')
                    ->label('Inventario')
                    ->badge(),

                TextColumn::make('shopify_variant_gid')
                    ->label('En Shopify')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Pendiente' : 'Sincronizada')
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'success')
                    ->toggleable(),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir variante')
                    ->visible(fn (): bool => $this->canEditOwner()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canEditOwner()),
                DeleteAction::make()
                    ->modalHeading('¿Eliminar esta variante?')
                    ->modalDescription('Queda registrado en la auditoría y no se puede deshacer.')
                    ->visible(fn (): bool => $this->canEditOwner() && $this->ownerRecord->variants()->count() > 1),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canEditOwner()),
                ]),
            ]);
    }

    /**
     * La autorización se delega en la Policy de la ficha: la interfaz no decide.
     */
    private function canEditOwner(): bool
    {
        $owner = $this->ownerRecord;

        return $owner instanceof Product && auth()->user()?->can('update', $owner) === true;
    }
}

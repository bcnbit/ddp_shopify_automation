<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\InventoryPolicy;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductVariantService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

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
                // Este va **antes** que `CreateAction` a propósito: Filament
                // respeta el orden del array y el botón debe quedar a su izquierda.
                Action::make('addSizes')
                    ->label('Añadir Variante Tallas')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->visible(fn (): bool => $this->canEditOwner())
                    ->requiresConfirmation()
                    ->modalHeading('¿Añadir las tallas estándar?')
                    ->modalDescription(function (): string {
                        $faltan = $this->missingSizes();

                        if ($faltan === []) {
                            return 'Esta ficha ya tiene todas las tallas estándar: no hay nada que añadir.';
                        }

                        return 'Se añadirán las tallas que falten: '.implode(', ', $faltan)
                            .'. El resto de variantes no se toca.';
                    })
                    ->modalSubmitActionLabel('Añadir tallas')
                    ->action(function (): void {
                        $product = $this->ownerRecord;

                        try {
                            $created = app(ProductVariantService::class)->generateSizes(
                                $product,
                                auth()->user(),
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title('No se han podido añadir las tallas')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($created === 0) {
                            // No es un error: la ficha ya tenía todas las tallas.
                            // Conviene decirlo, o parece que el botón no funciona.
                            Notification::make()
                                ->title('No había nada que añadir')
                                ->body('Esta ficha ya tiene todas las tallas estándar.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($created === 1 ? 'Talla añadida' : $created.' tallas añadidas')
                            ->body('Se han creado con el precio de la ficha. Las imágenes no se han tocado.')
                            ->success()
                            ->send();
                    }),

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
     * Tallas estándar que todavía no tiene la ficha, en el orden configurado.
     *
     * Se calcula **en el servidor** y con la misma regla que usa el servicio
     * (comparar la combinación color×talla): si aquí se contara de otra forma, el
     * aviso diría «se añadirán 2» y luego se crearían 3, o al revés.
     *
     * @return list<string>
     */
    private function missingSizes(): array
    {
        /** @var list<string> $sizes */
        $sizes = array_values((array) config('product-studio.variants.standard_sizes', []));

        // Se reutiliza la clave del servicio: una sola definición de «esta
        // combinación ya existe».
        $existing = $this->ownerRecord->variants()->get()->map(
            static fn (ProductVariant $variant): string => ProductVariantService::combinationKey(
                (string) $variant->option1_value,
                (string) $variant->option2_value,
            ),
        )->all();

        $missing = [];

        foreach ($sizes as $size) {
            // Sin color, la clave del servicio queda «|M».
            if (! in_array(ProductVariantService::combinationKey('', $size), $existing, true)) {
                $missing[] = $size;
            }
        }

        return $missing;
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

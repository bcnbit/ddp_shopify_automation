<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Support\Products\TechnicalSheetCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Formulario de ficha (RFC-0002).
 *
 * Tres pestañas, en el orden en que la operadora trabaja: primero los datos que
 * confirma una persona, después el contenido comercial y por último lo que se
 * envía a Shopify. Las imágenes y las variantes se gestionan en sus propias
 * pestañas (RelationManagers), porque necesitan orden y acciones propias.
 *
 * Los campos son `live(onBlur: true)` para que el guardado automático de la
 * página de edición tenga cambios que persistir sin inundar el servidor.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.forms.components.auto-save')
                    ->viewData(['interval' => 5])
                    ->dehydrated(false)
                    ->visibleOn(['edit']),

                View::make('filament.forms.components.product-warnings')
                    ->dehydrated(false)
                    ->visibleOn(['edit']),

                Tabs::make('ficha')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Datos verificados')
                            ->icon('heroicon-o-check-badge')
                            ->schema(self::verifiedSchema()),
                        Tab::make('Contenido comercial')
                            ->icon('heroicon-o-document-text')
                            ->schema(self::contentSchema()),
                        Tab::make('SEO y Shopify')
                            ->icon('heroicon-o-globe-alt')
                            ->schema(self::seoSchema()),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function verifiedSchema(): array
    {
        return [
            Section::make('Identificación')
                ->columns(3)
                ->schema([
                    TextInput::make('internal_reference')
                        ->label('Referencia interna o SKU base')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText('Se normaliza a mayúsculas.')
                        ->live(onBlur: true),

                    TextInput::make('source_name')
                        ->label('Nombre provisional')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Cómo llamarías a esta prenda al hablar con alguien.')
                        ->live(onBlur: true)
                        ->columnSpan(2),

                    Select::make('product_type')
                        ->label('Tipo de prenda')
                        ->options(ProductType::class)
                        ->required()
                        ->native(false)
                        ->live(),

                    Select::make('audience')
                        ->label('Público')
                        ->options(Audience::class)
                        ->native(false)
                        ->live(),

                    TextInput::make('brand')
                        ->label('Marca')
                        ->maxLength(255)
                        ->default('Dies de Platja')
                        ->live(onBlur: true),
                ]),

            Section::make('Datos comerciales')
                ->description('Son datos reales: los confirmas tú, no la IA.')
                ->columns(3)
                ->schema([
                    TextInput::make('price')
                        ->label('Precio de venta')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->prefix('€')
                        ->helperText('IVA incluido. Bloqueante si falta.')
                        ->live(onBlur: true),

                    TextInput::make('compare_at_price')
                        ->label('Precio anterior')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('€')
                        ->helperText('Sólo si hay rebaja real.')
                        ->live(onBlur: true),

                    TextInput::make('currency')
                        ->label('Moneda')
                        ->default('EUR')
                        ->maxLength(3)
                        ->required(),
                ]),

            Section::make('Ficha técnica')
                ->description('Lo que dejes vacío no se podrá mencionar en la descripción.')
                ->columns(2)
                ->schema([
                    // Los mantenimientos se eligen de un catálogo (RFC-0008) en
                    // lugar de escribirse a mano: así el dato está confirmado una
                    // sola vez y no se reescribe distinto en cada ficha.
                    self::technicalSheetSelect(
                        'technical_sheet_composition_id',
                        'Composición',
                        ProductTechnicalSheet::SLOT_COMPOSITION,
                        'No se podrá mencionar la composición en la descripción.',
                    ),

                    self::technicalSheetSelect(
                        'technical_sheet_fit_id',
                        'Ajuste / tallaje',
                        ProductTechnicalSheet::SLOT_FIT,
                        'No se podrá mencionar el corte ni el tallaje.',
                    ),

                    self::technicalSheetSelect(
                        'technical_sheet_care_id',
                        'Cuidados',
                        ProductTechnicalSheet::SLOT_CARE,
                        'No se podrán mencionar los cuidados.',
                    ),

                    self::technicalSheetSelect(
                        'technical_sheet_size_guide_id',
                        'Guía de tallas',
                        ProductTechnicalSheet::SLOT_SIZE_GUIDE,
                        'La descripción no incluirá tabla de medidas.',
                    ),

                    Textarea::make('ai_base_description')
                        ->label('Descripción base para IA')
                        ->rows(4)
                        ->helperText('No se publica literalmente. Se utiliza para mejorar la propuesta generada por IA.')
                        ->live(onBlur: true)
                        ->columnSpanFull(),

                    TextInput::make('collection_context')
                        ->label('Colección o campaña')
                        ->maxLength(255)
                        ->live(onBlur: true),

                    Textarea::make('notes')
                        ->label('Observaciones internas')
                        ->rows(2)
                        ->helperText('No se envía a Shopify ni a la IA.')
                        ->live(onBlur: true),

                    // La previsualización comparte compositor con el envío a
                    // Shopify: lo que se ve aquí es exactamente lo que se enviará.
                    View::make('filament.forms.components.technical-sheet-preview')
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function contentSchema(): array
    {
        return [
            View::make('filament.forms.components.content-proposal')
                ->dehydrated(false)
                ->visibleOn(['edit']),
        ];
    }

    /**
     * Selector de un mantenimiento de ficha técnica (RFC-0008).
     *
     * Es `searchable` porque el catálogo crece: buscar por nombre es más rápido
     * que recorrer una lista. Se permite dejarlo vacío —una ficha sin
     * mantenimiento es válida— y la selección actual se incluye siempre entre
     * las opciones para que desactivar un mantenimiento no borre en silencio lo
     * que una ficha ya tenía.
     *
     * `options()` se evalúa con el estado actual del formulario, así que al
     * cambiar el tipo de prenda el selector se recalcula (`live()` en ese campo).
     */
    private static function technicalSheetSelect(string $name, string $label, string $slot, string $emptyHelp): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->native(false)
            ->placeholder('Sin seleccionar')
            ->options(function (callable $get, ?Product $record) use ($slot): array {
                $catalog = app(TechnicalSheetCatalog::class);

                return $catalog->options(
                    $slot,
                    $catalog->typeFrom($get('product_type')),
                    $catalog->audienceFrom($get('audience')),
                    // `$record` es la ficha que se está editando (nula al crear).
                    // Se pasa para que su mantenimiento actual siga entre las
                    // opciones aunque se haya desactivado.
                    $record?->getKey() === null ? null : (int) $record->getKey(),
                );
            })
            ->getOptionLabelUsing(function (mixed $value) use ($slot): ?string {
                // Sin esto, un mantenimiento desactivado —que ya no está entre
                // las opciones— se mostraría como un número suelto en lugar de
                // por su nombre.
                return app(TechnicalSheetCatalog::class)->labelFor($slot, $value);
            })
            ->helperText($emptyHelp)
            ->live();
    }

    /**
     * @return array<int, mixed>
     */
    private static function seoSchema(): array
    {
        return [
            Section::make('Shopify')
                ->columns(2)
                ->schema([
                    TextInput::make('shopify_handle')
                        ->label('Handle')
                        ->maxLength(255)
                        ->helperText('Forma la URL. Minúsculas y guiones.')
                        ->live(onBlur: true),

                    TextInput::make('shopify_product_gid')
                        ->label('Producto en Shopify')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Todavía no se ha enviado')
                        ->helperText('Con valor, el botón pasa a «Actualizar borrador».'),
                ]),
        ];
    }
}

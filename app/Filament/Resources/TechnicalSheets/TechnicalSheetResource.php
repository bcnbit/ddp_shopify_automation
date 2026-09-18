<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetEntry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Base de los cuatro mantenimientos de ficha técnica (RFC-0008).
 *
 * Los cuatro comparten cabecera —código, nombre, tipo de prenda, público y
 * estado activo— y navegación. Lo único propio de cada uno son sus campos de
 * contenido, que aporta la subclase.
 *
 * Ocultar el recurso del menú no autoriza: la barrera son las Policies, que
 * Filament consulta en cada página y acción.
 */
abstract class TechnicalSheetResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Mantenimientos de ficha técnica';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'mantenimiento';

    protected static ?string $pluralModelLabel = 'mantenimientos';

    /**
     * Campos de contenido propios del mantenimiento.
     *
     * @return array<int, mixed>
     */
    abstract protected static function contentSchema(): array;

    /**
     * Texto de ayuda que explica para qué sirve este mantenimiento.
     */
    abstract protected static function contentHelp(): string;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre interno')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Cómo lo reconoces tú en el listado.')
                            ->live(onBlur: true),

                        TextInput::make('code')
                            ->label('Código único')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->helperText('Legible y estable. Se normaliza a mayúsculas y guiones.')
                            ->live(onBlur: true),

                        Select::make('product_type')
                            ->label('Tipo de prenda aplicable')
                            ->options(ProductType::class)
                            ->placeholder('Cualquier tipo')
                            ->native(false)
                            ->helperText('Vacío = sirve para cualquier ficha.'),

                        Select::make('audience')
                            ->label('Público aplicable')
                            ->options(Audience::class)
                            ->placeholder('Cualquier público')
                            ->native(false)
                            ->helperText('Vacío = sirve para cualquier público.'),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Un mantenimiento inactivo no se ofrece en fichas nuevas, pero las que ya lo usan lo conservan.')
                            ->columnSpanFull(),

                        TextInput::make('version')
                            ->label('Versión')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('1')
                            ->helperText('La sube el sistema al cambiar el contenido. No se edita a mano.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Contenido')
                    ->description(static::contentHelp())
                    ->schema(static::contentSchema()),
            ]);
    }

    /**
     * Tabla compartida por los cuatro mantenimientos.
     *
     * Las columnas de contenido las aporta cada recurso: en la tabla sólo se
     * muestra un extracto, porque el texto aprobado puede ser largo y lo que
     * aquí se busca es reconocer el mantenimiento de un vistazo.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('Medium'),

                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->copyable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('product_type')
                    ->label('Tipo')
                    ->badge()
                    ->placeholder('Cualquiera'),

                TextColumn::make('audience')
                    ->label('Público')
                    ->badge()
                    ->placeholder('Cualquiera'),

                TextColumn::make('version')
                    ->label('Versión')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(static fn ($state): string => 'v'.$state),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state ? 'Activo' : 'Inactivo')
                    ->color(static fn ($state): string => $state ? 'success' : 'gray'),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Sólo activos')
                    ->falseLabel('Sólo inactivos')
                    ->default(true),

                SelectFilter::make('product_type')
                    ->label('Tipo de prenda')
                    ->options(ProductType::class),

                SelectFilter::make('audience')
                    ->label('Público')
                    ->options(Audience::class),
            ])
            ->recordActions([
                EditAction::make()->label('Editar'),
                ReplicateAction::make()
                    ->label('Duplicar')
                    ->modalHeading('Duplicar mantenimiento')
                    ->modalDescription(
                        'Copia el contenido para partir de algo ya escrito: un ajuste parecido, '
                        .'una composición con otro porcentaje. El original no se toca.'
                    )
                    ->modalSubmitActionLabel('Duplicar')
                    // El código es único, así que la copia no puede heredarlo: se
                    // pide uno nuevo, con una sugerencia ya calculada para que
                    // duplicar sea pulsar y confirmar en el caso normal.
                    ->schema([
                        TextInput::make('code')
                            ->label('Código de la copia')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->helperText('Único en todo el catálogo. Se normaliza a mayúsculas y guiones.'),

                        TextInput::make('name')
                            ->label('Nombre interno')
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Activa desde el principio')
                            ->default(false)
                            ->helperText(
                                'Desactivada no se ofrece en fichas nuevas mientras la terminas de revisar. '
                                .'Las fichas que ya usaban el original no cambian.'
                            ),
                    ])
                    ->fillForm(static fn (TechnicalSheetEntry $record): array => [
                        'code' => static::suggestCopyCode($record),
                        'name' => $record->name.' (copia)',
                        'is_active' => false,
                    ])
                    ->excludeAttributes(['code', 'version', 'created_at', 'updated_at'])
                    ->successNotificationTitle('Mantenimiento duplicado')
                    ->visible(fn (): bool => auth()->user()?->can('create', static::getModel()) === true),
                DeleteAction::make()
                    ->modalDescription(
                        'Las fichas que ya lo usan conservan su copia congelada: seguirán enviando el mismo texto '
                        .'a Shopify. Sólo deja de ofrecerse en fichas nuevas.'
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(
                            'Las fichas que ya los usan conservan su copia congelada. '
                            .'Sólo dejan de ofrecerse en fichas nuevas.'
                        ),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay mantenimientos')
            ->emptyStateDescription('Crea el primero para poder reutilizarlo en las fichas de producto.');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    /**
     * Código libre sugerido para una copia.
     *
     * Duplicar dos veces el mismo mantenimiento no debe chocar con el código de
     * la copia anterior, así que se prueba `-COPIA`, `-COPIA-2`, `-COPIA-3`… y se
     * devuelve el primero libre. Es sólo una sugerencia: la persona puede
     * escribir otro y el campo valida la unicidad igualmente.
     */
    protected static function suggestCopyCode(TechnicalSheetEntry $record): string
    {
        $base = (string) $record->code;
        $candidate = $base.'-COPIA';
        $suffix = 2;

        while (static::getModel()::query()->where('code', $candidate)->exists()) {
            $candidate = $base.'-COPIA-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Nota de contenido compartida por los tres mantenimientos de texto.
     */
    protected static function textHelp(): string
    {
        return 'Se copia a la ficha al seleccionarlo: cambiar esto después no altera las fichas que ya lo usan.';
    }

    /**
     * Selector común de tipo de prenda, por si una subclase necesita reutilizarlo.
     */
    protected static function productTypeSelect(): Select
    {
        return Select::make('product_type')
            ->label('Tipo de prenda aplicable')
            ->options(ProductType::class)
            ->placeholder('Cualquier tipo')
            ->native(false);
    }

    /**
     * Área de texto común de los mantenimientos de texto.
     */
    protected static function contentTextarea(string $label, int $rows = 3): Textarea
    {
        return Textarea::make('content_text')
            ->label($label)
            ->required()
            ->rows($rows)
            ->columnSpanFull();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', static::getModel()) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', static::getModel()) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) === true;
    }
}

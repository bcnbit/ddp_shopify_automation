<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Enums\Permission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Filament\Resources\Products\RelationManagers\SyncAttemptsRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Recurso de fichas (RFC-0002).
 *
 * Ninguna consulta sale del ámbito del usuario: la operadora sólo ve sus
 * fichas y el responsable ve todas. Esto es una segunda barrera además de la
 * Policy, para que un filtro mal construido no exponga datos ajenos.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Fichas';

    protected static ?string $modelLabel = 'ficha';

    protected static ?string $pluralModelLabel = 'fichas';

    protected static ?string $recordTitleAttribute = 'source_name';

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MediaRelationManager::class,
            VariantsRelationManager::class,
            SyncAttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['internal_reference', 'source_name', 'brand', 'collection_context'];
    }

    /**
     * @return Builder<Product>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        // Quien puede ver todo el catálogo no necesita filtro; el resto sólo ve
        // lo suyo, coincida o no con la Policy del registro concreto.
        if ($user->can(Permission::ProductsViewAll->value)) {
            return $query;
        }

        return $query->where('created_by', $user->getKey());
    }

    /**
     * Columnas que el guardado automático puede escribir.
     *
     * Se declara aquí para que la página y el formulario compartan la misma
     * lista: si un campo nuevo se añade al formulario y no a esta lista, no se
     * guardará, y eso es preferible a que un dato desconocido llegue al modelo.
     *
     * @return list<string>
     */
    public static function editableProductAttributes(): array
    {
        return [
            'internal_reference',
            'source_name',
            'brand',
            'product_type',
            'audience',
            'price',
            'compare_at_price',
            'currency',
            'technical_sheet_composition_id',
            'technical_sheet_fit_id',
            'technical_sheet_care_id',
            'technical_sheet_size_guide_id',
            'ai_base_description',
            'collection_context',
            'notes',
            'shopify_handle',
        ];
    }

    /**
     * Representación exportable de una ficha, para revisión o soporte.
     *
     * No incluye ninguna credencial: sólo datos de la ficha, sus variantes,
     * medios y versiones de contenido.
     *
     * @return array<string, mixed>
     */
    public static function toExportArray(Product $product): array
    {
        return [
            'internal_reference' => $product->internal_reference,
            'status' => $product->status->value,
            'source_name' => $product->source_name,
            'brand' => $product->brand,
            'product_type' => $product->product_type?->value,
            'audience' => $product->audience?->value,
            'price' => $product->price,
            'compare_at_price' => $product->compare_at_price,
            'currency' => $product->currency,
            'composition' => $product->composition,
            'fit' => $product->fit,
            'care_instructions' => $product->care_instructions,
            'ai_base_description' => $product->ai_base_description,
            'technical_sheets' => $product->technicalSheets->map(static fn ($sheet): array => [
                'slot' => $sheet->slot,
                'entry_id' => $sheet->entry_id,
                'entry_code' => $sheet->entry_code,
                'entry_name' => $sheet->entry_name,
                'entry_version' => $sheet->entry_version,
                'captured_at' => $sheet->captured_at?->toIso8601String(),
            ])->all(),
            'collection_context' => $product->collection_context,
            'shopify_product_gid' => $product->shopify_product_gid,
            'shopify_handle' => $product->shopify_handle,
            'last_synced_at' => $product->last_synced_at?->toIso8601String(),
            'variants' => $product->variants->map(static fn ($variant): array => [
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'option1_name' => $variant->option1_name,
                'option1_value' => $variant->option1_value,
                'option2_name' => $variant->option2_name,
                'option2_value' => $variant->option2_value,
                'price' => $variant->price,
                'inventory_policy' => $variant->inventory_policy->value,
                'position' => $variant->position,
            ])->all(),
            'media' => $product->media->map(static fn ($item): array => [
                'original_filename' => $item->original_filename,
                'mime_type' => $item->mime_type,
                'bytes' => $item->bytes,
                'sha256' => $item->sha256,
                'width' => $item->width,
                'height' => $item->height,
                'alt_text' => $item->alt_text,
                'is_primary' => $item->is_primary,
                'sort_order' => $item->sort_order,
            ])->all(),
            'contents' => $product->contents->map(static fn ($content): array => [
                'locale' => $content->locale->value,
                'version' => $content->version,
                'title' => $content->title,
                'handle' => $content->handle,
                'html_description' => $content->html_description,
                'seo_title' => $content->seo_title,
                'seo_description' => $content->seo_description,
                'tags' => $content->tags(),
                'warnings' => $content->warnings(),
                'approved_at' => $content->approved_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $pending = Product::query()
            ->whereIn('status', [ProductStatus::Draft->value, ProductStatus::Review->value])
            ->when(
                ! $user->can(Permission::ProductsViewAll->value),
                fn (Builder $query): Builder => $query->where('created_by', $user->getKey()),
            )
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Product::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Product::class) === true;
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

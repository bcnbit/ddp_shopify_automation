<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ProductStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Widgets\ProductStatsOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de trabajo (RFC-0002).
 *
 * Las pestañas replican la jornada: la operadora vive en «Pendientes», el
 * responsable revisa «Enviadas».
 */
class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva ficha'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProductStatsOverview::class,
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas'),
            'pending' => Tab::make('Pendientes')
                ->badge(fn (): int => $this->countByStatus([
                    ProductStatus::Draft,
                    ProductStatus::Review,
                ]))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ProductStatus::Draft->value,
                    ProductStatus::Review->value,
                ])),
            'in_progress' => Tab::make('En curso')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ProductStatus::Generating->value,
                    ProductStatus::Syncing->value,
                ])),
            'ready' => Tab::make('Aprobadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ProductStatus::Approved->value)),
            'sent' => Tab::make('Enviadas a Shopify')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ProductStatus::ShopifyDraft->value,
                    ProductStatus::Published->value,
                ])),
            'errors' => Tab::make('Con errores')
                ->badgeColor('danger')
                ->badge(fn (): int => $this->countByStatus([
                    ProductStatus::GenerationFailed,
                    ProductStatus::ValidationFailed,
                    ProductStatus::SyncFailed,
                ]))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ProductStatus::GenerationFailed->value,
                    ProductStatus::ValidationFailed->value,
                    ProductStatus::SyncFailed->value,
                ])),
        ];
    }

    /**
     * @param  list<ProductStatus>  $statuses
     */
    private function countByStatus(array $statuses): int
    {
        return ProductResource::getEloquentQuery()
            ->whereIn('status', array_map(static fn (ProductStatus $status): string => $status->value, $statuses))
            ->count();
    }
}

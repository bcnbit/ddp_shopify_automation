<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Widgets;

use App\Enums\ProductStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Métricas del listado (RFC-0002): fichas creadas, pendientes, tiempo medio a
 * borrador y fallos de sincronización.
 *
 * Respeta el ámbito de visibilidad del recurso, así que una operadora ve sus
 * propias cifras y no las del resto del equipo.
 */
class ProductStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $query = ProductResource::getEloquentQuery();

        return [
            $this->createdStat($query),
            $this->pendingStat($query),
            $this->averageTimeStat($query),
            $this->errorsStat($query),
        ];
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function createdStat(Builder $query): Stat
    {
        $total = (clone $query)->count();
        $thisMonth = (clone $query)->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return Stat::make('Fichas creadas', (string) $total)
            ->description($thisMonth.' este mes')
            ->descriptionIcon('heroicon-o-plus-circle')
            ->color('gray');
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function pendingStat(Builder $query): Stat
    {
        $pending = (clone $query)->whereIn('status', [
            ProductStatus::Draft->value,
            ProductStatus::Review->value,
        ])->count();

        return Stat::make('Pendientes', (string) $pending)
            ->description($pending > 0 ? 'Requieren tu revisión' : 'Todo al día')
            ->descriptionIcon('heroicon-o-clock')
            ->color($pending > 0 ? 'warning' : 'success');
    }

    /**
     * Tiempo medio desde que se crea la ficha hasta que llega a borrador de
     * Shopify.
     *
     * Se calcula en PHP a partir de las fechas porque la aritmética de fechas
     * difiere entre MySQL y SQLite, y la aplicación debe poder probarse en
     * ambos motores. El volumen del MVP hace que traer dos columnas sea barato.
     */
    private function averageTimeStat(Builder $query): Stat
    {
        $durations = (clone $query)
            ->whereNotNull('last_synced_at')
            ->whereNotNull('created_at')
            ->get(['created_at', 'last_synced_at'])
            ->map(static fn (Product $product): float => (float) $product->created_at->diffInSeconds($product->last_synced_at))
            ->all();

        if ($durations === []) {
            return Stat::make('Tiempo medio a borrador', '—')
                ->description('Todavía sin envíos')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('gray');
        }

        $averageSeconds = array_sum($durations) / count($durations);
        $minutes = $averageSeconds / 60;

        return Stat::make('Tiempo medio a borrador', number_format($minutes, 1, ',', '.').' min')
            ->description('Desde la creación hasta Shopify')
            ->descriptionIcon('heroicon-o-arrow-trending-up')
            ->color($minutes <= 3 ? 'success' : 'warning');
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function errorsStat(Builder $query): Stat
    {
        $errors = (clone $query)->whereIn('status', [
            ProductStatus::GenerationFailed->value,
            ProductStatus::ValidationFailed->value,
            ProductStatus::SyncFailed->value,
        ])->count();

        return Stat::make('Con errores', (string) $errors)
            ->description($errors > 0 ? 'Revisa las fichas señaladas' : 'Sin incidencias')
            ->descriptionIcon($errors > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->color($errors > 0 ? 'danger' : 'success');
    }
}

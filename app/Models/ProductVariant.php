<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryPolicy;
use App\Support\Products\SkuNormalizer;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante vendible (RFC-0001 / RFC-0005).
 *
 * La combinación de opciones no puede repetirse dentro de una ficha y el SKU es
 * único en todo el catálogo gestionado por la aplicación.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'option1_name',
        'option1_value',
        'option2_name',
        'option2_value',
        'price',
        'compare_at_price',
        'inventory_policy',
        'inventory_quantity',
        'shopify_variant_gid',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'inventory_policy' => InventoryPolicy::class,
            'inventory_quantity' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /**
         * La posición se asigna sola al crear, si nadie la ha indicado.
         *
         * Sin esto, una variante añadida desde el panel se quedaba con el valor por
         * defecto de la columna (`0`), igual que todas las anteriores, y el listado
         * —que ordena por `position`— salía en un orden arbitrario. Se detectó en una
         * ficha con cinco tallas, todas con posición 0.
         *
         * Se respeta una posición explícita: la tabla es reordenable a mano y una
         * persona puede querer colocar una variante en un sitio concreto.
         */
        static::creating(function (self $variant): void {
            if ($variant->position !== null) {
                return;
            }

            $max = static::query()
                ->where('product_id', $variant->product_id)
                ->max('position');

            // La primera variante de una ficha empieza en 0, no en 1: es el valor
            // con el que ya están las fichas existentes y evita un salto visible
            // entre lo creado a mano y lo creado por un botón.
            $variant->position = $max === null ? 0 : ((int) $max) + 1;
        });

        static::saving(function (self $variant): void {
            $variant->sku_normalized = SkuNormalizer::normalize($variant->sku);

            $variant->option1_name = $variant->option1_name ?? '';
            $variant->option1_value = $variant->option1_value ?? '';
            $variant->option2_name = $variant->option2_name ?? '';
            $variant->option2_value = $variant->option2_value ?? '';
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Etiqueta legible de la combinación de opciones, para listados y avisos.
     */
    public function optionLabel(): string
    {
        $parts = array_filter([
            $this->option1_value !== '' ? $this->option1_value : null,
            $this->option2_value !== '' ? $this->option2_value : null,
        ]);

        return $parts === [] ? 'Sin opciones' : implode(' / ', $parts);
    }

    /**
     * Precio efectivo: el de la variante si existe, si no el de la ficha.
     */
    public function effectivePrice(): ?string
    {
        return $this->price ?? $this->product?->price;
    }
}

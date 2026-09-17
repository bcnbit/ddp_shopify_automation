<?php

declare(strict_types=1);

use App\Enums\InventoryPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Variante vendible (RFC-0001 / RFC-0005).
     *
     * Se usan como máximo dos opciones: Color y Talla. Los campos de opción son
     * NOT NULL con cadena vacía (en lugar de NULL) para que el índice único de
     * combinación funcione igual en MySQL y en SQLite: un NULL no colisiona
     * consigo mismo y permitiría duplicados.
     *
     * `sku_normalized` es la forma normalizada de `sku` y es la que garantiza la
     * unicidad de catálogo sin depender del collation del motor.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64);
            $table->string('sku_normalized', 64)->unique();
            $table->string('barcode', 64)->nullable();

            $table->string('option1_name', 32)->default('');
            $table->string('option1_value', 64)->default('');
            $table->string('option2_name', 32)->default('');
            $table->string('option2_value', 64)->default('');

            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('inventory_policy', 16)->default(InventoryPolicy::Deny->value);
            $table->integer('inventory_quantity')->nullable();

            $table->string('shopify_variant_gid')->nullable()->unique();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(
                ['product_id', 'option1_name', 'option1_value', 'option2_name', 'option2_value'],
                'product_variants_combination_unique',
            );
            $table->index(['product_id', 'position']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};

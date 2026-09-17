<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ficha de producto (RFC-0001).
     *
     * Los campos `shopify_*` existen desde el inicio para que la sincronización
     * de RFC-0004 sea idempotente, pero ninguna integración los rellena todavía.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('status', 32)->default(ProductStatus::Draft->value);

            $table->string('internal_reference', 64)->unique();
            $table->string('source_name');
            $table->string('brand')->nullable();
            $table->string('product_type', 64)->nullable();
            $table->string('audience', 32)->nullable();

            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->char('currency', 3)->default('EUR');

            $table->text('composition')->nullable();
            $table->string('fit')->nullable();
            $table->text('care_instructions')->nullable();
            $table->string('collection_context')->nullable();
            $table->text('notes')->nullable();

            $table->string('shopify_product_gid')->nullable()->unique();
            $table->string('shopify_handle')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('product_type');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

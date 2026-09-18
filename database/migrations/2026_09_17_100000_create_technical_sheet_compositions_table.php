<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de composiciones reutilizables (RFC-0008).
 *
 * Sustituye la escritura a mano de la composición en cada ficha por un mantenimiento
 * que una persona confirma una sola vez. product_type y udience nulos
 * significan «aplicable a cualquier ficha», que es lo habitual en un tejido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_sheet_compositions', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('product_type', 64)->nullable();
            $table->string('audience', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->text('content_text');

            $table->timestamps();

            // MySQL limita el nombre de un índice a 64 caracteres: el nombre
            // generado por convención lo superaba, así que se fija uno corto.
            $table->index(['is_active', 'product_type', 'audience'], 'technical_sheet_compositions_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_sheet_compositions');
    }
};

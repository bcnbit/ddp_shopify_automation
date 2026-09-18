<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimientos seleccionados y contexto para la IA (RFC-0008).
 *
 * Las cuatro claves ajenas son `nullOnDelete`: borrar un mantenimiento del
 * catálogo no puede romper una ficha. El texto que la ficha usa vive en
 * `product_technical_sheets`, así que una clave borrada sólo pierde el vínculo
 * con el maestro, no el contenido ya capturado.
 *
 * `composition`, `fit` y `care_instructions` NO se retiran: siguen siendo el
 * respaldo de las fichas creadas antes de esta enmienda (ver RFC-0008 §5.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('technical_sheet_composition_id')
                ->nullable()
                ->after('care_instructions')
                ->constrained('technical_sheet_compositions')
                ->nullOnDelete();

            $table->foreignId('technical_sheet_fit_id')
                ->nullable()
                ->after('technical_sheet_composition_id')
                ->constrained('technical_sheet_fits')
                ->nullOnDelete();

            $table->foreignId('technical_sheet_care_id')
                ->nullable()
                ->after('technical_sheet_fit_id')
                ->constrained('technical_sheet_cares')
                ->nullOnDelete();

            $table->foreignId('technical_sheet_size_guide_id')
                ->nullable()
                ->after('technical_sheet_care_id')
                ->constrained('technical_sheet_size_guides')
                ->nullOnDelete();

            $table->text('ai_base_description')
                ->nullable()
                ->after('technical_sheet_size_guide_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_sheet_composition_id');
            $table->dropConstrainedForeignId('technical_sheet_fit_id');
            $table->dropConstrainedForeignId('technical_sheet_care_id');
            $table->dropConstrainedForeignId('technical_sheet_size_guide_id');
            $table->dropColumn('ai_base_description');
        });
    }
};

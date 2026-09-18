<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetEntry;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;

/**
 * Acceso al catálogo de mantenimientos (RFC-0008).
 *
 * Reúne las dos preguntas que hace el formulario de la ficha —«qué puedo
 * ofrecer en este selector» y «qué contenido corresponde a lo seleccionado»— en
 * un solo sitio, para que el formulario y su previsualización no puedan
 * discrepar sobre qué es elegible.
 */
class TechnicalSheetCatalog
{
    /**
     * Mantenimientos ofrecibles en un selector.
     *
     * Sólo los activos y aplicables al tipo y al público de la ficha. La
     * selección actual se incluye siempre, aunque se haya desactivado o ya no
     * coincida: si desapareciera de la lista, el guardado automático borraría la
     * selección sin que nadie lo hubiera pedido.
     *
     * @return array<int, string>
     */
    public function options(string $slot, ?ProductType $type, ?Audience $audience, ?int $includeId = null): array
    {
        $model = $this->modelFor($slot);

        if ($model === null) {
            return [];
        }

        return $model::query()
            ->applicableTo($type, $audience, $includeId)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (TechnicalSheetEntry $entry): array => [
                (int) $entry->getKey() => $entry->selectionLabel(),
            ])
            ->all();
    }

    /**
     * Copia al vuelo del mantenimiento indicado, para la previsualización.
     *
     * Devuelve una copia sin guardar con la misma forma que una copia congelada,
     * de modo que el compositor no necesite dos caminos. Si el mantenimiento no
     * existe, devuelve `null` y el bloque simplemente no se previsualiza.
     */
    public function previewSelection(string $slot, ?int $entryId): ?ProductTechnicalSheet
    {
        if ($entryId === null) {
            return null;
        }

        $model = $this->modelFor($slot);

        if ($model === null) {
            return null;
        }

        $entry = $model::query()->find($entryId);

        if ($entry === null) {
            return null;
        }

        $snapshot = new ProductTechnicalSheet([
            'slot' => $slot,
            'entry_id' => $entry->getKey(),
            'entry_code' => (string) $entry->code,
            'entry_name' => (string) $entry->name,
            'entry_version' => (int) $entry->version,
        ]);

        if ($entry instanceof TechnicalSheetSizeGuide) {
            $snapshot->content_html = $entry->content_html;
            $snapshot->intro_note = $entry->intro_note;
            $snapshot->closing_note = $entry->closing_note;
        } else {
            $snapshot->content_text = $entry->content_text;
        }

        return $snapshot;
    }

    /**
     * Nombre de un mantenimiento concreto, aunque ya no sea elegible.
     *
     * Filament lo necesita para pintar el valor seleccionado cuando el
     * mantenimiento se desactivó o dejó de coincidir con el tipo de prenda: sin
     * esto, el selector mostraría el identificador numérico.
     */
    public function labelFor(string $slot, mixed $entryId): ?string
    {
        if (! is_numeric($entryId)) {
            return null;
        }

        $model = $this->modelFor($slot);

        if ($model === null) {
            return null;
        }

        $entry = $model::query()->find((int) $entryId);

        return $entry?->selectionLabel();
    }

    /**
     * Etiqueta de la versión de un mantenimiento, para mostrarla en el selector.
     */
    public function versionLabel(string $slot, ?int $entryId): ?string
    {
        if ($entryId === null) {
            return null;
        }

        $model = $this->modelFor($slot);

        if ($model === null) {
            return null;
        }

        $entry = $model::query()->find($entryId);

        return $entry === null ? null : "v{$entry->version}";
    }

    /**
     * Tipo y público con los que filtrar, a partir del estado del formulario.
     *
     * Acepta el valor crudo del formulario porque puede llegar como cadena,
     * como enum o vacío, y el formulario no debe tener que traducirlo.
     */
    public function typeFrom(mixed $value): ?ProductType
    {
        if ($value instanceof ProductType) {
            return $value;
        }

        return is_string($value) && $value !== '' ? ProductType::tryFrom($value) : null;
    }

    public function audienceFrom(mixed $value): ?Audience
    {
        if ($value instanceof Audience) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Audience::tryFrom($value) : null;
    }

    /**
     * Clave del modelo que corresponde a un hueco.
     *
     * @return class-string<TechnicalSheetEntry>|null
     */
    private function modelFor(string $slot): ?string
    {
        return match ($slot) {
            ProductTechnicalSheet::SLOT_COMPOSITION => TechnicalSheetComposition::class,
            ProductTechnicalSheet::SLOT_FIT => TechnicalSheetFit::class,
            ProductTechnicalSheet::SLOT_CARE => TechnicalSheetCare::class,
            ProductTechnicalSheet::SLOT_SIZE_GUIDE => TechnicalSheetSizeGuide::class,
            default => null,
        };
    }
}

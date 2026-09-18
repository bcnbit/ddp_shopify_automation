<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetEntry;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;

/**
 * Captura la copia congelada de los mantenimientos de una ficha (RFC-0008).
 *
 * Es la pieza que hace cierta la promesa central de la enmienda: **modificar un
 * mantenimiento no altera fichas ya creadas, aprobadas o sincronizadas**. Lo que
 * se envía a Shopify y a la IA sale de `product_technical_sheets`, no del
 * maestro.
 *
 * Se ejecuta dentro de la transacción que guarda la ficha, de modo que la clave
 * seleccionada y la copia nunca puedan quedar desincronizadas.
 *
 * Se capturan cuatro huecos y siempre los cuatro: si un hueco se vacía, su copia
 * se elimina. Dejar una copia huérfana haría que la ficha siguiera enviando una
 * composición que la persona ya había quitado de la pantalla.
 */
class TechnicalSheetSnapshotService
{
    /**
     * Pone las copias al día con las claves seleccionadas.
     */
    public function capture(Product $product): void
    {
        $selections = $product->technicalSheetSelectionIds();

        foreach (ProductTechnicalSheet::slots() as $slot) {
            $entryId = $selections[$slot] ?? null;
            $entry = $entryId === null ? null : $this->findEntry($slot, $entryId);

            if ($entry === null) {
                // Sin selección (o con una clave que ya no existe) la copia se
                // retira: la ficha deja de enviar ese bloque.
                $this->forget($product, $slot);

                continue;
            }

            $this->store($product, $slot, $entry);
        }
    }

    /**
     * Construye la copia de un hueco sin guardarla.
     *
     * Expuesto para poder afirmar en las pruebas qué se va a capturar sin
     * depender de la base de datos.
     */
    public function buildSnapshot(Product $product, string $slot, TechnicalSheetEntry $entry): ProductTechnicalSheet
    {
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
     * ¿El contenido guardado en el maestro es distinto del copiado?
     *
     * No se usa para enviar el maestro (nunca se envía un maestro), sino para
     * poder informar de que una ficha está usando una versión anterior.
     */
    public function isOutdated(ProductTechnicalSheet $snapshot): bool
    {
        $entry = $this->findEntry($snapshot->slot, (int) $snapshot->entry_id);

        if ($entry === null) {
            return false;
        }

        return (int) $entry->version !== (int) $snapshot->entry_version;
    }

    private function store(Product $product, string $slot, TechnicalSheetEntry $entry): void
    {
        $snapshot = $this->buildSnapshot($product, $slot, $entry);
        $snapshot->product_id = $product->getKey();
        $snapshot->captured_at = now();

        // Se escribe con `updateOrCreate` sobre la clave única (product, slot)
        // para que un guardado automático repetido no acumule copias.
        ProductTechnicalSheet::updateOrCreate(
            ['product_id' => $product->getKey(), 'slot' => $slot],
            $snapshot->getAttributes(),
        );
    }

    private function forget(Product $product, string $slot): void
    {
        ProductTechnicalSheet::query()
            ->where('product_id', $product->getKey())
            ->where('slot', $slot)
            ->delete();
    }

    private function findEntry(string $slot, int $id): ?TechnicalSheetEntry
    {
        /** @var class-string<TechnicalSheetEntry>|null $model */
        $model = match ($slot) {
            ProductTechnicalSheet::SLOT_COMPOSITION => TechnicalSheetComposition::class,
            ProductTechnicalSheet::SLOT_FIT => TechnicalSheetFit::class,
            ProductTechnicalSheet::SLOT_CARE => TechnicalSheetCare::class,
            ProductTechnicalSheet::SLOT_SIZE_GUIDE => TechnicalSheetSizeGuide::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        return $model::query()->find($id);
    }
}

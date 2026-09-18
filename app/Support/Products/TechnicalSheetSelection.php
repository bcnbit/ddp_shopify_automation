<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetEntry;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;

/**
 * Selección efectiva de mantenimientos de una ficha (RFC-0008).
 *
 * Resuelve cada hueco en este orden:
 *
 * 1. **La copia congelada** de la ficha. Es la fuente normal y la que garantiza
 *    que modificar un mantenimiento no altera una ficha ya creada.
 * 2. **El maestro** al que apunta la clave. Es una red de seguridad: si la copia
 *    no existe todavía (una ficha escrita por una vía que no capturó, o una
 *    captura que falló), es preferible usar el maestro que ignorar en silencio
 *    la selección que una persona ve en el formulario.
 * 3. **La columna libre** de la ficha, para las fichas anteriores a RFC-0008.
 *
 * Este orden importa y se prueba: con la copia presente, cambiar el maestro no
 * cambia lo que la ficha envía.
 */
final readonly class TechnicalSheetSelection
{
    private function __construct(
        public ?string $composition,
        public ?string $fit,
        public ?string $care,
        public ?ProductTechnicalSheet $sizeGuide,
        public ?string $legacyComposition,
        public ?string $legacyFit,
        public ?string $legacyCare,
        public ?string $aiBaseDescription,
    ) {}

    public static function forProduct(Product $product): self
    {
        return new self(
            composition: self::snapshotText($product, ProductTechnicalSheet::SLOT_COMPOSITION)
                ?? self::masterText($product->technicalSheetComposition),
            fit: self::snapshotText($product, ProductTechnicalSheet::SLOT_FIT)
                ?? self::masterText($product->technicalSheetFit),
            care: self::snapshotText($product, ProductTechnicalSheet::SLOT_CARE)
                ?? self::masterText($product->technicalSheetCare),
            sizeGuide: self::snapshot($product, ProductTechnicalSheet::SLOT_SIZE_GUIDE)
                ?? self::masterSnapshot($product->technicalSheetSizeGuide),
            legacyComposition: self::filled($product->composition),
            legacyFit: self::filled($product->fit),
            legacyCare: self::filled($product->care_instructions),
            aiBaseDescription: self::filled($product->ai_base_description),
        );
    }

    /**
     * Composición que puede mencionarse: el mantenimiento o, si no lo hay, el
     * dato libre de una ficha anterior a RFC-0008.
     */
    public function effectiveComposition(): ?string
    {
        return $this->composition ?? $this->legacyComposition;
    }

    public function effectiveFit(): ?string
    {
        return $this->fit ?? $this->legacyFit;
    }

    public function effectiveCare(): ?string
    {
        return $this->care ?? $this->legacyCare;
    }

    public function hasAnySelection(): bool
    {
        return $this->effectiveComposition() !== null
            || $this->effectiveFit() !== null
            || $this->effectiveCare() !== null
            || $this->sizeGuide !== null;
    }

    /**
     * ¿La composición viene de un mantenimiento y no de una columna libre?
     *
     * Se usa para distinguir «dato confirmado en el catálogo» de «dato suelto».
     */
    public function compositionIsMaintained(): bool
    {
        return $this->composition !== null;
    }

    private static function snapshot(Product $product, string $slot): ?ProductTechnicalSheet
    {
        return $product->technicalSheet($slot);
    }

    private static function snapshotText(Product $product, string $slot): ?string
    {
        return self::filled(self::snapshot($product, $slot)?->content_text);
    }

    private static function masterText(?TechnicalSheetEntry $entry): ?string
    {
        if (! $entry instanceof TechnicalSheetComposition
            && ! $entry instanceof TechnicalSheetFit
            && ! $entry instanceof TechnicalSheetCare) {
            return null;
        }

        return self::filled($entry->content_text);
    }

    /**
     * Convierte un maestro en una copia al vuelo, para el caso en que la ficha
     * tiene la clave pero todavía no tiene copia capturada.
     */
    private static function masterSnapshot(?TechnicalSheetSizeGuide $guide): ?ProductTechnicalSheet
    {
        if ($guide === null) {
            return null;
        }

        return new ProductTechnicalSheet([
            'slot' => ProductTechnicalSheet::SLOT_SIZE_GUIDE,
            'entry_id' => $guide->getKey(),
            'entry_code' => $guide->code,
            'entry_name' => $guide->name,
            'entry_version' => $guide->version,
            'content_html' => $guide->content_html,
            'intro_note' => $guide->intro_note,
            'closing_note' => $guide->closing_note,
        ]);
    }

    private static function filled(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Product;
use App\Models\ProductTechnicalSheet;
use App\Support\Products\TechnicalSheetSelection;

/**
 * Datos confirmados que se pueden enviar a la IA (RFC-0003, ampliado por RFC-0008).
 *
 * El contrato de entrada es explícito y restrictivo: se construye a partir de la
 * ficha e **incluye sólo** campos que una persona ha confirmado. Lo que no está
 * aquí, la IA no puede afirmarlo; y si lo afirma, el validador de prohibiciones
 * lo detecta antes de guardar.
 *
 * Los campos vacíos se omiten en lugar de enviarse como nulos, para que el
 * modelo no los interprete como «dato disponible».
 *
 * RFC-0008 añadió dos entradas: la **copia congelada** de los mantenimientos de
 * ficha técnica (que tiene prioridad sobre las columnas libres, para que editar
 * un mantenimiento no cambie lo que se envía) y la **descripción base para IA**,
 * que es contexto comercial y no un dato publicable.
 */
final readonly class ProductFactSheet
{
    /**
     * @param  list<array{color: string, size: string, sku: string}>  $variants
     */
    private function __construct(
        public string $internalReference,
        public string $name,
        public ?string $brand,
        public ?string $productType,
        public ?string $audience,
        public ?string $price,
        public ?string $composition,
        public ?string $fit,
        public ?string $careInstructions,
        public ?string $collectionContext,
        public array $variants,
        public ?string $sizeGuide = null,
        public ?string $aiBaseDescription = null,
        public bool $compositionIsMaintained = false,
    ) {}

    public static function fromProduct(Product $product): self
    {
        $variants = $product->variants->map(static fn ($variant): array => [
            'color' => (string) $variant->option1_value,
            'size' => (string) $variant->option2_value,
            'sku' => (string) $variant->sku,
        ])->all();

        $technicalSheets = TechnicalSheetSelection::forProduct($product);

        return new self(
            internalReference: (string) $product->internal_reference,
            name: (string) $product->source_name,
            brand: self::filled($product->brand),
            productType: $product->product_type?->value,
            audience: $product->audience?->value,
            price: $product->price !== null ? (string) $product->price : null,
            composition: $technicalSheets->effectiveComposition(),
            fit: $technicalSheets->effectiveFit(),
            careInstructions: $technicalSheets->effectiveCare(),
            collectionContext: self::filled($product->collection_context),
            variants: $variants,
            sizeGuide: self::sizeGuideText($technicalSheets->sizeGuide),
            aiBaseDescription: $technicalSheets->aiBaseDescription,
            compositionIsMaintained: $technicalSheets->compositionIsMaintained(),
        );
    }

    /**
     * Representación enviable al modelo: sólo los datos presentes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $facts = array_filter([
            'referencia_interna' => $this->internalReference,
            'nombre_provisional' => $this->name,
            'marca' => $this->brand,
            'tipo_de_prenda' => $this->productType,
            'publico' => $this->audience,
            'precio_iva_incluido' => $this->price,
            'composicion' => $this->composition,
            'ajuste' => $this->fit,
            'cuidados' => $this->careInstructions,
            'coleccion' => $this->collectionContext,
            'guia_de_tallas' => $this->sizeGuide,
            'descripcion_base_para_ia' => $this->aiBaseDescription,
        ], static fn ($value): bool => $value !== null && $value !== '');

        if ($this->variants !== []) {
            $facts['variantes'] = $this->variants;
        }

        return $facts;
    }

    /**
     * Datos que NO están confirmados, para enviarlos como prohibiciones explícitas.
     *
     * Es más fiable decirle al modelo «no menciones la composición, porque no
     * consta» que confiar en que no la invente.
     *
     * @return list<string>
     */
    public function missingFacts(): array
    {
        $missing = [];

        $checks = [
            'composición' => $this->composition,
            'ajuste o tallaje' => $this->fit,
            'cuidados' => $this->careInstructions,
            'público objetivo' => $this->audience,
            'colección o campaña' => $this->collectionContext,
        ];

        foreach ($checks as $label => $value) {
            if ($value === null || $value === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function hasComposition(): bool
    {
        return $this->composition !== null && $this->composition !== '';
    }

    public function hasSizeGuide(): bool
    {
        return $this->sizeGuide !== null && $this->sizeGuide !== '';
    }

    public function hasAiBaseDescription(): bool
    {
        return $this->aiBaseDescription !== null && $this->aiBaseDescription !== '';
    }

    /**
     * La guía de tallas viaja como texto plano: el modelo no necesita el marcado
     * de la tabla para respetar el dato, y el HTML no aporta nada a la redacción.
     */
    private static function sizeGuideText(?ProductTechnicalSheet $guide): ?string
    {
        if ($guide === null) {
            return null;
        }

        $parts = array_filter([
            self::filled($guide->intro_note),
            self::filled(strip_tags((string) $guide->content_html)),
            self::filled($guide->closing_note),
        ], static fn (?string $part): bool => $part !== null);

        if ($parts === []) {
            return null;
        }

        // Las celdas de una tabla se pegan sin separador al quitar las etiquetas,
        // así que se normalizan los espacios para que el texto sea legible.
        $text = preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? implode(' ', $parts);

        return self::filled($text);
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

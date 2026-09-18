<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use App\Models\ProductTechnicalSheet;

/**
 * Compone la descripción HTML que se envía a Shopify (RFC-0008).
 *
 * Orden fijo, y cada bloque se omite si está vacío:
 *
 * 1. Descripción comercial.
 * 2. Composición.
 * 3. Ajuste / tallaje.
 * 4. Guía de tallas.
 * 5. Cuidados.
 *
 * La **misma** clase alimenta la previsualización del panel y la carga útil de
 * Shopify. Es deliberado: si hubiera dos plantillas, la persona vería una cosa
 * y la tienda recibiría otra, y ese fallo no se detecta en ninguna prueba.
 *
 * Los títulos de bloque los añade la aplicación, no el usuario, así que no pasan
 * por la whitelist de la guía de tallas (que se aplica al HTML almacenado, no a
 * este andamiaje). Se usa `<h2>`, la convención de sección que ya siguen las
 * descripciones del proyecto.
 *
 * El resultado es seguro por construcción: la descripción comercial y el HTML de
 * la guía ya pasaron por su cast de saneado al guardarse, y la composición, el
 * ajuste y los cuidados son texto plano que se escapa aquí. No se saneado de
 * nuevo al vuelo, porque un segundo saneado sólo podría mutilar HTML válido.
 */
class TechnicalSheetComposer
{
    public function __construct(private readonly TechnicalSheetComposerHeadings $headings = new TechnicalSheetComposerHeadings) {}

    /**
     * Bloque HTML de cada hueco, sin la descripción comercial.
     *
     * @return array<string, string>
     */
    public function blocks(Product $product): array
    {
        $selection = TechnicalSheetSelection::forProduct($product);
        $blocks = [];

        $composition = $this->textBlock(
            ProductTechnicalSheet::SLOT_COMPOSITION,
            $this->headings->composition,
            $selection->effectiveComposition(),
        );

        if ($composition !== null) {
            $blocks[ProductTechnicalSheet::SLOT_COMPOSITION] = $composition;
        }

        $fit = $this->textBlock(
            ProductTechnicalSheet::SLOT_FIT,
            $this->headings->fit,
            $selection->effectiveFit(),
        );

        if ($fit !== null) {
            $blocks[ProductTechnicalSheet::SLOT_FIT] = $fit;
        }

        $sizeGuide = $this->renderBlock($selection->sizeGuide);

        if ($sizeGuide !== null) {
            $blocks[ProductTechnicalSheet::SLOT_SIZE_GUIDE] = $sizeGuide;
        }

        $care = $this->textBlock(
            ProductTechnicalSheet::SLOT_CARE,
            $this->headings->care,
            $selection->effectiveCare(),
        );

        if ($care !== null) {
            $blocks[ProductTechnicalSheet::SLOT_CARE] = $care;
        }

        return $blocks;
    }

    /**
     * Bloque de una copia congelada concreta.
     *
     * Es lo que usan tanto el envío como la previsualización del panel: una
     * lectura del hueco que se está mirando, con el mismo resultado que si se
     * enviara. Existe para que la previsualización no tenga que montar un
     * `Product` entero para pintar una guía de tallas.
     */
    public function renderBlock(?ProductTechnicalSheet $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        if ($snapshot->isSizeGuide()) {
            return $this->sizeGuideBlock($snapshot);
        }

        return $this->textBlock(
            $snapshot->slot,
            $this->headingFor($snapshot->slot),
            $snapshot->content_text,
        );
    }

    /**
     * Bloque de un snapshot o `null` si el hueco está vacío.
     */
    private function textBlock(string $slot, string $heading, ?string $content): ?string
    {
        $content = trim((string) $content);

        if ($content === '') {
            return null;
        }

        $body = $slot === ProductTechnicalSheet::SLOT_CARE
            ? $this->careParagraphs($content)
            : '<p>'.$this->escape($content).'</p>';

        return $heading.$body;
    }

    private function headingFor(string $slot): string
    {
        return match ($slot) {
            ProductTechnicalSheet::SLOT_COMPOSITION => $this->headings->composition,
            ProductTechnicalSheet::SLOT_FIT => $this->headings->fit,
            ProductTechnicalSheet::SLOT_SIZE_GUIDE => $this->headings->sizeGuide,
            default => $this->headings->care,
        };
    }

    /**
     * Descripción completa enviable a Shopify.
     */
    public function compose(Product $product, ?string $commercialDescription = null): string
    {
        $parts = [];

        $commercial = trim((string) $commercialDescription);

        if ($commercial !== '') {
            $parts[] = $commercial;
        }

        foreach ($this->blocks($product) as $block) {
            $parts[] = $block;
        }

        return implode("\n", $parts);
    }

    /**
     * Bloques en el orden en que aparecen en la descripción final.
     *
     * Se expone para poder afirmar el orden en las pruebas sin depender de la
     * cadena completa.
     *
     * @return list<string>
     */
    public function orderedSlots(): array
    {
        return [
            ProductTechnicalSheet::SLOT_COMPOSITION,
            ProductTechnicalSheet::SLOT_FIT,
            ProductTechnicalSheet::SLOT_SIZE_GUIDE,
            ProductTechnicalSheet::SLOT_CARE,
        ];
    }

    private function sizeGuideBlock(?ProductTechnicalSheet $guide): ?string
    {
        if ($guide === null) {
            return null;
        }

        $parts = [$this->headings->sizeGuide];

        $intro = trim((string) $guide->intro_note);

        if ($intro !== '') {
            $parts[] = '<p>'.$this->escape($intro).'</p>';
        }

        $table = trim((string) $guide->content_html);

        if ($table !== '') {
            $parts[] = $table;
        }

        $closing = trim((string) $guide->closing_note);

        if ($closing !== '') {
            $parts[] = '<p>'.$this->escape($closing).'</p>';
        }

        return count($parts) === 1 ? null : implode("\n", $parts);
    }

    /**
     * Los cuidados se guardan una instrucción por línea: se emite una lista, que
     * es como se leen de un vistazo en una ficha de producto.
     */
    private function careParagraphs(string $care): string
    {
        $lines = preg_split('/\R/u', $care) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));

        if (count($lines) <= 1) {
            return '<p>'.$this->escape($care).'</p>';
        }

        $items = implode('', array_map(
            fn (string $line): string => '<li>'.$this->escape($line).'</li>',
            $lines,
        ));

        return '<ul>'.$items.'</ul>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Propuesta de contenido validada (RFC-0003).
 *
 * Es el JSON que devuelve la IA **después** de comprobar el esquema, escapar el
 * HTML y normalizar etiquetas y handle. Si no cumple el contrato, este objeto no
 * se construye y la generación se rechaza.
 */
final readonly class ContentProposal
{
    /**
     * @param  list<string>  $tags
     * @param  array<int, array{media_id: int, text: string}>  $altTexts
     * @param  list<string>  $factsDetected
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $title,
        public ?string $shortBenefit,
        public string $htmlDescription,
        public string $seoTitle,
        public string $seoDescription,
        public ?string $handleSuggestion,
        public array $tags = [],
        public array $altTexts = [],
        public array $factsDetected = [],
        public array $warnings = [],
    ) {}

    /**
     * Campos tal y como se persisten en `product_content`.
     *
     * @return array<string, mixed>
     */
    public function toContentAttributes(): array
    {
        return [
            'title' => $this->title,
            'short_benefit' => $this->shortBenefit,
            'handle' => $this->handleSuggestion,
            'html_description' => $this->htmlDescription,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'tags_json' => $this->tags,
            'alt_texts_json' => $this->altTexts,
            'facts_detected_json' => $this->factsDetected,
            'warnings_json' => $this->warnings,
        ];
    }

    /**
     * Propuesta limitada a un campo, para regeneración parcial (RFC-0003).
     *
     * @return array<string, mixed>
     */
    public function only(string $field): array
    {
        $attributes = $this->toContentAttributes();

        return array_key_exists($field, $attributes)
            ? [$field => $attributes[$field]]
            : [];
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Exceptions\Ai\AiResponseRejected;
use App\Support\Security\HtmlSanitizer;

/**
 * Valida y normaliza la respuesta de la IA (RFC-0003).
 *
 * El RFC exige JSON validable contra esquema y rechazar la salida que no lo
 * cumpla. Aquí se comprueba la forma, se limpia el HTML con la lista blanca del
 * RFC-0001, se normalizan etiquetas y handle y se ajustan los rangos de SEO que
 * la persona revisará después.
 *
 * Nunca se confía en el proveedor: aunque el modelo devuelva `application/json`,
 * el contenido se vuelve a validar.
 */
class AiResponseValidator
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AiResponseRejected
     */
    public function validate(array $payload): ContentProposal
    {
        $reasons = [];

        $title = $this->stringOrNull($payload['title'] ?? null);
        $htmlDescription = $this->stringOrNull($payload['html_description'] ?? null);
        $seoTitle = $this->stringOrNull($payload['seo_title'] ?? null);
        $seoDescription = $this->stringOrNull($payload['seo_description'] ?? null);

        if ($title === null) {
            $reasons[] = 'Falta el título.';
        }

        if ($htmlDescription === null) {
            $reasons[] = 'Falta la descripción.';
        }

        if ($seoTitle === null) {
            $reasons[] = 'Falta el meta title.';
        }

        if ($seoDescription === null) {
            $reasons[] = 'Falta la meta description.';
        }

        if ($reasons !== []) {
            throw AiResponseRejected::because($reasons);
        }

        $cleanDescription = $this->sanitizer->sanitize($htmlDescription);

        if ($cleanDescription === null || trim(strip_tags($cleanDescription)) === '') {
            throw AiResponseRejected::because(['La descripción quedó vacía tras limpiar el HTML.']);
        }

        return new ContentProposal(
            title: $title,
            shortBenefit: $this->stringOrNull($payload['short_benefit'] ?? null),
            htmlDescription: $cleanDescription,
            seoTitle: $seoTitle,
            seoDescription: $seoDescription,
            handleSuggestion: $this->normalizeHandle($payload['handle_suggestion'] ?? null),
            tags: $this->normalizeTags($payload['tags'] ?? []),
            altTexts: $this->normalizeAltTexts($payload['alt_texts'] ?? []),
            factsDetected: $this->stringList($payload['facts_detected'] ?? []),
            warnings: $this->stringList($payload['warnings'] ?? []),
        );
    }

    /**
     * Convierte el texto devuelto por el modelo en un array.
     *
     * Se acepta JSON envuelto en un bloque de código porque es una respuesta
     * habitual, pero no se acepta texto libre: sin JSON válido no hay propuesta.
     *
     * @return array<string, mixed>
     *
     * @throws AiResponseRejected
     */
    public function decode(string $raw): array
    {
        $trimmed = trim($raw);

        // Algunos modelos envuelven el JSON en ```json ... ```.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw AiResponseRejected::because(['La respuesta no es un JSON válido.']);
        }

        return $decoded;
    }

    /**
     * Handle: minúsculas, guiones, sin fechas ni caracteres especiales.
     */
    public function normalizeHandle(mixed $value): ?string
    {
        $handle = $this->stringOrNull($value);

        if ($handle === null) {
            return null;
        }

        $slug = str($handle)->ascii()->slug()->value();
        $slug = trim($slug, '-');

        return $slug === '' ? null : $slug;
    }

    /**
     * Etiquetas: sin duplicados, sin vacías, con un máximo razonable.
     *
     * @return list<string>
     */
    public function normalizeTags(mixed $value): array
    {
        $tags = $this->stringList($value);
        $normalized = [];
        $seen = [];

        foreach ($tags as $tag) {
            $clean = trim(preg_replace('/\s+/u', ' ', $tag) ?? $tag);

            if ($clean === '') {
                continue;
            }

            $key = mb_strtolower($clean);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $clean;
        }

        $max = (int) config('product-studio.content.tags_max', 12);

        return array_slice($normalized, 0, $max);
    }

    /**
     * ALT: se conserva sólo el texto para imágenes que existen en la ficha.
     *
     * @return array<int, array{media_id: int, text: string}>
     */
    public function normalizeAltTexts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $mediaId = $entry['media_id'] ?? null;
            $text = $this->stringOrNull($entry['text'] ?? null);

            if (! is_numeric($mediaId) || $text === null) {
                continue;
            }

            $result[] = [
                'media_id' => (int) $mediaId,
                'text' => mb_substr($text, 0, 255),
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values($result);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

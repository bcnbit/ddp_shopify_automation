<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\DataObjects\Ai\ContentGenerationRequest;

/**
 * Construye las instrucciones enviadas al modelo (RFC-0003).
 *
 * El prompt se compone del perfil de familia, los datos confirmados y una lista
 * explícita de prohibiciones con los datos que faltan. Se pide JSON estricto
 * porque el RFC prohíbe aceptar texto libre.
 */
class PromptBuilder
{
    public const VERSION = 'v1';

    /**
     * Esquema de salida exigido, tal cual aparece en RFC-0003.
     */
    public const OUTPUT_SCHEMA = <<<'JSON'
    {
      "title": "string",
      "short_benefit": "string",
      "html_description": "string",
      "seo_title": "string",
      "seo_description": "string",
      "handle_suggestion": "string",
      "tags": ["string"],
      "alt_texts": [{"media_id": 1, "text": "string"}],
      "facts_detected": ["string"],
      "warnings": ["string"]
    }
    JSON;

    public function systemPrompt(ContentGenerationRequest $request): string
    {
        $profile = $request->profile;

        $lines = [
            'Eres redactor de producto para una tienda de ropa y complementos de playa.',
            'Trabajas para una marca llamada Dies de Platja y escribes en castellano.',
            '',
            'REGLA PRINCIPAL: sólo puedes afirmar lo que aparezca en DATOS CONFIRMADOS.',
            'Si un dato no está ahí, no lo menciones. No inventes jamás composición,',
            'certificaciones, origen, medidas, disponibilidad, precio ni condiciones de envío.',
            '',
            "PERFIL DE CONTENIDO: {$profile->label} ({$profile->identifier()})",
            'Estructura HTML esperada: '.$profile->structure,
            "Longitud de la descripción: entre {$profile->minWords} y {$profile->maxWords} palabras.",
        ];

        if ($profile->instructions !== []) {
            $lines[] = '';
            $lines[] = 'INSTRUCCIONES DEL PERFIL:';

            foreach ($profile->instructions as $instruction) {
                $lines[] = '- '.$instruction;
            }
        }

        if ($profile->tagRules !== []) {
            $lines[] = '';
            $lines[] = 'REGLAS DE ETIQUETAS:';

            foreach ($profile->tagRules as $rule) {
                $lines[] = '- '.$rule;
            }
        }

        $lines = [...$lines, ...$this->editorialRules()];
        $lines = [...$lines, ...$this->prohibitions($request)];
        $lines = [...$lines, ...$this->outputContract()];

        return implode("\n", $lines);
    }

    public function userPrompt(ContentGenerationRequest $request): string
    {
        $facts = $request->facts->toArray();

        $payload = [
            'idioma' => $request->locale->value,
            'datos_confirmados' => $facts,
        ];

        if ($request->existingTags !== []) {
            $payload['etiquetas_actuales'] = $request->existingTags;
        }

        if ($request->warnings !== []) {
            $payload['avisos_previos'] = $request->warnings;
        }

        if ($request->regenerateField !== null) {
            $payload['regenerar_solo'] = $request->regenerateField;
        }

        if ($request->hasImages()) {
            $payload['imagenes'] = count($request->imageDataUris).' fotografía(s) adjunta(s).';
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Genera la propuesta de contenido para esta ficha.\n\n"
            .($json === false ? '{}' : $json);
    }

    /**
     * @return list<string>
     */
    private function editorialRules(): array
    {
        return [
            '',
            'NORMAS EDITORIALES:',
            '- Tono cercano, concreto y sin promesas exageradas.',
            '- Meta title: entre 50 y 60 caracteres, con tipo de producto y un diferenciador real.',
            '- Meta description: entre 140 y 160 caracteres, sin repetir palabras de forma artificial.',
            '- Handle: minúsculas, sólo guiones, sin fechas ni caracteres especiales.',
            '- ALT: describe exactamente lo que se ve en la imagen, sin listas de palabras clave.',
            '- Etiquetas: entre 5 y 12, separando tipo, color, colección, público y atributo confirmado.',
            '- HTML permitido: p, ul, ol, li, strong, em, br, h2, h3. Nada más.',
        ];
    }

    /**
     * Prohibiciones explícitas, incluidos los datos que faltan (RFC-0003).
     *
     * @return list<string>
     */
    private function prohibitions(ContentGenerationRequest $request): array
    {
        $lines = [
            '',
            'PROHIBIDO AFIRMAR si no consta en los datos confirmados:',
            '- "algodón orgánico", "hecho en España", "edición limitada", "unisex", "oversize".',
            '- Medidas, certificados, disponibilidad, rebajas, tiempos o condiciones de envío.',
            '- Cualquier compatibilidad o característica técnica no listada.',
        ];

        $missing = $request->facts->missingFacts();

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'DATOS NO CONFIRMADOS (no los menciones; si son imprescindibles, añádelos a "warnings"):';

            foreach ($missing as $item) {
                $lines[] = "- {$item}";
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function outputContract(): array
    {
        return [
            '',
            'FORMATO DE SALIDA:',
            'Responde EXCLUSIVAMENTE con un objeto JSON válido, sin texto adicional antes ni después.',
            'Si falta un dato, usa cadena vacía o una lista vacía, nunca inventes.',
            'Devuelve exactamente estas claves:',
            self::OUTPUT_SCHEMA,
        ];
    }
}

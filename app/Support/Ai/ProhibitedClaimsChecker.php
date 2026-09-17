<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Detecta afirmaciones que la IA no puede hacer (RFC-0003).
 *
 * El RFC enumera lo que la IA tiene prohibido afirmar si no consta en los datos
 * confirmados: algodón orgánico, hecho en España, edición limitada, unisex,
 * oversize, medidas, certificados, disponibilidad, rebajas, tiempos de envío o
 * compatibilidades.
 *
 * Este comprobador es la red de seguridad: aunque el prompt lo prohíba, se
 * revisa el texto generado y, si aparece una afirmación no respaldada, se
 * convierte en una advertencia visible para la persona y se retira del contenido
 * aprobado. No bloquea la generación, porque un aviso debe llegar a la usuaria
 * en lugar de perderse.
 */
class ProhibitedClaimsChecker
{
    /**
     * Afirmaciones prohibidas y el dato confirmado que las respaldaría.
     *
     * Si el dato está presente en la ficha, la mención es legítima y no se
     * señala. Si no lo está, es una invención.
     *
     * @var array<string, array{patterns: list<string>, requires: string|null, label: string}>
     */
    private const CLAIMS = [
        'organic_cotton' => [
            'patterns' => [
                'algodón orgánico', 'algodón orgánica', 'algodón ecológico',
                'cotton orgánico', 'orgánico certificado', 'orgánica certificada',
            ],
            'requires' => 'composition',
            'label' => 'algodón orgánico',
        ],
        'made_in_spain' => [
            // Se contemplan las variantes de género («hecho/hecha», «confeccionado/
            // confeccionada»): el castellano concuerda con el sustantivo y una
            // lista sólo en masculino dejaría pasar la mitad de los casos.
            'patterns' => [
                'hecho en españa', 'hecha en españa',
                'fabricado en españa', 'fabricada en españa',
                'confeccionado en españa', 'confeccionada en españa',
                'elaborado en españa', 'elaborada en españa',
                'made in spain',
            ],
            'requires' => 'composition',
            'label' => 'origen de fabricación',
        ],
        'limited_edition' => [
            'patterns' => [
                'edición limitada', 'edicion limitada', 'ediciones limitadas',
                'limited edition', 'unidades limitadas',
            ],
            'requires' => null,
            'label' => 'edición limitada',
        ],
        'unisex' => [
            'patterns' => ['unisex'],
            'requires' => 'audience',
            'label' => 'unisex',
        ],
        'oversize' => [
            'patterns' => ['oversize', 'over size', 'talla grande holgada'],
            'requires' => 'fit',
            'label' => 'oversize',
        ],
        'certifications' => [
            'patterns' => [
                'certificado', 'certificada', 'certificación', 'certificacion',
                'oeko-tex', 'gots', 'sello sostenible',
            ],
            'requires' => 'composition',
            'label' => 'certificaciones',
        ],
        'availability' => [
            'patterns' => ['en stock', 'disponibilidad inmediata', 'últimas unidades', 'ultimas unidades', 'agotado'],
            'requires' => null,
            'label' => 'disponibilidad',
        ],
        'discounts' => [
            'patterns' => ['rebaja', 'descuento', 'oferta', 'precio rebajado', '% off'],
            'requires' => null,
            'label' => 'rebajas o descuentos',
        ],
        'shipping_times' => [
            'patterns' => ['envío en 24', 'envio en 24', 'entrega en 24', 'envío gratis', 'envio gratis', 'plazo de entrega'],
            'requires' => null,
            'label' => 'tiempos o condiciones de envío',
        ],
        'measurements' => [
            'patterns' => ['cm de ancho', 'cm de largo', 'centímetros', 'centimetros', 'talla exacta', 'medidas exactas'],
            'requires' => 'fit',
            'label' => 'medidas',
        ],
    ];

    /**
     * @return list<string> advertencias legibles
     */
    public function inspect(ContentProposal $proposal, ProductFactSheet $facts): array
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $proposal->title,
            $proposal->shortBenefit,
            strip_tags($proposal->htmlDescription),
            $proposal->seoTitle,
            $proposal->seoDescription,
            implode(' ', $proposal->tags),
        ])));

        $warnings = [];

        foreach (self::CLAIMS as $claim) {
            if (! $this->mentions($haystack, $claim['patterns'])) {
                continue;
            }

            // Si el dato que lo respaldaría está confirmado, la mención es válida.
            if ($claim['requires'] !== null && $this->factIsPresent($facts, $claim['requires'])) {
                continue;
            }

            $warnings[] = "La propuesta menciona {$claim['label']} sin que conste en los datos confirmados. "
                .'Revísalo antes de aprobar.';
        }

        return $warnings;
    }

    /**
     * ¿El texto afirma algo que no está respaldado?
     */
    public function hasProhibitedClaims(ContentProposal $proposal, ProductFactSheet $facts): bool
    {
        return $this->inspect($proposal, $facts) !== [];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function mentions(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($haystack, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function factIsPresent(ProductFactSheet $facts, string $fact): bool
    {
        $value = match ($fact) {
            'composition' => $facts->composition,
            'fit' => $facts->fit,
            'audience' => $facts->audience,
            default => null,
        };

        return $value !== null && $value !== '';
    }
}

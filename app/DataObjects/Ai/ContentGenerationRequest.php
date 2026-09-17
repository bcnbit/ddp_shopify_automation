<?php

declare(strict_types=1);

namespace App\DataObjects\Ai;

use App\Enums\Locale;
use App\Support\Ai\ContentProfile;
use App\Support\Ai\ProductFactSheet;

/**
 * Entrada a la IA (RFC-0003).
 *
 * Transporta **sólo** datos confirmados por una persona: el `ProductFactSheet`
 * decide qué se envía. Nunca incluye credenciales ni datos de clientes, y las
 * imágenes van redimensionadas (su preparación corresponde a RFC-0005).
 */
final readonly class ContentGenerationRequest
{
    /**
     * @param  list<string>  $imageDataUris  hasta cuatro fotos representativas, ya redimensionadas
     * @param  list<string>  $existingTags
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ProductFactSheet $facts,
        public ContentProfile $profile,
        public Locale $locale,
        public array $imageDataUris = [],
        public array $existingTags = [],
        public array $warnings = [],
        public ?string $regenerateField = null,
        public ?string $style = null,
    ) {}

    public function hasImages(): bool
    {
        return $this->imageDataUris !== [];
    }

    public function isPartialRegeneration(): bool
    {
        return $this->regenerateField !== null;
    }
}

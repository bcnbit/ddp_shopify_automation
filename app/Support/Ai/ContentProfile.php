<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ProductType;

final readonly class ContentProfile
{
    /**
     * @param  list<string>  $instructions
     * @param  list<string>  $tagRules
     * @param  list<string>  $faqPrompts
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $label,
        public string $structure,
        public array $instructions = [],
        public array $tagRules = [],
        public array $faqPrompts = [],
        public int $minWords = 120,
        public int $maxWords = 220,
    ) {}

    public function identifier(): string
    {
        return "{$this->key}@{$this->version}";
    }

    /**
     * Perfil correspondiente a una familia de producto.
     *
     * Si el tipo no está confirmado o no tiene perfil propio, se usa el genérico:
     * es preferible un perfil conservador a inventar instrucciones de familia.
     */
    public static function forProductType(?ProductType $type): self
    {
        return self::all()[$type?->value] ?? self::generic();
    }

    public static function forKey(string $key): self
    {
        return self::all()[$key] ?? self::generic();
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        return [
            ProductType::Tshirt->value => self::tshirt(),
            ProductType::Hoodie->value => self::hoodie(),
            ProductType::Bag->value => self::bag(),
            ProductType::Kids->value => self::kids(),
        ];
    }

    public static function generic(): self
    {
        return new self(
            key: 'generico',
            version: 'v2',
            label: 'Prenda estándar',
            structure: '<p>apertura comercial</p><p>diseño y uso</p><h2>Detalles confirmados</h2><p>información técnica, sólo si existe</p><h2>Cuidados</h2><p>cuidados, sólo si existen</p>',
            instructions: [
                'Escribe en castellano de España, con un tono cercano, claro y comercial, sin exageraciones.',
                'Abre con una frase concreta sobre el diseño, el mensaje, el estilo o el uso real del producto; evita expresiones genéricas.',
                'Explica después cómo encaja en un uso cotidiano o en una ocasión concreta sólo si ese uso está respaldado por los datos o la descripción base.',
                'Incluye encabezados técnicos y de cuidados únicamente cuando existan datos confirmados para completarlos.',
                'La descripción base aporta contexto creativo o comercial: úsala para orientar la redacción, pero no la copies literalmente ni conviertas suposiciones en características.',
                'No incluyas ninguna afirmación que no aparezca en los datos confirmados.',
                'No uses emojis, hashtags, llamadas a la acción agresivas ni repitas palabras clave de forma artificial.',
            ],
            tagRules: [
                'Genera entre 5 y 10 etiquetas útiles para filtros, colecciones y organización interna.',
                'Incluye tipo, color, colección, público y atributos sólo cuando estén confirmados.',
                'Usa etiquetas cortas, en minúsculas, sin hashtags y sin duplicados.',
                'No repitas sinónimos ni añadas etiquetas genéricas de relleno.',
            ],
            minWords: 70,
            maxWords: 140,
        );
    }

    private static function tshirt(): self
    {
        return new self(
            key: ProductType::Tshirt->value,
            version: 'v2',
            label: 'Camiseta',
            structure: '<p>apertura comercial</p><p>diseño y uso</p><h2>Composición y ajuste</h2><p>datos técnicos confirmados, sólo si existen</p><h2>Cuidados</h2><p>cuidados confirmados, sólo si existen</p>',
            instructions: [
                'Escribe en castellano de España, con tono cercano, concreto y comercial, sin exageraciones.',
                'Abre con una frase específica sobre el diseño, mensaje, estilo o uso de la camiseta; no empieces con "una camiseta imprescindible" ni fórmulas equivalentes.',
                'Describe el diseño de forma visual y explica cómo encaja en un look o uso cotidiano real.',
                'Incluye "Composición y ajuste" sólo cuando haya composición, ajuste o tallaje confirmados.',
                'Incluye "Cuidados" sólo cuando existan cuidados confirmados.',
                'Menciona composición, ajuste y tallaje sólo si constan en los datos confirmados o en el mantenimiento técnico seleccionado.',
                'No uses "unisex", "oversize", "algodón orgánico", "hecho en España" o expresiones equivalentes salvo que sean datos confirmados.',
                'No inventes gramaje, certificaciones, origen, tipo de estampado, medidas, disponibilidad ni plazo de envío.',
                'La descripción base puede orientar el tono y el contexto, pero no debe copiarse literalmente ni tratarse como dato técnico.',
                'No uses emojis, hashtags, llamadas a la acción agresivas ni repetición artificial de palabras clave.',
            ],
            tagRules: [
                'Genera entre 5 y 10 etiquetas útiles para filtros, colecciones y organización interna.',
                'Incluye "camiseta" y el público sólo si están confirmados.',
                'Incluye color, colección, estilo, temática o técnica de personalización sólo si están confirmados.',
                'Usa etiquetas cortas, en minúsculas, sin hashtags y sin duplicados.',
                'No añadas "regalo", "tendencia", "novedad", "calidad" o etiquetas genéricas de relleno.',
            ],
            minWords: 90,
            maxWords: 150,
        );
    }

    private static function hoodie(): self
    {
        return new self(
            key: ProductType::Hoodie->value,
            version: 'v2',
            label: 'Sudadera',
            structure: '<p>apertura comercial</p><p>diseño y uso</p><h2>Composición y ajuste</h2><p>datos técnicos confirmados, sólo si existen</p><h2>Cuidados</h2><p>cuidados confirmados, sólo si existen</p>',
            instructions: [
                'Escribe en castellano de España, con tono cercano, concreto y comercial, sin exageraciones.',
                'Abre con una frase específica sobre el diseño, mensaje, estilo o uso de la sudadera; evita fórmulas vacías como "un básico imprescindible".',
                'Describe el diseño y su uso real. Sólo destaca abrigo, suavidad o comodidad cuando esos atributos estén confirmados.',
                'Incluye "Composición y ajuste" sólo cuando haya composición, ajuste o tallaje confirmados.',
                'Incluye "Cuidados" sólo cuando existan cuidados confirmados.',
                'No afirmes gramaje, forro, felpa, composición, origen o certificaciones si no constan como datos confirmados.',
                'La descripción base puede orientar el tono y el contexto, pero no debe copiarse literalmente ni tratarse como dato técnico.',
                'No uses emojis, hashtags, llamadas a la acción agresivas ni repetición artificial de palabras clave.',
            ],
            tagRules: [
                'Genera entre 5 y 10 etiquetas útiles para filtros, colecciones y organización interna.',
                'Incluye "sudadera", color, colección, público, ajuste y atributo técnico sólo cuando estén confirmados.',
                'Usa etiquetas cortas, en minúsculas, sin hashtags y sin duplicados.',
                'Evita adjetivos promocionales y etiquetas genéricas que no describan el producto.',
            ],
            minWords: 90,
            maxWords: 160,
        );
    }

    private static function bag(): self
    {
        return new self(
            key: ProductType::Bag->value,
            version: 'v2',
            label: 'Bolso o neceser',
            structure: '<p>apertura comercial</p><p>diseño y uso</p><h2>Material y detalles</h2><p>material, medidas o características confirmadas, sólo si existen</p><h2>Cuidados</h2><p>cuidados confirmados, sólo si existen</p>',
            instructions: [
                'Escribe en castellano de España, describiendo uso y capacidad con honestidad.',
                'Abre con una frase específica sobre el diseño, la utilidad o el contexto de uso del bolso o neceser.',
                'Explica su uso cotidiano con precisión, sin sugerir capacidad, compartimentos o compatibilidades que no estén confirmados.',
                'Incluye "Material y detalles" sólo cuando existan material, medidas, cierre, forro u otras características confirmadas.',
                'Incluye "Cuidados" sólo cuando existan cuidados confirmados.',
                'No inventes medidas, cierres, forro, capacidad, impermeabilidad ni resistencia: menciona sólo lo confirmado.',
                'La descripción base puede orientar el tono y el contexto, pero no debe copiarse literalmente ni tratarse como dato técnico.',
                'No uses emojis, hashtags, llamadas a la acción agresivas ni repetición artificial de palabras clave.',
            ],
            tagRules: [
                'Genera entre 5 y 10 etiquetas útiles para filtros, colecciones y organización interna.',
                'Incluye tipo, material, color, colección, cierre o uso sólo cuando estén confirmados.',
                'Usa etiquetas cortas, en minúsculas, sin hashtags y sin duplicados.',
                'No uses etiquetas promocionales o genéricas como "regalo", "tendencia" o "calidad".',
            ],
            minWords: 80,
            maxWords: 140,
        );
    }

    private static function kids(): self
    {
        return new self(
            key: ProductType::Kids->value,
            version: 'v2',
            label: 'Prenda infantil',
            structure: '<p>apertura comercial</p><p>diseño y uso</p><h2>Composición y tallaje</h2><p>datos técnicos confirmados, sólo si existen</p><h2>Cuidados</h2><p>cuidados confirmados, sólo si existen</p>',
            instructions: [
                'Escribe en castellano de España, pensando en la persona adulta que compra para un niño o una niña.',
                'Abre con una frase específica sobre el diseño, mensaje, estilo o situación de uso de la prenda.',
                'Describe el producto de forma práctica y clara, sin infantilizar el lenguaje ni hacer promesas sobre comodidad o resistencia si no están confirmadas.',
                'Incluye "Composición y tallaje" sólo cuando haya composición, ajuste o tallaje confirmados.',
                'Incluye "Cuidados" sólo cuando existan cuidados confirmados.',
                'No afirmes seguridad, hipoalergenicidad, protección solar, sostenibilidad, certificados o resistencia si no constan como datos confirmados.',
                'No uses "unisex" salvo que sea un dato confirmado.',
                'La descripción base puede orientar el tono y el contexto, pero no debe copiarse literalmente ni tratarse como dato técnico.',
                'No uses emojis, hashtags, llamadas a la acción agresivas ni repetición artificial de palabras clave.',
            ],
            tagRules: [
                'Genera entre 5 y 10 etiquetas útiles para filtros, colecciones y organización interna.',
                'Incluye tipo, color, franja de edad, público y colección sólo cuando estén confirmados.',
                'Usa etiquetas cortas, en minúsculas, sin hashtags y sin duplicados.',
                'No añadas etiquetas de "seguridad", "ecológico", "orgánico" o similares sin dato confirmado.',
            ],
            minWords: 80,
            maxWords: 150,
        );
    }
}

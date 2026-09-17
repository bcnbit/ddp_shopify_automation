<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ProductType;

/**
 * Perfil de contenido versionado por familia de producto (RFC-0003).
 *
 * Aporta las instrucciones, la estructura HTML esperada y las reglas de tags.
 * La persona puede elegir el perfil, pero **no** editar los prompts desde el
 * flujo operativo: los prompts son código revisable y versionado.
 *
 * La versión forma parte de la identidad porque RFC-0003 exige guardar la
 * versión de prompt usada en cada generación.
 */
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
            version: 'v1',
            label: 'Prenda estándar',
            structure: '<p>beneficio</p><p>diseño y uso</p><h2>Cuidado</h2><p>cuidado</p>',
            instructions: [
                'Escribe en castellano, con tono cercano, concreto y sin promesas exageradas.',
                'Estructura: beneficio, diseño o uso, y cuidado.',
                'No incluyas ninguna afirmación que no aparezca en los datos confirmados.',
            ],
            tagRules: [
                'Entre 5 y 12 etiquetas.',
                'Separa tipo, color, colección, público y atributo confirmado.',
                'No repitas sinónimos ni añadas etiquetas genéricas de relleno.',
            ],
        );
    }

    private static function tshirt(): self
    {
        return new self(
            key: ProductType::Tshirt->value,
            version: 'v1',
            label: 'Camiseta',
            structure: '<p>beneficio</p><p>diseño y uso</p><h2>Tejido y tallaje</h2><p>tejido</p><h2>Cuidado</h2><p>cuidado</p>',
            instructions: [
                'Escribe en castellano, con tono cercano y concreto.',
                'Estructura: beneficio principal, diseño y uso cotidiano, tejido y tallaje, cuidado.',
                'Menciona el tejido y el tallaje SÓLO si constan en los datos confirmados.',
                'No uses "unisex" ni "oversize" salvo que aparezcan como dato confirmado.',
            ],
            tagRules: [
                'Entre 5 y 12 etiquetas.',
                'Incluye tipo (camiseta), color, colección y atributo confirmado.',
                'No añadas "regalo" ni "tendencia": no aportan búsqueda real.',
            ],
        );
    }

    private static function hoodie(): self
    {
        return new self(
            key: ProductType::Hoodie->value,
            version: 'v1',
            label: 'Sudadera',
            structure: '<p>beneficio</p><p>diseño y uso</p><h2>Tejido y tallaje</h2><p>tejido</p><h2>Cuidado</h2><p>cuidado</p>',
            instructions: [
                'Escribe en castellano, destacando abrigo y comodidad sin exagerar.',
                'Estructura: beneficio, diseño y uso, tejido y tallaje, cuidado.',
                'No afirmes gramaje, forro ni composición si no constan como dato confirmado.',
            ],
            tagRules: [
                'Entre 5 y 12 etiquetas.',
                'Incluye tipo (sudadera), color, colección y atributo confirmado.',
                'Evita adjetivos promocionales que no describan el producto.',
            ],
        );
    }

    private static function bag(): self
    {
        return new self(
            key: ProductType::Bag->value,
            version: 'v1',
            label: 'Bolso o neceser',
            structure: '<p>beneficio</p><p>diseño y uso</p><h2>Material y medidas</h2><p>material</p><h2>Cuidado</h2><p>cuidado</p>',
            instructions: [
                'Escribe en castellano, describiendo uso y capacidad con honestidad.',
                'Estructura: beneficio, diseño y uso, material y medidas, cuidado.',
                'No inventes medidas, cierres, forro ni capacidad: sólo lo confirmado.',
            ],
            tagRules: [
                'Entre 5 y 12 etiquetas.',
                'Incluye tipo (bolso o neceser), material confirmado, color y colección.',
            ],
        );
    }

    private static function kids(): self
    {
        return new self(
            key: ProductType::Kids->value,
            version: 'v1',
            label: 'Prenda infantil',
            structure: '<p>beneficio</p><p>diseño y uso</p><h2>Tejido y tallaje</h2><p>tejido</p><h2>Cuidado</h2><p>cuidado</p>',
            instructions: [
                'Escribe en castellano, pensando en quien compra para un niño o una niña.',
                'Estructura: beneficio, diseño y uso, tejido y tallaje, cuidado.',
                'No afirmes seguridad, hipoalergenicidad ni certificados si no constan.',
                'No uses "unisex" salvo que sea un dato confirmado.',
            ],
            tagRules: [
                'Entre 5 y 12 etiquetas.',
                'Incluye tipo, color, talla si consta y colección.',
                'No añadas etiquetas de "seguridad" ni "ecológico" sin dato confirmado.',
            ],
        );
    }
}

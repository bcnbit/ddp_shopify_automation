<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use Illuminate\Database\Seeder;

/**
 * Mantenimientos de ficha técnica de ejemplo (RFC-0008).
 *
 * Son datos reales de la marca, no de relleno: existen para que una ficha se
 * pueda montar sin volver a escribir la composición o los cuidados. El seeder es
 * idempotente (se apoya en el código único) para poder ejecutarse sobre una base
 * que ya los tenga.
 *
 * Se ejecuta en todos los entornos salvo pruebas: sin mantenimientos, los
 * selectores de la ficha quedarían vacíos y el flujo parecería roto.
 */
class TechnicalSheetMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->compositions();
        $this->fits();
        $this->cares();
        $this->sizeGuides();
    }

    private function compositions(): void
    {
        $entries = [
            [
                'code' => 'COMP-ALG-100',
                'name' => 'Algodón 100% (camiseta y sudadera)',
                'content_text' => '100% algodón',
            ],
            [
                'code' => 'COMP-ALG-PES-8020',
                'name' => 'Algodón 80% / poliéster 20%',
                'content_text' => '80% algodón / 20% poliéster',
            ],
            [
                'code' => 'COMP-ALG-ORG-100',
                'name' => 'Algodón orgánico 100% (sólo si consta certificado)',
                'content_text' => '100% algodón orgánico',
            ],
            [
                'code' => 'COMP-RAFIA-100',
                'name' => 'Rafia natural',
                'product_type' => ProductType::Bag,
                'content_text' => '100% rafia natural',
            ],
        ];

        foreach ($entries as $entry) {
            TechnicalSheetComposition::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name' => $entry['name'],
                    'product_type' => $entry['product_type'] ?? null,
                    'audience' => null,
                    'is_active' => true,
                    'content_text' => $entry['content_text'],
                ],
            );
        }
    }

    private function fits(): void
    {
        $entries = [
            [
                'code' => 'FIT-UNISEX-REG',
                'name' => 'Unisex regular',
                'audience' => Audience::Unisex,
                'content_text' => 'Corte regular unisex, con caída natural y hombro estándar.',
            ],
            [
                'code' => 'FIT-MUJER-ENT',
                'name' => 'Mujer entallada',
                'audience' => Audience::Woman,
                'content_text' => 'Corte entallado que marca la silueta sin apretar.',
            ],
            [
                'code' => 'FIT-OVERSIZE',
                'name' => 'Oversize',
                'content_text' => 'Corte amplio y holgado, con hombro caído y largo por debajo de la cadera.',
            ],
            [
                'code' => 'FIT-RECTO',
                'name' => 'Corte recto',
                'content_text' => 'Corte recto, sin entallar, de largo estándar.',
            ],
        ];

        foreach ($entries as $entry) {
            TechnicalSheetFit::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name' => $entry['name'],
                    'product_type' => null,
                    'audience' => $entry['audience'] ?? null,
                    'is_active' => true,
                    'content_text' => $entry['content_text'],
                ],
            );
        }
    }

    private function cares(): void
    {
        $entries = [
            [
                'code' => 'CARE-ALG-BASICO',
                'name' => 'Algodón — cuidados básicos',
                'content_text' => "Lavar del revés a un máximo de 30 ºC.\nNo usar secadora.\nPlanchar del revés y sin pasar sobre el estampado.",
            ],
            [
                'code' => 'CARE-ESTAMPADO',
                'name' => 'Prenda estampada',
                'content_text' => "Lavar del revés a un máximo de 30 ºC.\nNo usar secadora.\nNo planchar sobre el estampado.\nNo usar lejía.",
            ],
            [
                'code' => 'CARE-RAFIA',
                'name' => 'Rafia y fibras naturales',
                'content_text' => "Limpiar con un paño húmedo.\nNo sumergir en agua.\nGuardar en un lugar seco y protegido del sol directo.",
            ],
        ];

        foreach ($entries as $entry) {
            TechnicalSheetCare::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name' => $entry['name'],
                    'product_type' => null,
                    'audience' => null,
                    'is_active' => true,
                    'content_text' => $entry['content_text'],
                ],
            );
        }
    }

    private function sizeGuides(): void
    {
        $adultTshirt = $this->table(
            ['Talla', 'Pecho (cm)', 'Largo (cm)'],
            [
                ['XS', '90', '66'],
                ['S', '96', '68'],
                ['M', '102', '70'],
                ['L', '108', '72'],
                ['XL', '114', '74'],
            ],
        );

        $hoodie = $this->table(
            ['Talla', 'Pecho (cm)', 'Largo (cm)', 'Manga (cm)'],
            [
                ['S', '104', '68', '60'],
                ['M', '110', '70', '62'],
                ['L', '116', '72', '64'],
                ['XL', '122', '74', '66'],
            ],
        );

        $kids = $this->table(
            ['Edad', 'Altura (cm)', 'Pecho (cm)'],
            [
                ['2-3 años', '98', '54'],
                ['4-5 años', '110', '58'],
                ['6-7 años', '122', '62'],
                ['8-9 años', '134', '68'],
            ],
        );

        $entries = [
            [
                'code' => 'TALLA-CAMISETA-ADULTO',
                'name' => 'Guía de tallas — camiseta adulto',
                'product_type' => ProductType::Tshirt,
                'audience' => null,
                'content_html' => $adultTshirt,
                'intro_note' => 'Medidas tomadas en plano, en centímetros. Puede haber una variación de ±1 cm.',
                'closing_note' => 'Si dudas entre dos tallas, elige la mayor para un ajuste más holgado.',
            ],
            [
                'code' => 'TALLA-SUDADERA',
                'name' => 'Guía de tallas — sudadera',
                'product_type' => ProductType::Hoodie,
                'audience' => null,
                'content_html' => $hoodie,
                'intro_note' => 'Medidas tomadas en plano, en centímetros.',
                'closing_note' => 'Corte amplio: si buscas un ajuste ceñido, elige una talla menos.',
            ],
            [
                'code' => 'TALLA-INFANTIL',
                'name' => 'Guía de tallas — infantil',
                'product_type' => ProductType::Kids,
                'audience' => Audience::Kids,
                'content_html' => $kids,
                'intro_note' => 'La altura orientativa es la del niño o la niña, no la de la prenda.',
                'closing_note' => null,
            ],
        ];

        foreach ($entries as $entry) {
            TechnicalSheetSizeGuide::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name' => $entry['name'],
                    'product_type' => $entry['product_type'],
                    'audience' => $entry['audience'],
                    'is_active' => true,
                    'intro_note' => $entry['intro_note'],
                    'content_html' => $entry['content_html'],
                    'closing_note' => $entry['closing_note'],
                ],
            );
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        $head = implode('', array_map(
            static fn (string $header): string => "<th>{$header}</th>",
            $headers,
        ));

        $body = implode('', array_map(
            static fn (array $row): string => '<tr>'.implode('', array_map(
                static fn (string $cell): string => "<td>{$cell}</td>",
                $row,
            )).'</tr>',
            $rows,
        ));

        return "<table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>";
    }
}

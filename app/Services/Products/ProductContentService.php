<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\ActivityEvent;
use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Support\Audit\ActivityRecorder;
use App\Support\Products\SkuNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Versiones de contenido comercial de una ficha (RFC-0002).
 *
 * Reglas que protege este servicio:
 *
 * - Guardar cambios sobre una versión **no aprobada** la actualiza in situ.
 * - Guardar sobre una versión **aprobada** crea una versión nueva, de modo que
 *   aprobar no se pueda deshacer por accidente al editar un campo.
 * - «Restaurar propuesta» recupera la última versión aprobada como borrador
 *   nuevo, sin destruir nada.
 */
class ProductContentService
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * Guarda los campos editados en la versión vigente (o crea una nueva).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveProposal(Product $product, array $attributes, ?User $author, Locale $locale = Locale::Es): ProductContent
    {
        return DB::transaction(function () use ($product, $attributes, $author, $locale): ProductContent {
            $current = $product->contentFor($locale);

            if ($current === null || $current->isApproved()) {
                return $this->createVersion($product, $attributes, $author, $locale, $current);
            }

            $current->fill($attributes);
            $current->save();

            $this->recorder->record(
                ActivityEvent::ContentUpdated,
                $product,
                'Propuesta de contenido actualizada.',
                ['version' => $current->version],
                actor: $author,
            );

            return $current;
        });
    }

    /**
     * Crea la primera propuesta a partir de los datos ya confirmados.
     *
     * Este borrador NO usa IA (eso es RFC-0003) y no inventa ningún dato
     * comercial: sólo reutiliza lo que una persona ha confirmado. Sirve para
     * que el flujo de RFC-0002 sea completo y verificable sin depender del
     * proveedor de IA.
     */
    public function createLocalDraft(Product $product, ?User $author, Locale $locale = Locale::Es): ProductContent
    {
        $type = $product->product_type instanceof ProductType ? $product->product_type : null;

        $title = trim((string) $product->source_name);
        $brand = trim((string) $product->brand);

        if ($brand !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($brand))) {
            $title = $title === '' ? $brand : $title;
        }

        return $this->saveProposal($product, [
            'title' => $title !== '' ? $title : null,
            'short_benefit' => null,
            'handle' => $this->suggestHandle($product),
            'html_description' => null,
            'seo_title' => null,
            'seo_description' => null,
            'tags_json' => $this->suggestTags($product, $type),
            'alt_texts_json' => [],
            'facts_detected_json' => [],
            'warnings_json' => $this->localWarnings($product, $type),
        ], $author, $locale);
    }

    /**
     * Restaura la última versión aprobada como propuesta nueva (RFC-0002).
     *
     * No borra ni modifica la versión aprobada: la copia para poder seguir
     * iterando sin perder el punto de referencia validado por una persona.
     */
    public function restoreFromApproved(Product $product, ?User $author, Locale $locale = Locale::Es): ProductContent
    {
        $approved = $product->contents()
            ->where('locale', $locale->value)
            ->whereNotNull('approved_at')
            ->orderByDesc('version')
            ->first();

        if ($approved === null) {
            throw new RuntimeException('Todavía no hay ninguna propuesta aprobada que restaurar.');
        }

        $attributes = [
            'title' => $approved->title,
            'short_benefit' => $approved->short_benefit,
            'handle' => $approved->handle,
            'html_description' => $approved->html_description,
            'seo_title' => $approved->seo_title,
            'seo_description' => $approved->seo_description,
            'tags_json' => $approved->tags_json,
            'alt_texts_json' => $approved->alt_texts_json,
            'facts_detected_json' => $approved->facts_detected_json,
            'warnings_json' => $approved->warnings_json,
            'product_category_taxonomy_id' => $approved->product_category_taxonomy_id,
        ];

        return DB::transaction(function () use ($product, $attributes, $author, $locale, $approved): ProductContent {
            $restored = $this->createVersion($product, $attributes, $author, $locale, null, true);

            $this->recorder->record(
                ActivityEvent::ContentCreated,
                $product,
                "Propuesta restaurada desde la versión aprobada {$approved->version}.",
                ['from_version' => $approved->version, 'to_version' => $restored->version],
                actor: $author,
            );

            return $restored;
        });
    }

    /**
     * Aprueba una versión de contenido. Después no podrá editarse: se creará
     * otra versión si hace falta cambiar algo.
     */
    public function approve(ProductContent $content, User $approver): ProductContent
    {
        if (blank($content->title)) {
            throw new RuntimeException('No se puede aprobar contenido sin título.');
        }

        if (blank($content->html_description)) {
            throw new RuntimeException('No se puede aprobar contenido sin descripción.');
        }

        $content->approved_at = now();
        $content->approved_by = $approver->getKey();
        $content->save();

        $this->recorder->record(
            ActivityEvent::Approved,
            $content->product,
            "Contenido de la versión {$content->version} aprobado.",
            ['version' => $content->version],
            actor: $approver,
        );

        return $content;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createVersion(
        Product $product,
        array $attributes,
        ?User $author,
        Locale $locale,
        ?ProductContent $previous,
        bool $isRestore = false,
    ): ProductContent {
        $version = $product->latestContentVersion($locale) + 1;

        $content = new ProductContent($attributes);
        $content->product_id = $product->getKey();
        $content->locale = $locale;
        $content->version = $version;
        $content->save();

        $this->recorder->record(
            $isRestore ? ActivityEvent::ContentUpdated : ActivityEvent::ContentCreated,
            $product,
            "Nueva versión de contenido ({$version}) para {$locale->label()}.",
            ['version' => $version, 'previous_version' => $previous?->version],
            actor: $author,
        );

        return $content;
    }

    private function suggestHandle(Product $product): ?string
    {
        $base = $product->source_name !== null && $product->source_name !== ''
            ? $product->source_name
            : (string) SkuNormalizer::normalize($product->internal_reference);

        $handle = str($base)->slug()->value();

        return $handle === '' ? null : $handle;
    }

    /**
     * Etiquetas derivadas sólo de datos confirmados (tipo, público, color).
     *
     * @return list<string>
     */
    private function suggestTags(Product $product, ?ProductType $type): array
    {
        $tags = [];

        if ($type !== null) {
            $tags[] = $type->label();
        }

        if ($product->audience !== null) {
            $tags[] = $product->audience->label();
        }

        if ($product->brand !== null && $product->brand !== '') {
            $tags[] = $product->brand;
        }

        if ($product->collection_context !== null && $product->collection_context !== '') {
            $tags[] = $product->collection_context;
        }

        foreach ($product->variants as $variant) {
            if ($variant->option1_value !== '') {
                $tags[] = $variant->option1_value;
            }
        }

        return array_values(array_unique(array_filter($tags)));
    }

    /**
     * Avisos locales: datos que faltan, sin afirmar nada sobre el producto.
     *
     * @return list<string>
     */
    private function localWarnings(Product $product, ?ProductType $type): array
    {
        $warnings = [];

        if (blank($product->composition)) {
            $warnings[] = 'Composición pendiente de confirmar por una persona.';
        }

        if (blank($product->care_instructions)) {
            $warnings[] = 'Cuidados pendientes de confirmar.';
        }

        if (blank($product->fit)) {
            $warnings[] = 'Ajuste o tallaje pendiente de confirmar.';
        }

        if ($type === null) {
            $warnings[] = 'Tipo de prenda sin confirmar.';
        }

        if ($product->status !== ProductStatus::Draft && $product->media()->whereNull('alt_text')->exists()) {
            $warnings[] = 'Hay imágenes sin texto ALT.';
        }

        return $warnings;
    }
}

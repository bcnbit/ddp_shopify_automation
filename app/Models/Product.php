<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audience;
use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Services\Products\TechnicalSheetSnapshotService;
use App\Support\Products\SkuNormalizer;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ficha de producto (RFC-0001).
 *
 * La tabla local es la fuente de verdad; Shopify recibe una copia borrador (RFC-0004).
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Claves que enlazan la ficha con un mantenimiento (RFC-0008).
     *
     * Se declaran juntas porque se recorren en tres sitios —la captura, la
     * comprobación de cambios y el propio snapshot— y una lista duplicada sería
     * la forma más fácil de que una clave nueva se quedara sin copia.
     *
     * @var list<string>
     */
    private const TECHNICAL_SHEET_SELECTION_ATTRIBUTES = [
        'technical_sheet_composition_id',
        'technical_sheet_fit_id',
        'technical_sheet_care_id',
        'technical_sheet_size_guide_id',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'internal_reference',
        'source_name',
        'brand',
        'product_type',
        'audience',
        'price',
        'compare_at_price',
        'currency',
        'composition',
        'fit',
        'care_instructions',
        'technical_sheet_composition_id',
        'technical_sheet_fit_id',
        'technical_sheet_care_id',
        'technical_sheet_size_guide_id',
        'ai_base_description',
        'collection_context',
        'notes',
        'shopify_product_gid',
        'shopify_handle',
        'last_synced_at',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'product_type' => ProductType::class,
            'audience' => Audience::class,
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'last_synced_at' => 'datetime',
            'approved_at' => 'datetime',
            'technical_sheet_composition_id' => 'integer',
            'technical_sheet_fit_id' => 'integer',
            'technical_sheet_care_id' => 'integer',
            'technical_sheet_size_guide_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if ($product->internal_reference !== null) {
                $product->internal_reference = SkuNormalizer::normalize($product->internal_reference);
            }
        });

        /*
         * Copia congelada de los mantenimientos (RFC-0008).
         *
         * Se engancha aquí, y no sólo en el servicio de fichas, porque la
         * garantía de la enmienda debe ser estructural: modificar un
         * mantenimiento no puede alterar una ficha ya creada. Si la captura
         * dependiera de pasar por un servicio concreto, cualquier otra vía
         * (seeders, fábricas, una futura importación) dejaría fichas sin copia y
         * esas fichas sí cambiarían al editar el maestro.
         *
         * Sólo se ejecuta cuando cambia alguna de las cuatro claves: un guardado
         * cualquiera no paga el coste.
         */
        // En un alta se captura siempre que haya alguna selección: una ficha
        // puede nacer con mantenimientos ya elegidos.
        static::created(function (self $product): void {
            if ($product->hasTechnicalSheetSelection()) {
                app(TechnicalSheetSnapshotService::class)->capture($product);
            }
        });

        // En una edición, sólo si cambió alguna de las cuatro claves: un guardado
        // cualquiera no paga el coste.
        //
        // Se usan `created`/`updated` y no `saved` a propósito. Con `saved` habría
        // que distinguir el alta a mano, y `wasRecentlyCreated` **sigue siendo
        // true durante toda la vida de la instancia** una vez insertada, así que
        // la segunda escritura de esa misma instancia se tomaría por un alta y la
        // retirada de un mantenimiento no se propagaría a la copia.
        static::updated(function (self $product): void {
            if (! $product->technicalSheetSelectionChanged()) {
                return;
            }

            app(TechnicalSheetSnapshotService::class)->capture($product);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Variantes vendibles de la ficha.
     *
     * `chaperone()` enlaza cada variante con su ficha al cargarla, de modo que
     * `ProductVariant::effectivePrice()` puede leer el precio de la ficha sin
     * provocar una carga diferida. Sin esto, validar una variante sin precio
     * propio fallaba con «Attempted to lazy load [product]» cuando las cargas
     * diferidas están desactivadas (entorno de desarrollo), y además añadía una
     * consulta por variante.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->orderBy('position')
            ->chaperone('product');
    }

    /**
     * Imágenes de la ficha.
     *
     * `chaperone('product')` devuelve cada imagen con su ficha ya enlazada. Es
     * imprescindible y no una optimización: las Policies autorizan por fila
     * (`$media->product`), y Filament carga las filas de la tabla de golpe. Sin
     * el enlace, cada comprobación de permiso intentaba una carga diferida y el
     * panel fallaba con «Attempted to lazy load [product]» en desarrollo.
     *
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)
            ->orderBy('sort_order')
            ->chaperone('product');
    }

    /**
     * Versiones de contenido de la ficha. `chaperone('product')` por el mismo
     * motivo que en `media()`: ProductContentPolicy autoriza por fila.
     *
     * @return HasMany<ProductContent, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(ProductContent::class)->chaperone('product');
    }

    /**
     * Intentos de sincronización. `chaperone('product')` por el mismo motivo que
     * en `media()`: SyncAttemptPolicy autoriza por fila y el panel de errores
     * las muestra en tabla.
     *
     * @return HasMany<SyncAttempt, $this>
     */
    public function syncAttempts(): HasMany
    {
        return $this->hasMany(SyncAttempt::class)->chaperone('product');
    }

    /**
     * Copias congeladas de los mantenimientos de ficha técnica (RFC-0008).
     *
     * `chaperone('product')` por el mismo motivo que en `media()`: la Policy del
     * snapshot autoriza por fila (`$sheet->product`) y Filament carga las filas
     * de golpe. Sin el enlace, cada comprobación de permiso intentaría una carga
     * diferida y el panel fallaría en desarrollo.
     *
     * @return HasMany<ProductTechnicalSheet, $this>
     */
    public function technicalSheets(): HasMany
    {
        return $this->hasMany(ProductTechnicalSheet::class)->chaperone('product');
    }

    /**
     * Mantenimiento de composición seleccionado (el maestro, no la copia).
     *
     * @return BelongsTo<TechnicalSheetComposition, $this>
     */
    public function technicalSheetComposition(): BelongsTo
    {
        return $this->belongsTo(TechnicalSheetComposition::class, 'technical_sheet_composition_id');
    }

    /** @return BelongsTo<TechnicalSheetFit, $this> */
    public function technicalSheetFit(): BelongsTo
    {
        return $this->belongsTo(TechnicalSheetFit::class, 'technical_sheet_fit_id');
    }

    /** @return BelongsTo<TechnicalSheetCare, $this> */
    public function technicalSheetCare(): BelongsTo
    {
        return $this->belongsTo(TechnicalSheetCare::class, 'technical_sheet_care_id');
    }

    /** @return BelongsTo<TechnicalSheetSizeGuide, $this> */
    public function technicalSheetSizeGuide(): BelongsTo
    {
        return $this->belongsTo(TechnicalSheetSizeGuide::class, 'technical_sheet_size_guide_id');
    }

    /**
     * Copia congelada de un hueco, sin provocar una carga diferida.
     *
     * Si la relación ya viene cargada se usa esa colección; si no, se consulta.
     * Es importante porque en desarrollo la carga diferida está prohibida y esta
     * consulta ocurre también en el envío a Shopify y en la generación con IA,
     * donde no hay nadie mirando la pantalla.
     */
    public function technicalSheet(string $slot): ?ProductTechnicalSheet
    {
        // Un modelo sin guardar no puede tener copias: se evita una consulta que
        // fallaría o devolvería algo de otra ficha.
        if (! $this->exists) {
            return null;
        }

        if ($this->relationLoaded('technicalSheets')) {
            return $this->technicalSheets->firstWhere('slot', $slot);
        }

        return $this->technicalSheets()->where('slot', $slot)->first();
    }

    /**
     * Mantenimiento maestro de un hueco, sin provocar una carga diferida.
     *
     * Devuelve la clave a la que apunta la ficha, no la copia congelada.
     */
    public function masterTechnicalSheet(string $slot): ?TechnicalSheetEntry
    {
        /** @var array<string, array{relation: string, model: class-string<TechnicalSheetEntry>}> $map */
        $map = [
            ProductTechnicalSheet::SLOT_COMPOSITION => [
                'relation' => 'technicalSheetComposition',
                'model' => TechnicalSheetComposition::class,
            ],
            ProductTechnicalSheet::SLOT_FIT => [
                'relation' => 'technicalSheetFit',
                'model' => TechnicalSheetFit::class,
            ],
            ProductTechnicalSheet::SLOT_CARE => [
                'relation' => 'technicalSheetCare',
                'model' => TechnicalSheetCare::class,
            ],
            ProductTechnicalSheet::SLOT_SIZE_GUIDE => [
                'relation' => 'technicalSheetSizeGuide',
                'model' => TechnicalSheetSizeGuide::class,
            ],
        ];

        if (! isset($map[$slot])) {
            return null;
        }

        $definition = $map[$slot];
        $relation = $definition['relation'];
        $model = $definition['model'];

        if (! $this->exists) {
            return null;
        }

        if ($this->relationLoaded($relation)) {
            /** @var TechnicalSheetEntry|null $loaded */
            $loaded = $this->getRelation($relation);

            return $loaded;
        }

        $key = $this->getAttribute(match ($slot) {
            ProductTechnicalSheet::SLOT_COMPOSITION => 'technical_sheet_composition_id',
            ProductTechnicalSheet::SLOT_FIT => 'technical_sheet_fit_id',
            ProductTechnicalSheet::SLOT_CARE => 'technical_sheet_care_id',
            default => 'technical_sheet_size_guide_id',
        });

        if ($key === null) {
            return null;
        }

        return $model::query()->find($key);
    }

    /**
     * ¿Ha cambiado alguna clave de mantenimiento en esta edición?
     *
     * Se mira `wasChanged` y no `isDirty`, porque el enganche corre **después**
     * de guardar y en ese momento `isDirty` ya se ha limpiado.
     */
    public function technicalSheetSelectionChanged(): bool
    {
        foreach (self::TECHNICAL_SHEET_SELECTION_ATTRIBUTES as $attribute) {
            if ($this->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Hay algún mantenimiento seleccionado?
     */
    public function hasTechnicalSheetSelection(): bool
    {
        foreach (self::TECHNICAL_SHEET_SELECTION_ATTRIBUTES as $attribute) {
            if ($this->getAttribute($attribute) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claves ajenas de mantenimiento, por hueco. Sirve para capturar la copia
     * congelada en el mismo orden en que se compone la descripción.
     *
     * @return array<string, int|null>
     */
    public function technicalSheetSelectionIds(): array
    {
        return [
            ProductTechnicalSheet::SLOT_COMPOSITION => $this->technical_sheet_composition_id === null
                ? null
                : (int) $this->technical_sheet_composition_id,
            ProductTechnicalSheet::SLOT_FIT => $this->technical_sheet_fit_id === null
                ? null
                : (int) $this->technical_sheet_fit_id,
            ProductTechnicalSheet::SLOT_CARE => $this->technical_sheet_care_id === null
                ? null
                : (int) $this->technical_sheet_care_id,
            ProductTechnicalSheet::SLOT_SIZE_GUIDE => $this->technical_sheet_size_guide_id === null
                ? null
                : (int) $this->technical_sheet_size_guide_id,
        ];
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    public function primaryMedia(): ?ProductMedia
    {
        return $this->media()->where('is_primary', true)->first()
            ?? $this->media()->first();
    }

    public function contentFor(Locale $locale = Locale::Es, ?int $version = null): ?ProductContent
    {
        $query = $this->contents()->where('locale', $locale->value);

        if ($version !== null) {
            return $query->where('version', $version)->first();
        }

        return $query->orderByDesc('version')->first();
    }

    /**
     * Última versión de propuesta registrada para un idioma.
     */
    public function latestContentVersion(Locale $locale = Locale::Es): int
    {
        return (int) ($this->contents()->where('locale', $locale->value)->max('version') ?? 0);
    }

    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }

    public function isSyncedWithShopify(): bool
    {
        return filled($this->shopify_product_gid);
    }

    /**
     * Cambia el estado validando la transición (RFC-0000).
     *
     * Devuelve `false` sin modificar nada si la transición no está permitida, de
     * modo que un flujo no pueda saltarse la revisión humana por accidente.
     */
    public function transitionTo(ProductStatus $target): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $this->status = $target;

        return $this->save();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, ProductStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('created_by', $user instanceof User ? $user->getKey() : $user);
    }
}

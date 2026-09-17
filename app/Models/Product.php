<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audience;
use App\Enums\Locale;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if ($product->internal_reference !== null) {
                $product->internal_reference = SkuNormalizer::normalize($product->internal_reference);
            }
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

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /** @return HasMany<ProductMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductContent, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(ProductContent::class);
    }

    /** @return HasMany<SyncAttempt, $this> */
    public function syncAttempts(): HasMany
    {
        return $this->hasMany(SyncAttempt::class);
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

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Support\Security\SanitizesHtml;
use Database\Factories\ProductContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contenido comercial por idioma y versión de propuesta (RFC-0001 / RFC-0003).
 *
 * `html_description` pasa por la whitelist de HTML al guardarse. Una propuesta
 * nueva se guarda como versión nueva: una versión aprobada no se sobrescribe.
 */
class ProductContent extends Model
{
    /** @use HasFactory<ProductContentFactory> */
    use HasFactory;

    protected $table = 'product_content';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'locale',
        'version',
        'title',
        'short_benefit',
        'handle',
        'html_description',
        'seo_title',
        'seo_description',
        'tags_json',
        'alt_texts_json',
        'facts_detected_json',
        'warnings_json',
        'product_category_taxonomy_id',
        'ai_model',
        'prompt_version',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'version' => 'integer',
            'tags_json' => 'array',
            'alt_texts_json' => 'array',
            'facts_detected_json' => 'array',
            'warnings_json' => 'array',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'html_description' => SanitizesHtml::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_filter((array) $this->tags_json, static fn ($tag): bool => is_string($tag) && $tag !== ''));
    }

    /**
     * Indicadores de datos no confirmados que la usuaria debe revisar (RFC-0003).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_filter((array) $this->warnings_json, static fn ($warning): bool => is_string($warning) && $warning !== ''));
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isAiGenerated(): bool
    {
        return $this->ai_model !== null;
    }
}

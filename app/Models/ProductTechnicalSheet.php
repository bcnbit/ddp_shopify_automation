<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copia congelada de un mantenimiento usada por una ficha (RFC-0008).
 *
 * Es lo que hace cierta la promesa «modificar un mantenimiento no altera fichas
 * ya creadas»: lo que se envía a Shopify y lo que se envía a la IA salen de
 * aquí, nunca del maestro.
 *
 * `entry_id` no tiene clave ajena a propósito: si el mantenimiento se borra, la
 * ficha debe seguir enviando el mismo texto. Por eso también se copian el
 * código, el nombre y la versión, para que la copia sea legible por sí sola.
 */
class ProductTechnicalSheet extends Model
{
    public const SLOT_COMPOSITION = 'composition';

    public const SLOT_FIT = 'fit';

    public const SLOT_CARE = 'care';

    public const SLOT_SIZE_GUIDE = 'size_guide';

    /** @return list<string> */
    public static function slots(): array
    {
        return [
            self::SLOT_COMPOSITION,
            self::SLOT_FIT,
            self::SLOT_CARE,
            self::SLOT_SIZE_GUIDE,
        ];
    }

    protected $table = 'product_technical_sheets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'slot',
        'entry_id',
        'entry_code',
        'entry_name',
        'entry_version',
        'content_text',
        'content_html',
        'intro_note',
        'closing_note',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_version' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isSizeGuide(): bool
    {
        return $this->slot === self::SLOT_SIZE_GUIDE;
    }
}

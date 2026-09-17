<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaUploadStatus;
use Database\Factories\ProductMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Medio de producto (RFC-0001 / RFC-0005).
 *
 * El original nunca se borra. `sha256` identifica el archivo de forma estable
 * para detectar duplicados y para no volver a subirlo a Shopify (RFC-0004).
 */
class ProductMedia extends Model
{
    /** @use HasFactory<ProductMediaFactory> */
    use HasFactory;

    protected $table = 'product_media';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'bytes',
        'sha256',
        'width',
        'height',
        'alt_text',
        'is_primary',
        'sort_order',
        'shopify_media_gid',
        'upload_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'upload_status' => MediaUploadStatus::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isUploaded(): bool
    {
        return $this->upload_status === MediaUploadStatus::Uploaded;
    }

    public function hasAltText(): bool
    {
        return filled($this->alt_text);
    }

    /**
     * URL de lectura del original. El disco de medios es privado: se sirve a
     * través de Storage, nunca como archivo público del directorio `public`.
     */
    public function url(): ?string
    {
        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function humanFileSize(): string
    {
        $bytes = (int) $this->bytes;

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.').' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0, ',', '.').' KB',
            default => $bytes.' B',
        };
    }
}

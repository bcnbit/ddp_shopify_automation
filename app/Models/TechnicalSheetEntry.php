<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audience;
use App\Enums\ProductType;
use App\Support\Products\SkuNormalizer;
use Database\Factories\TechnicalSheetCompositionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de los mantenimientos de ficha técnica (RFC-0008).
 *
 * Los cuatro mantenimientos comparten la misma cabecera —código legible, nombre
 * interno, tipo de prenda y público aplicables, estado activo y versión— y se
 * diferencian sólo en su contenido. Esta clase reúne lo común para que las
 * cuatro tablas no repitan las mismas reglas.
 *
 * No se usa una tabla única con discriminador porque cada mantenimiento es un
 * recurso Filament distinto y tiene una forma de contenido distinta: con cuatro
 * modelos, la URL de un recurso no puede resolver el modelo de otro y la base de
 * datos impone la forma en lugar de un `if`.
 */
abstract class TechnicalSheetEntry extends Model
{
    /**
     * Las fábricas se resuelven por el nombre de la clase concreta, así que
     * `TechnicalSheetComposition::factory()` encuentra su propia fábrica aunque
     * el trait viva aquí.
     *
     * @use HasFactory<TechnicalSheetCompositionFactory>
     */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'product_type',
        'audience',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'audience' => Audience::class,
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            // El código se normaliza igual que un SKU: mayúsculas y guiones. Así
            // «Unisex regular» y «unisex-regular» no conviven como dos códigos.
            if ($entry->code !== null) {
                $entry->code = SkuNormalizer::normalize($entry->code);
            }

            $entry->bumpVersionWhenContentChanged();
        });
    }

    /**
     * Campos cuyo cambio obliga a subir la versión.
     *
     * Son los que identifican el contenido que una ficha copia: si cambian, la
     * copia deja de corresponder al maestro y hay que poder distinguirlo.
     *
     * @return list<string>
     */
    abstract protected function versionedAttributes(): array;

    /**
     * Sube la versión cuando cambia el contenido.
     *
     * La versión no se edita a mano: una versión escrita a mano puede repetirse
     * o retroceder, y entonces la copia congelada de una ficha dejaría de
     * distinguir dos contenidos distintos. Aquí sólo sube, nunca baja.
     */
    private function bumpVersionWhenContentChanged(): void
    {
        if (! $this->exists) {
            $this->version ??= 1;

            return;
        }

        foreach ($this->versionedAttributes() as $attribute) {
            if ($this->isDirty($attribute)) {
                $this->version = ((int) $this->version) + 1;

                return;
            }
        }
    }

    /**
     * Sólo los mantenimientos activos se ofrecen en fichas nuevas (RFC-0008).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Mantenimientos aplicables a una ficha.
     *
     * Se consideran aplicables los que no declaran tipo ni público (sirven para
     * cualquier ficha) y los que coinciden exactamente con los de la ficha. Un
     * mantenimiento sin tipo no se descarta: es el caso habitual de una
     * composición de tejido.
     *
     * `$includeId` mantiene visible la selección actual aunque el mantenimiento
     * se haya desactivado o ya no coincida: ocultarlo haría que el guardado
     * automático borrase la selección sin que nadie lo pidiera.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApplicableTo(Builder $query, ?ProductType $type, ?Audience $audience, ?int $includeId = null): Builder
    {
        return $query->where(function (Builder $query) use ($type, $audience, $includeId): void {
            $query
                ->where(function (Builder $query) use ($type): void {
                    $query->whereNull('product_type');

                    if ($type !== null) {
                        $query->orWhere('product_type', $type->value);
                    }
                })
                ->where(function (Builder $query) use ($audience): void {
                    $query->whereNull('audience');

                    if ($audience !== null) {
                        $query->orWhere('audience', $audience->value);
                    }
                })
                ->where('is_active', true);

            if ($includeId !== null) {
                // `orWhereKey` no existe en el Builder de Eloquent: se usa la
                // clave primaria cualificada para no depender del nombre.
                $query->orWhere($query->getModel()->getQualifiedKeyName(), $includeId);
            }
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Etiqueta con la que aparece en los selectores de la ficha.
     */
    public function selectionLabel(): string
    {
        return (string) $this->name;
    }

    /**
     * Descripción corta del mantenimiento, para avisar de la versión copiada.
     */
    public function describeSnapshot(): string
    {
        return "{$this->name} ({$this->code} · v{$this->version})";
    }
}

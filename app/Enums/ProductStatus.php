<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ciclo de vida de una ficha (RFC-0000).
 *
 * draft -> generating -> review -> approved -> syncing -> shopify_draft -> published
 * Estados alternativos: generation_failed, validation_failed, sync_failed, archived.
 */
enum ProductStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Review = 'review';
    case Approved = 'approved';
    case Syncing = 'syncing';
    case ShopifyDraft = 'shopify_draft';
    case Published = 'published';
    case GenerationFailed = 'generation_failed';
    case ValidationFailed = 'validation_failed';
    case SyncFailed = 'sync_failed';
    case Archived = 'archived';

    /**
     * Color del distintivo de estado en el panel: refleja si la ficha está en
     * curso, esperando a una persona o en un estado de error.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Archived => 'gray',
            self::Generating, self::Syncing => 'info',
            self::Review => 'warning',
            self::Approved => 'primary',
            self::ShopifyDraft, self::Published => 'success',
            self::GenerationFailed, self::ValidationFailed, self::SyncFailed => 'danger',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Generating => 'Generando propuesta',
            self::Review => 'En revisión',
            self::Approved => 'Aprobada',
            self::Syncing => 'Sincronizando',
            self::ShopifyDraft => 'Borrador en Shopify',
            self::Published => 'Publicada',
            self::GenerationFailed => 'Fallo de generación',
            self::ValidationFailed => 'Fallo de validación',
            self::SyncFailed => 'Fallo de sincronización',
            self::Archived => 'Archivada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Indica si la ficha admite edición de datos por parte de una persona.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft,
            self::Review,
            self::GenerationFailed,
            self::ValidationFailed,
            self::SyncFailed => true,
            default => false,
        };
    }

    public function isFailed(): bool
    {
        return match ($this) {
            self::GenerationFailed,
            self::ValidationFailed,
            self::SyncFailed => true,
            default => false,
        };
    }

    /**
     * Indica si la ficha ya existe en Shopify (borrador o publicado).
     */
    public function isSynced(): bool
    {
        return match ($this) {
            self::ShopifyDraft,
            self::Published => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Transiciones permitidas desde el estado actual, según RFC-0000.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generating, self::Archived],
            self::Generating => [self::Review, self::GenerationFailed, self::ValidationFailed, self::Archived],
            self::Review => [self::Approved, self::Generating, self::Draft, self::ValidationFailed, self::Archived],
            self::Approved => [self::Syncing, self::Review, self::ValidationFailed, self::Archived],
            self::Syncing => [self::ShopifyDraft, self::SyncFailed],
            self::ShopifyDraft => [self::Published, self::Syncing, self::SyncFailed, self::Archived],
            self::Published => [self::Syncing, self::Archived],
            self::GenerationFailed => [self::Generating, self::Draft, self::Archived],
            self::ValidationFailed => [self::Draft, self::Review, self::Generating, self::Archived],
            self::SyncFailed => [self::Syncing, self::Approved, self::ValidationFailed, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}

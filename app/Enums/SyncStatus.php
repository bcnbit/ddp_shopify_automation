<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Running => 'En curso',
            self::Succeeded => 'Correcta',
            self::Failed => 'Con error',
            self::Skipped => 'Omitida',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Skipped => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

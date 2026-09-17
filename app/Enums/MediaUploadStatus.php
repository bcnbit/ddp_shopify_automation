<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MediaUploadStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Failed = 'failed';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Uploaded => 'success',
            self::Failed => 'danger',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de subir',
            self::Uploaded => 'Subida',
            self::Failed => 'Fallo de subida',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

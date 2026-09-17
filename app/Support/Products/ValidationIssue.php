<?php

declare(strict_types=1);

namespace App\Support\Products;

/**
 * Un problema detectado en una ficha (RFC-0002).
 *
 * `field` permite que la interfaz señale la pestaña o el campo concreto, y
 * `code` es un identificador estable que no depende del idioma del mensaje.
 */
final readonly class ValidationIssue
{
    public const SEVERITY_BLOCKING = 'blocking';

    public const SEVERITY_WARNING = 'warning';

    private function __construct(
        public string $severity,
        public string $field,
        public string $message,
        public string $code,
    ) {}

    public static function blocking(string $field, string $message, string $code): self
    {
        return new self(self::SEVERITY_BLOCKING, $field, $message, $code);
    }

    public static function warning(string $field, string $message, string $code): self
    {
        return new self(self::SEVERITY_WARNING, $field, $message, $code);
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_BLOCKING;
    }

    /**
     * @return array{severity: string, field: string, message: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'field' => $this->field,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}

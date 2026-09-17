<?php

declare(strict_types=1);

namespace App\Support\Products;

/**
 * Resultado de validar una ficha (RFC-0002).
 *
 * Separar bloqueantes de avisos es lo que permite que una ficha con una meta
 * description mejorable pueda enviarse igualmente, pero quede señalada: ese
 * comportamiento es un caso de prueba explícito de RFC-0006.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<ValidationIssue>  $issues
     */
    public function __construct(public array $issues = []) {}

    public function passes(): bool
    {
        return $this->blockingIssues() === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * @return list<ValidationIssue>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $issue): bool => $issue->isBlocking(),
        ));
    }

    /**
     * @return list<ValidationIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ValidationIssue $issue): bool => ! $issue->isBlocking(),
        ));
    }

    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    /**
     * @return list<string>
     */
    public function blockingMessages(): array
    {
        return array_map(static fn (ValidationIssue $issue): string => $issue->message, $this->blockingIssues());
    }

    /**
     * @return list<string>
     */
    public function warningMessages(): array
    {
        return array_map(static fn (ValidationIssue $issue): string => $issue->message, $this->warnings());
    }

    /**
     * Campos afectados por algún bloqueante, para señalarlos en el formulario.
     *
     * @return list<string>
     */
    public function blockingFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (ValidationIssue $issue): string => $issue->field,
            $this->blockingIssues(),
        )));
    }

    /**
     * @return list<array{severity: string, field: string, message: string, code: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ValidationIssue $issue): array => $issue->toArray(), $this->issues);
    }
}

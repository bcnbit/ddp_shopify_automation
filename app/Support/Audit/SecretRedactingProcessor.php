<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Security\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Procesador Monolog que elimina secretos de los logs (RFC-0001).
 *
 * Se registra en la configuración de cada canal (`config/logging.php`) en lugar
 * de resolverse al arrancar: resolver canales como Slack en el boot exigiría
 * credenciales que no siempre están configuradas. Al ser un procesador, actúa
 * en el momento de escribir y no puede olvidarse en una ruta concreta.
 */
final class SecretRedactingProcessor implements ProcessorInterface
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message) ?? $record->message,
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($record->extra),
        );
    }
}

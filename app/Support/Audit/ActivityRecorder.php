<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Punto único de escritura de auditoría (RFC-0001).
 *
 * Toda entrada pasa por SecretRedactor antes de tocar la base de datos, de modo
 * que ninguna credencial ni HTML completo llega a `activity_log`.
 *
 * El actor puede ser nulo porque hay acciones que no ejecuta una persona: un
 * trabajo en cola que genera contenido o sincroniza con Shopify. En ese caso se
 * registra una etiqueta («Sistema») en vez de atribuir la acción a quien inició
 * sesión por última vez, que sería engañoso en la auditoría.
 *
 * El contexto de la petición (IP, agente, `request_id`) lo aporta
 * `AssignsRequestId`; en consola queda vacío en lugar de registrar una IP falsa.
 */
class ActivityRecorder
{
    public const SYSTEM_ACTOR = 'Sistema';

    public function __construct(private readonly SecretRedactor $redactor) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        ActivityEvent $event,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
        ?User $actor = null,
        ?string $actorLabel = null,
    ): ActivityLog {
        // Sólo se toma el usuario de la sesión si no se ha dicho explícitamente
        // que la acción es del sistema.
        $actor ??= $actorLabel === null ? Auth::user() : null;

        return ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'event' => $event->value,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $this->redactor->redact($properties),
            'ip_address' => $this->context('client_ip'),
            'user_agent' => $this->truncatedUserAgent(),
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * Registra un cambio de atributos con diff estructurado.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function recordChanges(
        ActivityEvent $event,
        Model $subject,
        array $old,
        array $new,
        ?string $description = null,
        ?User $actor = null,
    ): ActivityLog {
        $actor ??= Auth::user();

        return ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'event' => $event->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'description' => $description,
            'properties' => ['changes' => $this->redactor->redact($new)],
            'changes_json' => [
                'old' => $this->redactor->redact($old),
                'new' => $this->redactor->redact($new),
            ],
            'ip_address' => $this->context('client_ip'),
            'user_agent' => $this->truncatedUserAgent(),
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * Acción automática, sin persona detrás (trabajos en cola).
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordSystem(
        ActivityEvent $event,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
    ): ActivityLog {
        return $this->record(
            event: $event,
            subject: $subject,
            description: $description,
            properties: $properties,
            actorLabel: self::SYSTEM_ACTOR,
        );
    }

    /**
     * Lee un dato de contexto que asignó `AssignsRequestId`.
     *
     * Fuera de una petición HTTP los atributos no existen y el valor queda nulo,
     * en lugar de registrarse una IP o un agente inventados.
     */
    private function context(string $key): ?string
    {
        $value = request()->attributes->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function truncatedUserAgent(): ?string
    {
        $agent = $this->context('client_user_agent');

        return $agent === null ? null : mb_substr($agent, 0, 255);
    }

    private function requestId(): ?string
    {
        $current = request()->attributes->get('request_id');

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $generated = (string) Str::uuid();
        request()->attributes->set('request_id', $generated);

        return $generated;
    }
}

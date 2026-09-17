<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityEvent;
use App\Support\Security\SecretRedactor;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro de auditoría (RFC-0001).
 *
 * Escribe exactamente lo que recibe: la redacción de secretos ocurre antes de
 * llamar a `record()` (ver ActivityRecorder). Así ningún llamante puede saltarse
 * el filtro por descuido.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    protected $table = 'activity_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'actor_email',
        'event',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'changes_json',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => ActivityEvent::class,
            'properties' => 'array',
            'changes_json' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Diff legible: valores anteriores y nuevos de los campos relevantes.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changedAttributes(): array
    {
        $old = (array) ($this->changes_json['old'] ?? []);
        $new = (array) ($this->changes_json['new'] ?? []);
        $redactor = new SecretRedactor;

        $result = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $result[$key] = [
                'old' => is_array($old[$key] ?? null) ? $redactor->redact($old[$key]) : $old[$key] ?? null,
                'new' => is_array($new[$key] ?? null) ? $redactor->redact($new[$key]) : $new[$key] ?? null,
            ];
        }

        return $result;
    }
}

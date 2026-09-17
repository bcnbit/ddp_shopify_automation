<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Eventos registrados en la base de auditoría (RFC-0001).
 */
enum ActivityEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case StatusChanged = 'status_changed';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';
    case GenerationRequested = 'generation_requested';
    case GenerationSucceeded = 'generation_succeeded';
    case GenerationFailed = 'generation_failed';
    case SyncRequested = 'sync_requested';
    case SyncSucceeded = 'sync_succeeded';
    case SyncFailed = 'sync_failed';
    case SyncRetried = 'sync_retried';
    case MediaUploaded = 'media_uploaded';
    case MediaDeleted = 'media_deleted';
    case ContentCreated = 'content_created';
    case ContentUpdated = 'content_updated';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case Login = 'login';
    case FailedLogin = 'failed_login';
    case SecretAccessed = 'secret_accessed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creado',
            self::Updated => 'Actualizado',
            self::Deleted => 'Eliminado',
            self::Restored => 'Restaurado',
            self::StatusChanged => 'Cambio de estado',
            self::Approved => 'Aprobado',
            self::Published => 'Publicado',
            self::Archived => 'Archivado',
            self::GenerationRequested => 'Generación solicitada',
            self::GenerationSucceeded => 'Generación correcta',
            self::GenerationFailed => 'Generación fallida',
            self::SyncRequested => 'Sincronización solicitada',
            self::SyncSucceeded => 'Sincronización correcta',
            self::SyncFailed => 'Sincronización fallida',
            self::SyncRetried => 'Reintento de sincronización',
            self::MediaUploaded => 'Medio subido',
            self::MediaDeleted => 'Medio eliminado',
            self::ContentCreated => 'Contenido creado',
            self::ContentUpdated => 'Contenido actualizado',
            self::RoleAssigned => 'Rol asignado',
            self::RoleRevoked => 'Rol retirado',
            self::Login => 'Inicio de sesión',
            self::FailedLogin => 'Intento de acceso fallido',
            self::SecretAccessed => 'Acceso a credenciales',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permisos atómicos del sistema (RFC-0000).
 *
 * Se separan deliberadamente los permisos de escritura de fichas de los de
 * envío, publicación, configuración y auditoría.
 */
enum Permission: string
{
    // Fichas y contenido
    case ProductsView = 'products.view';
    case ProductsViewAll = 'products.view_all';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    // Medios
    case MediaUpload = 'media.upload';
    case MediaDelete = 'media.delete';

    // IA
    case ContentGenerate = 'content.generate';
    case ContentApprove = 'content.approve';

    // Aprobación y Shopify
    case ProductsApprove = 'products.approve';
    case ProductsSync = 'products.sync';
    case ProductsPublish = 'products.publish';

    // Mantenimientos de ficha técnica (RFC-0008)
    case TechnicalSheetsView = 'technical_sheets.view';
    case TechnicalSheetsManage = 'technical_sheets.manage';

    // Operación técnica
    case SyncRetry = 'sync.retry';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case UsersManage = 'users.manage';

    public function label(): string
    {
        return match ($this) {
            self::ProductsView => 'Ver fichas propias',
            self::ProductsViewAll => 'Ver todas las fichas',
            self::ProductsCreate => 'Crear fichas',
            self::ProductsUpdate => 'Editar fichas',
            self::ProductsDelete => 'Eliminar fichas',
            self::MediaUpload => 'Subir medios',
            self::MediaDelete => 'Eliminar medios',
            self::ContentGenerate => 'Generar propuesta con IA',
            self::ContentApprove => 'Aprobar contenido',
            self::ProductsApprove => 'Aprobar ficha',
            self::ProductsSync => 'Enviar borradores a Shopify',
            self::ProductsPublish => 'Publicar en Shopify',
            self::TechnicalSheetsView => 'Consultar mantenimientos de ficha técnica',
            self::TechnicalSheetsManage => 'Gestionar mantenimientos de ficha técnica',
            self::SyncRetry => 'Reintentar sincronizaciones',
            self::AuditView => 'Consultar auditoría',
            self::SettingsManage => 'Configurar conexiones y reglas',
            self::UsersManage => 'Gestionar usuarios y roles',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Permisos concedidos a cada rol (RFC-0000).
     *
     * @return array<string, list<string>>
     */
    public static function byRole(): array
    {
        return [
            Role::Operadora->value => [
                self::ProductsView->value,
                self::ProductsCreate->value,
                self::ProductsUpdate->value,
                self::MediaUpload->value,
                self::MediaDelete->value,
                self::ContentGenerate->value,
                self::ProductsSync->value,
                self::TechnicalSheetsView->value,
                self::TechnicalSheetsManage->value,
            ],
            Role::ResponsableCatalogo->value => [
                self::ProductsView->value,
                self::ProductsViewAll->value,
                self::ProductsCreate->value,
                self::ProductsUpdate->value,
                self::ProductsDelete->value,
                self::MediaUpload->value,
                self::MediaDelete->value,
                self::ContentGenerate->value,
                self::ContentApprove->value,
                self::ProductsApprove->value,
                self::ProductsSync->value,
                self::TechnicalSheetsView->value,
                self::TechnicalSheetsManage->value,
            ],
            Role::AdminTecnico->value => self::values(),
        ];
    }
}

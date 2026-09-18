<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Comando de diagnóstico y reparación de permisos (RFC-0008).
 *
 * Convierte el desfase «código por delante de la base de datos» en algo visible
 * y reparable en un paso, en lugar de un 500 en el panel.
 */
class SyncPermissionsCommandTest extends TestCase
{
    private function removePermission(string $name): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', static function ($query) use ($name): void {
                $query->select('id')->from('permissions')->where('name', $name);
            })
            ->delete();

        DB::table('permissions')->where('name', $name)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_informa_cuando_todo_esta_en_sincronia(): void
    {
        $this->artisan('studio:permissions')
            ->expectsOutputToContain('sincronizados')
            ->assertSuccessful();
    }

    public function test_detecta_un_permiso_que_falta(): void
    {
        $this->removePermission(Permission::TechnicalSheetsView->value);

        $this->artisan('studio:permissions')
            ->expectsOutputToContain(Permission::TechnicalSheetsView->value)
            ->assertFailed();
    }

    public function test_avisa_sin_modificar_nada_si_no_se_pide_sincronizar(): void
    {
        $this->removePermission(Permission::TechnicalSheetsView->value);

        $this->artisan('studio:permissions')->assertFailed();

        // Sin `--sync` es sólo un diagnóstico: no debe tocar la base.
        $this->assertFalse(
            PermissionModel::query()->where('name', Permission::TechnicalSheetsView->value)->exists(),
        );
    }

    public function test_sincroniza_el_reparto_del_enum_con_sync(): void
    {
        $this->removePermission(Permission::TechnicalSheetsView->value);

        $this->artisan('studio:permissions', ['--sync' => true])->assertSuccessful();

        $this->assertTrue(
            PermissionModel::query()->where('name', Permission::TechnicalSheetsView->value)->exists(),
        );

        // Y queda concedido a quien le corresponde por `byRole()`.
        $this->assertTrue($this->operadora()->hasPermissionTo(Permission::TechnicalSheetsView->value));
    }

    public function test_sync_es_idempotente(): void
    {
        $before = PermissionModel::count();

        $this->artisan('studio:permissions', ['--sync' => true])->assertSuccessful();
        $this->artisan('studio:permissions', ['--sync' => true])->assertSuccessful();

        $this->assertSame($before, PermissionModel::count());
    }

    public function test_sync_restaura_los_roles(): void
    {
        DB::table('role_has_permissions')->delete();

        $this->artisan('studio:permissions', ['--sync' => true])->assertSuccessful();

        // Tras vaciar el reparto, el seeder lo reconstruye entero.
        $this->assertTrue($this->responsable()->hasPermissionTo(Permission::ProductsApprove->value));
        $this->assertTrue($this->admin()->hasPermissionTo(Permission::UsersManage->value));
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Cada prueba arranca con roles y permisos reales: la autorización se
     * ejercita contra el mismo reparto de permisos que en producción.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    protected function operadora(): User
    {
        return User::factory()->operadora()->create();
    }

    protected function responsable(): User
    {
        return User::factory()->responsableCatalogo()->create();
    }

    protected function admin(): User
    {
        return User::factory()->adminTecnico()->create();
    }
}

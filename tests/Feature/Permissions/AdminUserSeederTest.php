<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Creación y reseteo del administrador técnico (RFC-0007 / RFC-0010).
 *
 * El caso que motivó estas pruebas: el seeder leía `env()` directamente, y con la
 * configuración cacheada —lo que recomienda RFC-0010 para producción— `env()`
 * devuelve `null` porque Laravel no carga el `.env`. El seeder creía entonces que
 * no se le había dado contraseña y, como el administrador ya existía, terminaba
 * **sin cambiar nada y sin avisar**.
 *
 * Por eso la prueba clave no es «el seeder crea un administrador», sino
 * «el seeder respeta la contraseña configurada aunque la configuración esté
 * cacheada».
 */
class AdminUserSeederTest extends TestCase
{
    /**
     * Simula el efecto de `php artisan config:cache` sobre lo que lee el seeder.
     *
     * No se ejecuta el comando de verdad —cachearía toda la aplicación y dejaría el
     * entorno de pruebas sucio—: se comprueba lo que de verdad importa, que es que
     * el seeder **no dependa de `env()`**. Con `config()` el valor ya está
     * resuelto; con `env()` directo, no.
     */
    public function test_usa_la_contrasena_configurada_en_lugar_de_generar_una_aleatoria(): void
    {
        config()->set('product-studio.admin.password', 'contrasena-de-prueba-larga');

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@diesdeplatja.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('contrasena-de-prueba-larga', $admin->password));
        $this->assertTrue($admin->hasRole(Role::AdminTecnico->value));
    }

    public function test_la_contrasena_configurada_no_depende_de_env(): void
    {
        // Es la regresión concreta: si el seeder volviera a leer `env()`, con la
        // configuración cacheada recibiría null y esta prueba fallaría.
        config()->set('product-studio.admin.password', 'otra-contrasena-larga-123');

        // Se deja el entorno sin la variable, igual que con la caché activa.
        putenv('PRODUCT_STUDIO_ADMIN_PASSWORD');
        unset($_ENV['PRODUCT_STUDIO_ADMIN_PASSWORD'], $_SERVER['PRODUCT_STUDIO_ADMIN_PASSWORD']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@diesdeplatja.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('otra-contrasena-larga-123', $admin->password));
    }

    public function test_resetea_la_contrasena_de_un_administrador_existente(): void
    {
        $admin = User::factory()->adminTecnico()->create([
            'email' => 'admin@diesdeplatja.test',
            'password' => Hash::make('contrasena-antigua'),
        ]);

        config()->set('product-studio.admin.password', 'contrasena-nueva-larga-1');

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        // Se actualiza el mismo registro, no se crea otro.
        $this->assertSame(1, User::where('email', 'admin@diesdeplatja.test')->count());
        $this->assertTrue(Hash::check('contrasena-nueva-larga-1', $admin->password));
        $this->assertFalse(Hash::check('contrasena-antigua', $admin->password));
    }

    public function test_genera_una_aleatoria_cuando_no_hay_contrasena_ni_administrador(): void
    {
        config()->set('product-studio.admin.password', null);

        $this->assertSame(0, User::role(Role::AdminTecnico->value)->count());

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@diesdeplatja.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole(Role::AdminTecnico->value));

        // La contraseña generada no se puede conocer, pero sí comprobar que es un
        // hash bcrypt real y no un valor vacío.
        $this->assertStringStartsWith('$2y$', (string) $admin->password);
    }

    public function test_no_toca_nada_si_hay_administrador_y_no_hay_contrasena(): void
    {
        // Sin contraseña configurada, generar otra dejaría al administrador actual
        // fuera sin que nadie lo haya pedido.
        $admin = User::factory()->adminTecnico()->create([
            'email' => 'admin@diesdeplatja.test',
            'password' => Hash::make('contrasena-que-debe-sobrevivir'),
        ]);

        config()->set('product-studio.admin.password', null);

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        $this->assertTrue(Hash::check('contrasena-que-debe-sobrevivir', $admin->password));
    }

    public function test_rechaza_una_contrasena_demasiado_corta(): void
    {
        config()->set('product-studio.admin.password', 'corta');

        $this->expectException(RuntimeException::class);

        $this->seed(AdminUserSeeder::class);
    }

    public function test_respeta_el_minimo_configurado(): void
    {
        config()->set('product-studio.admin.min_password_length', 20);
        config()->set('product-studio.admin.password', 'dieciseis-chars-1');

        // Con el mínimo subido a 20, una contraseña de 17 debe rechazarse.
        $this->expectException(RuntimeException::class);

        $this->seed(AdminUserSeeder::class);
    }

    public function test_usa_el_email_y_el_nombre_configurados(): void
    {
        config()->set('product-studio.admin.email', 'otro-admin@example.test');
        config()->set('product-studio.admin.name', 'Otro administrador');
        config()->set('product-studio.admin.password', 'contrasena-de-prueba-larga');

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'otro-admin@example.test')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Otro administrador', $admin->name);
    }

    public function test_es_idempotente_con_la_misma_contrasena(): void
    {
        config()->set('product-studio.admin.password', 'contrasena-de-prueba-larga');

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@diesdeplatja.test')->count());

        $admin = User::where('email', 'admin@diesdeplatja.test')->first();
        $this->assertTrue(Hash::check('contrasena-de-prueba-larga', $admin->password));
    }
}

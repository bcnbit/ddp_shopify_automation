<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Comando `storage:check` (RFC-0007).
 *
 * Es el diagnóstico que se ejecuta antes de activar el bucket, así que sus dos
 * obligaciones son: no filtrar credenciales y distinguir los fallos que se
 * confunden entre sí (credencial inválida, bucket inexistente, región equivocada).
 */
class CheckStorageConnectionTest extends TestCase
{
    /**
     * Disco local: el comando informa y comprueba escritura.
     */
    public function test_un_disco_local_se_comprueba_sin_exigir_bucket(): void
    {
        Storage::fake('media');
        config()->set('media.disks.originals', 'media');
        config()->set('livewire.temporary_file_upload.disk', null);

        $this->artisan('storage:check')
            ->expectsOutputToContain('El disco no es S3')
            ->expectsOutputToContain('lectura y escritura')
            ->assertExitCode(0);
    }

    public function test_avisa_de_las_credenciales_que_faltan(): void
    {
        config()->set('filesystems.disks.bucket-sin-credenciales', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'region' => 'eu-west-1',
            'bucket' => '',
        ]);

        $this->artisan('storage:check', ['--disk' => 'bucket-sin-credenciales'])
            ->expectsOutputToContain('Faltan datos para usar el bucket')
            ->expectsOutputToContain('AWS_ACCESS_KEY_ID')
            ->expectsOutputToContain('AWS_SECRET_ACCESS_KEY')
            ->expectsOutputToContain('AWS_BUCKET')
            ->assertExitCode(1);
    }

    public function test_nunca_imprime_los_valores_de_las_credenciales(): void
    {
        // Valores con marca, para poder buscarlos literalmente en la salida.
        config()->set('filesystems.disks.bucket-marca', [
            'driver' => 's3',
            'key' => 'AKIAMARCAACCESSKEY123',
            'secret' => 'MARCA-secreto-que-no-debe-salir',
            'region' => 'eu-west-1',
            'bucket' => 'marca-bucket',
        ]);

        $this->artisan('storage:check', ['--disk' => 'bucket-marca'])
            ->doesntExpectOutputToContain('AKIAMARCAACCESSKEY123')
            ->doesntExpectOutputToContain('MARCA-secreto-que-no-debe-salir')
            ->assertExitCode(1);
    }

    public function test_informa_de_la_longitud_de_la_credencial(): void
    {
        $clave = 'clave-de-prueba-con-longitud-conocida';
        $longitud = strlen($clave);

        config()->set('filesystems.disks.bucket-longitud', [
            'driver' => 's3',
            'key' => $clave,
            'secret' => 'secreto',
            'region' => 'eu-west-1',
            'bucket' => 'bucket-de-prueba',
        ]);

        // Saber que está puesta y cuánto mide ayuda a detectar un pegado incompleto,
        // que es el error más común al copiar credenciales.
        $this->artisan('storage:check', ['--disk' => 'bucket-longitud'])
            ->expectsOutputToContain('presente ('.$longitud.' caracteres)')
            ->assertExitCode(1);
    }

    public function test_el_bucket_de_originales_cae_al_principal_si_no_se_indica(): void
    {
        $config = file_get_contents(base_path('config/filesystems.php'));

        // El fallback anidado de `env()` NO funciona cuando la variable existe y
        // está vacía —que es como queda en la plantilla—, y ese fue un bug real:
        // el disco se quedaba sin bucket. Se comprueba que la configuración usa la
        // forma que sí lo resuelve.
        $this->assertStringContainsString(
            "env('AWS_BUCKET_MEDIA') ?: env('AWS_BUCKET')",
            (string) $config,
        );

        $this->assertStringNotContainsString(
            "env('AWS_BUCKET_MEDIA', env('AWS_BUCKET'))",
            (string) $config,
        );
    }

    public function test_un_disco_inexistente_lo_dice(): void
    {
        $this->artisan('storage:check', ['--disk' => 'no-existe'])
            ->expectsOutputToContain('no existe en config/filesystems.php')
            ->assertExitCode(1);
    }
}

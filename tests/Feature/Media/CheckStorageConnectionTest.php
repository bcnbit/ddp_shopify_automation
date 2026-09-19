<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
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
     * Ninguna prueba de esta clase debe llegar a la red.
     *
     * Las comprobaciones nuevas envían peticiones HTTP reales (el preflight y el PUT),
     * así que una que se escape no falla: se queda esperando al timeout y **pasa por
     * casualidad** dando el resultado equivocado. Con esto, una petición sin simular
     * lanza una excepción en lugar de tardar 15 segundos en silencio.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

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

    public function test_los_dos_discos_aceptan_cualquiera_de_las_dos_variables_de_bucket(): void
    {
        $config = file_get_contents(base_path('config/filesystems.php'));

        // Bug real: el disco de originales prefería `AWS_BUCKET_MEDIA` y el temporal
        // sólo miraba `AWS_BUCKET`. Rellenando una sola de las dos —que es lo que
        // invita a hacer la plantilla—, uno de los dos se quedaba sin bucket y la
        // firma de la subida fallaba con un error genérico.
        $this->assertStringContainsString(
            "env('AWS_BUCKET_MEDIA') ?: env('AWS_BUCKET')",
            $config,
            'Los originales deben aceptar AWS_BUCKET como respaldo.',
        );

        $this->assertStringContainsString(
            "env('AWS_BUCKET') ?: env('AWS_BUCKET_MEDIA')",
            $config,
            'El temporal debe aceptar AWS_BUCKET_MEDIA como respaldo.',
        );
    }

    public function test_avisa_cuando_el_disco_temporal_no_es_un_bucket(): void
    {
        // Es el escenario real del error de subida: los originales ya están en S3
        // —por eso el comando dice «Bucket OK»— pero el disco que usa la **subida**
        // sigue siendo local.
        config()->set('filesystems.disks.originales-bucket', [
            'driver' => 's3',
            'key' => 'clave',
            'secret' => 'secreto',
            'region' => 'eu-west-1',
            'bucket' => 'bucket',
        ]);

        config()->set('media.disks.originals', 'media');
        config()->set('livewire.temporary_file_upload.disk', null);
        config()->set('filesystems.default', 'local');

        Storage::fake('media');

        // El aviso se da al terminar: el bucket está bien, pero la subida no lo usa.
        $this->artisan('storage:check', ['--disk' => 'media'])
            ->expectsOutputToContain('Disco temporal')
            ->assertExitCode(0);
    }

    /**
     * Prepara un disco S3 «de mentira» que responde a todo lo que comprueba el
     * comando, y una firma de subida controlada.
     *
     * El disco se registra como instancia local (lo que hace `Storage::fake()`), pero
     * la **configuración** dice `s3`, que es lo que mira el comando para decidir si
     * comprueba la subida del navegador. Así se ejercita ese camino sin salir a la red.
     */
    private function fakeBucket(string $diskName = 'bucket-prueba'): void
    {
        Storage::fake($diskName);

        // Se cambia la configuración **después** de `fake()` para no alterar el disco
        // que éste construye.
        config()->set('filesystems.disks.'.$diskName, [
            'driver' => 's3',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'region' => 'eu-west-1',
            'bucket' => 'bucket-de-prueba',
            'visibility' => 'private',
        ]);

        config()->set('media.disks.originals', $diskName);
        config()->set('livewire.temporary_file_upload.disk', $diskName);
        config()->set('app.url', 'https://panel.test');

        $this->fakeSignedUpload();
    }

    /**
     * Sustituye la firma de Livewire por una URL fija, para controlar qué responde el
     * bucket.
     *
     * Se sustituye el servicio en lugar de firmar de verdad porque lo que se prueba no
     * es la firma de AWS —eso lo hace el SDK— sino la comprobación que la rodea: el
     * preflight y la interpretación del error.
     */
    private function fakeSignedUpload(): void
    {
        $fake = new class extends GenerateSignedUploadUrl
        {
            public function forS3($file, $visibility = 'private')
            {
                return [
                    'path' => 'livewire-tmp/prueba.jpg',
                    'url' => 'https://bucket-de-prueba.s3.eu-west-1.amazonaws.com/livewire-tmp/prueba.jpg'
                        .'?x-amz-acl=private&X-Amz-Signature=FIRMA-QUE-NO-DEBE-IMPRIMIRSE',
                    'headers' => [
                        'Host' => ['bucket-de-prueba.s3.eu-west-1.amazonaws.com'],
                        'x-amz-acl' => ['private'],
                        'Content-Type' => 'image/jpeg',
                    ],
                ];
            }
        };

        app()->instance(GenerateSignedUploadUrl::class, $fake);

        // Livewire deja un doble propio resuelto en el facade durante las pruebas
        // (`SupportFileUploads::provide()`), y el facade sirve **su** instancia
        // cacheada antes de mirar el contenedor: sin este borrado, la sustitución no
        // tendría efecto y las pruebas pasarían sin ejercitar nada.
        Facade::clearResolvedInstance(GenerateSignedUploadUrl::class);
    }

    /**
     * El fallo que motivó esta comprobación: sin política CORS el navegador bloquea la
     * subida **antes de enviarla**, así que el servidor no registra nada y el panel sólo
     * muestra «failed to upload». El comando debe detectarlo y entregar la política.
     */
    public function test_detecta_la_falta_de_politica_cors_y_entrega_la_politica(): void
    {
        $this->fakeBucket();

        // Un bucket sin CORS responde al preflight sin la cabecera que el navegador
        // necesita, y con un 403 que no hay que confundir con un fallo de permisos.
        Http::fake(fn () => Http::response('', 403));

        $this->artisan('storage:check')
            ->expectsOutputToContain('El bucket no tiene política CORS')
            // Las expectativas se consumen **en orden**, una por línea, así que se
            // pide cada dato en la línea exacta donde se imprime: la política debe
            // llevar el origen real y las cabeceras que el navegador envía.
            ->expectsOutputToContain('"AllowedOrigins": ["https://panel.test"]')
            ->expectsOutputToContain('"AllowedMethods": ["PUT"]')
            ->expectsOutputToContain('"AllowedHeaders": ["x-amz-acl", "content-type"]')
            ->assertExitCode(1);
    }

    public function test_con_politica_cors_la_subida_del_navegador_pasa(): void
    {
        $this->fakeBucket();

        Http::fake(function ($request) {
            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, [
                    'Access-Control-Allow-Origin' => 'https://panel.test',
                    'Access-Control-Allow-Methods' => 'PUT',
                ]);
            }

            return Http::response('', 200);
        });

        $this->artisan('storage:check')
            ->expectsOutputToContain('CORS: El bucket permite el PUT')
            ->expectsOutputToContain('PUT firmado: El bucket ha aceptado')
            ->assertExitCode(0);
    }

    /**
     * El segundo fallo, que aparece **después** de arreglar CORS: el bucket tiene las
     * ACL deshabilitadas y la firma de Livewire envía «x-amz-acl: private».
     */
    public function test_explica_el_rechazo_por_acl_deshabilitadas(): void
    {
        $this->fakeBucket();

        Http::fake(function ($request) {
            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, ['Access-Control-Allow-Origin' => 'https://panel.test']);
            }

            return Http::response(
                '<Error><Code>AccessControlListNotSupported</Code><Message>Bad Request</Message></Error>',
                400,
            );
        });

        $this->artisan('storage:check')
            ->expectsOutputToContain('ACL deshabilitadas')
            ->assertExitCode(1);
    }

    /**
     * El comando no debe filtrar la URL firmada: lleva la access key en claro y una
     * firma válida durante 15 minutos. Es el mismo criterio que con el secreto.
     */
    public function test_no_imprime_la_url_firmada_ni_su_firma(): void
    {
        $this->fakeBucket();

        Http::fake(function ($request) {
            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, ['Access-Control-Allow-Origin' => 'https://panel.test']);
            }

            return Http::response('', 200);
        });

        $this->artisan('storage:check')
            ->doesntExpectOutputToContain('FIRMA-QUE-NO-DEBE-IMPRIMIRSE')
            ->doesntExpectOutputToContain('X-Amz-Signature')
            ->assertExitCode(0);
    }

    /**
     * El preflight se pregunta por el método y las cabeceras reales: si el comando
     * preguntara por otra cosa, daría por bueno un bucket que el navegador rechaza.
     */
    public function test_el_preflight_se_pregunta_por_put_y_la_cabecera_acl(): void
    {
        $this->fakeBucket();

        $sent = [];

        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request;

            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, ['Access-Control-Allow-Origin' => 'https://panel.test']);
            }

            return Http::response('', 200);
        });

        $this->artisan('storage:check')->assertExitCode(0);

        $this->assertSame('OPTIONS', $sent[0]->method());

        // Los nombres de cabecera no distinguen mayúsculas: se comparan en minúsculas
        // para que la prueba no dependa de cómo los escriba el cliente.
        $headers = array_change_key_case($sent[0]->headers(), CASE_LOWER);

        $this->assertSame(['PUT'], $headers['access-control-request-method']);
        $this->assertSame(['x-amz-acl'], $headers['access-control-request-headers']);
        $this->assertSame(['https://panel.test'], $headers['origin']);

        // Y después del preflight se envía el PUT de verdad.
        $this->assertSame('PUT', $sent[1]->method());
    }

    /**
     * El objeto de prueba se borra del bucket aunque el PUT falle, para no dejar basura
     * en `livewire-tmp`.
     */
    public function test_limpia_el_objeto_de_prueba_aunque_falle_la_subida(): void
    {
        $this->fakeBucket();

        Http::fake(function ($request) {
            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, ['Access-Control-Allow-Origin' => 'https://panel.test']);
            }

            return Http::response('<Error><Code>AccessDenied</Code></Error>', 403);
        });

        $this->artisan('storage:check')->assertExitCode(1);

        Storage::disk('bucket-prueba')->assertMissing('livewire-tmp/prueba.jpg');
    }

    public function test_un_disco_inexistente_lo_dice(): void
    {
        $this->artisan('storage:check', ['--disk' => 'no-existe'])
            ->expectsOutputToContain('no existe en config/filesystems.php')
            ->assertExitCode(1);
    }
}

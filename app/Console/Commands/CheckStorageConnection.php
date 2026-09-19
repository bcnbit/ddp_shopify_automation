<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Security\SecretRedactor;
use Aws\S3\Exception\PermanentRedirectException;
use Facades\Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Comprueba que el almacenamiento en bucket funciona de verdad (RFC-0007).
 *
 * Es el equivalente de `shopify:check` para S3: un diagnóstico que **escribe y
 * borra un único objeto de prueba**, sin tocar nada del catálogo.
 *
 * Responde a las preguntas que importan antes de activar el bucket:
 *
 * | Pregunta | Cómo se responde |
 * |---|---|
 * | ¿Están puestas las credenciales? | Se leen de la configuración, sin imprimirlas |
 * | ¿Son válidas? | Se pide listar; una clave inválida da 403 |
 * | ¿Existe el bucket y es el correcto? | `NoSuchBucket` o redirección de región |
 * | ¿Puedo escribir? | Se sube un objeto de prueba |
 * | ¿Puedo leer lo escrito? | Se lee y se compara con lo enviado |
 * | ¿Puedo firmar URLs? | Se firma una, que es lo que usa el panel para mostrar miniaturas |
 * | ¿Puedo borrar? | Se borra el objeto de prueba |
 * | ¿Puede subir el navegador? | Se simula el preflight CORS del `PUT` |
 * | ¿Acepta el bucket el PUT firmado? | Se envía uno de prueba y se borra |
 *
 * La prueba usa un nombre aleatorio dentro de `healthcheck/` y lo limpia **siempre**,
 * incluso si algo falla a mitad. Nunca toca `products/originals`.
 *
 * Y nunca imprime una credencial: sólo si está puesta y cuánto mide.
 */
class CheckStorageConnection extends Command
{
    protected $signature = 'storage:check {--disk= : Disco a comprobar (por defecto, el de originales)}';

    protected $description = 'Comprueba credenciales y permisos del almacenamiento de medios';

    public function handle(): int
    {
        $diskName = (string) ($this->option('disk') ?: config('media.disks.originals', 'media'));
        $temporaryDisk = (string) (config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));

        $this->newLine();
        $this->line('  Configuración');
        $this->line('  -------------');
        $this->line('  Disco de originales : '.$diskName);
        $this->line('  Disco temporal      : '.$temporaryDisk);

        $config = config('filesystems.disks.'.$diskName);

        if (! is_array($config)) {
            $this->error('  El disco «'.$diskName.'» no existe en config/filesystems.php.');

            return self::FAILURE;
        }

        $driver = (string) ($config['driver'] ?? '');
        $this->line('  Driver              : '.($driver !== '' ? $driver : '(vacío)'));

        if ($driver !== 's3') {
            // Un disco local también es válido: se informa y se sigue. Este comando
            // describe el estado, no exige S3.
            $this->newLine();
            $this->warn('  El disco no es S3: se comprueba como disco local.');
            $this->line('  Para usar un bucket: PRODUCT_STUDIO_MEDIA_DISK=media-s3');

            return $this->checkLocal($diskName);
        }

        // Credenciales: se informa de si están y de su longitud, nunca del valor.
        $this->line('  Access key          : '.$this->describeSecret((string) ($config['key'] ?? '')));
        $this->line('  Secret key          : '.$this->describeSecret((string) ($config['secret'] ?? '')));
        $this->line('  Región              : '.((string) ($config['region'] ?? '') ?: '(vacía)'));
        $this->line('  Bucket              : '.((string) ($config['bucket'] ?? '') ?: '(vacío)'));
        $this->line('  Endpoint            : '.((string) ($config['endpoint'] ?? '') ?: '(por defecto de AWS)'));
        $this->line('  Path style          : '.((($config['use_path_style_endpoint'] ?? false) === true) ? 'sí' : 'no'));

        $missing = $this->missingCredentials($config);

        if ($missing !== []) {
            $this->newLine();
            $this->error('  Faltan datos para usar el bucket: '.implode(', ', $missing));
            $this->newLine();
            $this->line('  Rellena estas claves en .env (nunca en Git) y repite:');
            $this->line('    AWS_ACCESS_KEY_ID=...');
            $this->line('    AWS_SECRET_ACCESS_KEY=...');
            $this->line('    AWS_DEFAULT_REGION=eu-west-1');
            $this->line('    AWS_BUCKET=nombre-del-bucket');
            $this->newLine();
            $this->line('  Y activa el disco: PRODUCT_STUDIO_MEDIA_DISK=media-s3');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Comprobación de permisos');
        $this->line('  -------------------------');

        $failed = $this->printResults($this->probe($diskName));

        if ($failed) {
            $this->newLine();
            $this->error('  El bucket no está listo para usarse.');
            $this->newLine();

            return self::FAILURE;
        }

        // El disco temporal es el que usa la **subida**: sin comprobarlo, el comando
        // podía decir «Bucket OK» y la subida fallar igualmente. Fue un fallo real de
        // este comando: comprobaba los originales y sólo avisaba del temporal.
        $temporaryIsS3 = $this->isS3Disk($temporaryDisk);

        if ($temporaryIsS3 && $temporaryDisk !== $diskName) {
            $this->newLine();
            $this->line('  Comprobación del disco temporal ('.$temporaryDisk.')');
            $this->line('  -------------------------------------------------');

            if ($this->printResults($this->probe($temporaryDisk))) {
                $failed = true;
            }
        }

        // La subida del navegador no se parece a `put()` de Flysystem: va firmada,
        // con la cabecera `x-amz-acl`, y **desde otro origen**, así que el navegador
        // lanza antes un preflight. Son dos comprobaciones que sólo aparecen aquí, y
        // son justo las que fallan al subir la primera imagen.
        if ($temporaryIsS3) {
            $this->newLine();
            $this->line('  Comprobación de la subida del navegador');
            $this->line('  ----------------------------------------');

            $directResults = $this->probeDirectUpload($temporaryDisk);

            if ($this->printResults($directResults)) {
                $failed = true;

                // El arreglo no está en el código sino en el bucket, así que el
                // comando entrega la política exacta que hay que pegar.
                $this->suggestCorsPolicy($directResults);
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('  El almacenamiento no está listo: hay comprobaciones en rojo.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Almacenamiento OK.');
        $this->line('  Credenciales válidas y permisos de lectura, escritura, borrado y firma.');

        if ($temporaryIsS3) {
            $this->line('  La subida directa de Livewire está activa: el archivo va al bucket sin pasar por el servidor.');
        } else {
            $this->newLine();
            $this->warn('  El disco temporal de Livewire NO es un bucket: '.$temporaryDisk);
            $this->line('  Las subidas siguen pasando por el servidor y sufren los límites de PHP.');
            $this->line('  Para subir directo al bucket: LIVEWIRE_TEMPORARY_UPLOAD_DISK=s3');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Imprime los resultados de una comprobación y dice si alguno falló.
     *
     * @param  list<array{label: string, ok: bool, detail: string}>  $results
     */
    private function printResults(array $results): bool
    {
        $failed = false;

        foreach ($results as $result) {
            $this->line('  ['.($result['ok'] ? 'OK   ' : 'FALLO').'] '.$result['label'].': '.$result['detail']);

            if (! $result['ok']) {
                $failed = true;
            }
        }

        return $failed;
    }

    /**
     * Comprueba lo que hace el navegador al subir: un `PUT` **firmado**, con la
     * cabecera `x-amz-acl`, y **desde otro origen**.
     *
     * Es un camino distinto del que ejercita `probe()`: Flysystem firma la petición
     * con SigV4 en el servidor, mientras que aquí la URL se firma para que la ejecute
     * un tercero (el navegador de la persona que sube la imagen). Por eso hay dos
     * fallos que sólo se manifiestan en la subida real:
     *
     * 1. **CORS.** Un `PUT` con `x-amz-acl` no es una petición simple, así que el
     *    navegador lanza antes un `OPTIONS` (preflight). Si el bucket no tiene
     *    política CORS, S3 responde sin `Access-Control-Allow-Origin` y el navegador
     *    bloquea la subida **sin llegar a enviarla**: el panel muestra «failed to
     *    upload» y el servidor no ve nada. Es el fallo que motivó esta comprobación.
     * 2. **ACL.** Livewire firma `ACL => private`. En un bucket con las ACL
     *    deshabilitadas (ajuste por defecto de los buckets nuevos), S3 rechaza
     *    cualquier ACL explícita con `AccessControlListNotSupported`.
     *
     * La comprobación del preflight se hace **desde el servidor**: es la única forma
     * de saber si el bucket devuelve la cabecera, y no depende de tener un navegador.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    private function probeDirectUpload(string $diskName): array
    {
        $results = [];

        try {
            $payload = $this->signedUploadPayload($diskName);
        } catch (Throwable $exception) {
            return [[
                'label' => 'Firma de la subida',
                'ok' => false,
                'detail' => $this->explain($exception),
            ]];
        }

        $url = (string) ($payload['url'] ?? '');

        if ($url === '') {
            return [[
                'label' => 'Firma de la subida',
                'ok' => false,
                'detail' => 'No se ha podido firmar la URL de subida.',
            ]];
        }

        $results[] = [
            'label' => 'Firma de la subida',
            'ok' => true,
            'detail' => 'Se ha generado una URL firmada como la que recibe el navegador.',
        ];

        $results[] = $this->probeCors($url);
        $results[] = $this->probeSignedPut($url, (array) ($payload['headers'] ?? []));

        return $results;
    }

    /**
     * Reproduce el preflight que hace el navegador antes de subir.
     *
     * Se envía el mismo `OPTIONS` que enviaría el navegador y se comprueba que la
     * respuesta traiga `Access-Control-Allow-Origin`. Sin esa cabecera el navegador
     * descarta la subida, así que la comprobación es exactamente la que hace él.
     *
     * @return array{label: string, ok: bool, detail: string}
     */
    private function probeCors(string $url): array
    {
        $origin = $this->panelOrigin();

        try {
            $response = Http::withHeaders([
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'PUT',
                'Access-Control-Request-Headers' => 'x-amz-acl',
            ])
                ->timeout(15)
                ->send('OPTIONS', $url);

            $allowOrigin = (string) ($response->header('Access-Control-Allow-Origin') ?? '');

            if ($allowOrigin !== '') {
                return [
                    'label' => 'CORS',
                    'ok' => true,
                    'detail' => 'El bucket permite el PUT desde '.$origin.'.',
                ];
            }

            return [
                'label' => 'CORS',
                'ok' => false,
                'detail' => 'El bucket no tiene política CORS: el navegador bloqueará la subida antes de enviarla.',
            ];
        } catch (Throwable $exception) {
            return [
                'label' => 'CORS',
                'ok' => false,
                'detail' => 'No se ha podido comprobar el preflight: '.$this->explain($exception),
            ];
        }
    }

    /**
     * Envía un `PUT` real con la firma que recibe el navegador y borra el objeto.
     *
     * El preflight puede pasar y la subida fallar igualmente: S3 valida la firma y el
     * ACL **al ejecutar el PUT**. Esta comprobación distingue un bucket listo de uno
     * que sólo lo parece, y es la que detecta `AccessControlListNotSupported`.
     *
     * @param  array<string, mixed>  $headers
     * @return array{label: string, ok: bool, detail: string}
     */
    private function probeSignedPut(string $url, array $headers): array
    {
        // El objeto de prueba se sube a la ruta firmada (dentro de livewire-tmp) y se
        // borra acto seguido: no queda nada en el bucket.
        $path = $this->pathFromSignedUrl($url);

        try {
            $requestHeaders = [];

            foreach ($headers as $name => $value) {
                // El navegador elimina `Host` antes de enviar (lo añade él mismo) y
                // Livewire hace lo mismo; enviarlo a mano invalidaría la firma.
                if (strtolower((string) $name) === 'host') {
                    continue;
                }

                $requestHeaders[(string) $name] = is_array($value) ? implode(',', $value) : (string) $value;
            }

            $response = Http::withHeaders($requestHeaders)
                ->withBody('comprobacion-'.now()->timestamp, 'image/jpeg')
                ->timeout(30)
                ->send('PUT', $url);

            if ($response->successful()) {
                return [
                    'label' => 'PUT firmado',
                    'ok' => true,
                    'detail' => 'El bucket ha aceptado una subida firmada idéntica a la del navegador.',
                ];
            }

            return [
                'label' => 'PUT firmado',
                'ok' => false,
                'detail' => $this->explainSignedPutFailure($response->status(), $response->body()),
            ];
        } catch (Throwable $exception) {
            return [
                'label' => 'PUT firmado',
                'ok' => false,
                'detail' => $this->explain($exception),
            ];
        } finally {
            // Se limpia por el disco, no por la URL: borrar es otra operación firmada y
            // no depende de que la subida haya funcionado.
            if ($path !== null) {
                try {
                    Storage::disk($this->temporaryDiskName())->delete($path);
                } catch (Throwable) {
                    // Un objeto huérfano en livewire-tmp no justifica fallar el
                    // diagnóstico: el bucket lo limpia por su ciclo de vida.
                }
            }
        }
    }

    /**
     * Traduce el fallo del PUT firmado, que **no** pasa por el SDK y por tanto no trae
     * un código de error de AWS: llega como XML en el cuerpo de la respuesta.
     */
    private function explainSignedPutFailure(int $status, string $body): string
    {
        return match ($this->errorCodeFromXml($body)) {
            'AccessControlListNotSupported' => 'El bucket tiene las ACL deshabilitadas y la firma envía «x-amz-acl: private». Habilita las ACL del bucket o quita el ACL de la firma.',
            'AccessDenied' => 'El bucket ha denegado el PUT firmado: revisa su política.',
            'SignatureDoesNotMatch' => 'La firma no coincide: revisa AWS_SECRET_ACCESS_KEY.',
            default => match ($status) {
                403 => 'El bucket ha denegado el PUT firmado (403).',
                400 => 'El bucket ha rechazado el PUT firmado (400).',
                default => 'El bucket ha rechazado el PUT firmado ('.$status.').',
            },
        };
    }

    /**
     * Código de error de un XML de S3, sin exigir que el XML sea válido.
     */
    private function errorCodeFromXml(string $body): ?string
    {
        if (preg_match('/<Code>([^<]+)<\/Code>/', $body, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Ruta del objeto a partir de la URL firmada, para poder borrarlo después.
     */
    private function pathFromSignedUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim(rawurldecode($path), '/');

        // Con `use_path_style_endpoint` el nombre del bucket va en la ruta; el disco
        // ya lo aplica al borrar, así que se recorta para no duplicarlo.
        $bucket = (string) config('filesystems.disks.'.$this->temporaryDiskName().'.bucket', '');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            $path = substr($path, strlen($bucket) + 1);
        }

        return $path === '' ? null : $path;
    }

    /**
     * Nombre del disco temporal de Livewire.
     */
    private function temporaryDiskName(): string
    {
        return (string) (config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));
    }

    /**
     * Origen del panel: el que el bucket debe permitir en su política CORS.
     *
     * Se toma de `app.url` porque es el origen desde el que el navegador sube, y es
     * justo el valor que debe aparecer en `AllowedOrigins`.
     */
    private function panelOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Firma una subida igual que la firma Livewire para el navegador.
     *
     * Se reutiliza el mismo servicio en lugar de firmar por cuenta propia: así la
     * comprobación no puede quedarse obsoleta si Livewire cambia su forma de firmar.
     *
     * @return array{url?: string, headers?: array<string, mixed>, path?: string}
     */
    private function signedUploadPayload(string $diskName): array
    {
        // Livewire firma contra **su** disco temporal, que es el que se está
        // comprobando. Si no coincidiera, la URL apuntaría a otro bucket y la
        // comprobación diría que todo va bien sin haber mirado el correcto.
        if ($this->temporaryDiskName() !== $diskName) {
            config()->set('livewire.temporary_file_upload.disk', $diskName);
        }

        $file = UploadedFile::fake()->create('prueba.jpg', 10, 'image/jpeg');

        /** @var array{url?: string, headers?: array<string, mixed>, path?: string} $payload */
        $payload = GenerateSignedUploadUrl::forS3($file);

        return $payload;
    }

    /**
     * Entrega la política CORS que hay que aplicar en el bucket.
     *
     * El arreglo no está en el código, así que el comando no puede aplicarlo: lo que
     * sí puede hacer es dar el texto exacto y evitar que se copie a mano mal.
     *
     * @param  list<array{label: string, ok: bool, detail: string}>  $results
     */
    private function suggestCorsPolicy(array $results): void
    {
        $corsFailed = false;

        foreach ($results as $result) {
            if ($result['label'] === 'CORS' && ! $result['ok']) {
                $corsFailed = true;
            }
        }

        if (! $corsFailed) {
            return;
        }

        $origin = $this->panelOrigin();

        $this->newLine();
        $this->line('  El bucket necesita una política CORS. En la consola de S3:');
        $this->line('  Permissions -> Cross-origin resource sharing (CORS) -> Edit');
        $this->newLine();
        $this->line('  [');
        $this->line('    {');
        $this->line('      "AllowedOrigins": ["'.$origin.'"],');
        $this->line('      "AllowedMethods": ["PUT"],');
        $this->line('      "AllowedHeaders": ["x-amz-acl", "content-type"],');
        $this->line('      "ExposeHeaders": ["ETag"],');
        $this->line('      "MaxAgeSeconds": 3000');
        $this->line('    }');
        $this->line('  ]');
        $this->newLine();
        $this->line('  Si el dominio de producción es distinto, añádelo también a AllowedOrigins.');
        $this->newLine();
    }

    /**
     * ¿El disco existe y es de tipo S3?
     */
    private function isS3Disk(string $name): bool
    {
        return (string) (config('filesystems.disks.'.$name.'.driver') ?? '') === 's3';
    }

    /**
     * Escribe, lee, firma y borra un objeto de prueba.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    private function probe(string $diskName): array
    {
        $results = [];
        $path = 'healthcheck/prueba-'.bin2hex(random_bytes(8)).'.txt';
        $contents = 'comprobacion-'.now()->timestamp;
        $written = false;

        try {
            $disk = Storage::disk($diskName);

            // 1) Listar. Es la comprobación más barata de que la credencial es válida
            // y de que el bucket existe.
            try {
                $disk->files('healthcheck');

                $results[] = [
                    'label' => 'Credenciales y bucket',
                    'ok' => true,
                    'detail' => 'La clave es válida y el bucket responde.',
                ];
            } catch (PermanentRedirectException) {
                // Error clásico: la región configurada no es la del bucket, y AWS
                // responde redirigiendo en lugar de aceptar la petición.
                $results[] = [
                    'label' => 'Credenciales y bucket',
                    'ok' => false,
                    'detail' => 'El bucket está en otra región. Revisa AWS_DEFAULT_REGION.',
                ];

                return $results;
            } catch (Throwable $exception) {
                $results[] = [
                    'label' => 'Credenciales y bucket',
                    'ok' => false,
                    'detail' => $this->explain($exception),
                ];

                return $results;
            }

            // 2) Escribir. Sin esto, el panel fallaría al subir la primera imagen.
            try {
                $written = $disk->put($path, $contents) !== false;

                $results[] = [
                    'label' => 'Escritura',
                    'ok' => $written,
                    'detail' => $written
                        ? 'Se ha subido un objeto de prueba.'
                        : 'La subida no ha confirmado que el objeto se guardara.',
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'label' => 'Escritura',
                    'ok' => false,
                    'detail' => $this->explain($exception),
                ];

                return $results;
            }

            // 3) Leer. Un permiso de escritura sin lectura dejaría archivos de los que
            // no se podrían recuperar los bytes para enviarlos a Shopify.
            try {
                $read = $disk->get($path);

                $results[] = [
                    'label' => 'Lectura',
                    'ok' => $read === $contents,
                    'detail' => $read === $contents
                        ? 'El objeto se lee y coincide con lo escrito.'
                        : 'El objeto se leyó, pero su contenido no coincide.',
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'label' => 'Lectura',
                    'ok' => false,
                    'detail' => $this->explain($exception),
                ];
            }

            // 4) Firmar una URL. Es lo que usa el panel para mostrar una miniatura de
            // un bucket privado: sin esto, las imágenes saldrían rotas.
            try {
                $url = $disk->temporaryUrl($path, now()->addMinutes(5));
                $signed = is_string($url) && $url !== '';

                $results[] = [
                    'label' => 'URL firmada',
                    'ok' => $signed,
                    'detail' => $signed
                        ? 'Se ha generado una URL temporal para el bucket privado.'
                        : 'No se ha podido firmar una URL.',
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'label' => 'URL firmada',
                    'ok' => false,
                    'detail' => $this->explain($exception),
                ];
            }
        } finally {
            // El objeto de prueba se borra siempre, también si algo falló antes.
            if ($written) {
                try {
                    Storage::disk($diskName)->delete($path);

                    $results[] = [
                        'label' => 'Borrado',
                        'ok' => true,
                        'detail' => 'El objeto de prueba se ha eliminado.',
                    ];
                } catch (Throwable $exception) {
                    $results[] = [
                        'label' => 'Borrado',
                        'ok' => false,
                        'detail' => $this->explain($exception).' Queda un objeto en healthcheck/.',
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Comprobación mínima de un disco local: sólo que se pueda escribir y borrar.
     */
    private function checkLocal(string $diskName): int
    {
        try {
            $path = 'healthcheck/prueba-'.bin2hex(random_bytes(6)).'.txt';
            $disk = Storage::disk($diskName);

            if ($disk->put($path, 'prueba') === false) {
                $this->error('  No se ha podido escribir en el disco.');

                return self::FAILURE;
            }

            $disk->delete($path);

            $this->info('  El disco local permite lectura y escritura.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('  El disco no es escribible: '.$this->explain($exception));

            return self::FAILURE;
        }
    }

    /**
     * Traduce el fallo a algo accionable, en lugar de volcar el mensaje crudo del SDK.
     */
    private function explain(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // El SDK de AWS envuelve su excepción dentro de una de Flysystem, así que el
        // código de error no está en la excepción que se recibe: hay que recorrer la
        // cadena de causas hasta encontrarlo. Buscarlo sólo en el primer nivel era el
        // motivo de que un bucket inexistente diera un mensaje genérico.
        $code = $this->awsErrorCode($exception);

        return match (true) {
            $code === 'NoSuchBucket' => 'El bucket no existe. Revisa AWS_BUCKET.',
            $code === 'AccessDenied' => 'Acceso denegado: revisa las credenciales y la política del bucket.',
            $code === 'SignatureDoesNotMatch' => 'La firma no coincide: revisa AWS_SECRET_ACCESS_KEY.',
            $code === 'InvalidAccessKeyId' => 'La access key no existe o es incorrecta.',
            $code === 'PermanentRedirect' => 'El bucket está en otra región. Revisa AWS_DEFAULT_REGION.',
            $code === 'RequestTimeTooSkewed' => 'La hora del servidor no coincide con la de AWS.',
            $code === 'NoSuchKey' => 'El objeto no existe.',
            str_contains($message, 'cURL') || str_contains($message, 'resolve host') => 'No se ha podido contactar con el endpoint. Revisa AWS_ENDPOINT.',
            // El mensaje crudo del SDK incluye la URL firmada y varias líneas de
            // contexto: se resume a la primera línea útil y se recorta. Nunca debe
            // llegar una firma a la pantalla ni a un log.
            default => 'Fallo: '.$this->firstLine($message),
        };
    }

    /**
     * Código de error de AWS, buscándolo por toda la cadena de causas.
     *
     * Flysystem envuelve la excepción del SDK para no acoplar su API, de modo que el
     * objeto que llega aquí es un envoltorio. El código real (`NoSuchBucket`,
     * `AccessDenied`…) está más abajo.
     */
    private function awsErrorCode(Throwable $exception): ?string
    {
        $current = $exception;
        $depth = 0;

        // Se limita la profundidad para no recorrer una cadena circular.
        while ($current !== null && $depth < 10) {
            if (method_exists($current, 'getAwsErrorCode')) {
                /** @var mixed $code */
                $code = $current->getAwsErrorCode();

                if (is_string($code) && $code !== '') {
                    return $code;
                }
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return null;
    }

    /**
     * Primera línea del error, redactada y recortada.
     *
     * El SDK de AWS devuelve varias líneas con la URL firmada dentro. Se toma sólo
     * la primera frase, se pasa por el redactor de secretos y se limita la longitud:
     * lo que se muestra debe ser útil sin filtrar nada.
     */
    private function firstLine(string $message): string
    {
        $first = trim(strtok($message, "\n") ?: $message);

        $redacted = app(SecretRedactor::class)->redactString($first) ?? $first;

        return mb_substr($redacted, 0, 140);
    }

    private function describeSecret(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '(vacía)' : 'presente ('.strlen($value).' caracteres)';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function missingCredentials(array $config): array
    {
        $missing = [];

        /** @var array<string, string> $map */
        $map = [
            'key' => 'AWS_ACCESS_KEY_ID',
            'secret' => 'AWS_SECRET_ACCESS_KEY',
            'bucket' => 'AWS_BUCKET',
        ];

        foreach ($map as $field => $variable) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                $missing[] = $variable;
            }
        }

        return $missing;
    }
}

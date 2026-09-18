<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Security\SecretRedactor;
use Aws\S3\Exception\PermanentRedirectException;
use Illuminate\Console\Command;
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

        $results = $this->probe($diskName);
        $failed = false;

        foreach ($results as $result) {
            $this->line('  ['.($result['ok'] ? 'OK   ' : 'FALLO').'] '.$result['label'].': '.$result['detail']);

            if (! $result['ok']) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('  El bucket no está listo para usarse.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Bucket OK.');
        $this->line('  Credenciales válidas, bucket accesible y permisos de lectura, escritura y borrado.');

        if ($temporaryDisk === $diskName || $temporaryDisk === 's3') {
            $this->line('  La subida directa de Livewire ya está activa.');
        } else {
            $this->newLine();
            $this->warn('  El disco temporal de Livewire NO es el bucket: '.$temporaryDisk);
            $this->line('  Las subidas siguen pasando por el servidor y sufren los límites de PHP.');
            $this->line('  Para subir directo al bucket: LIVEWIRE_TEMPORARY_UPLOAD_DISK=s3');
        }

        $this->newLine();

        return self::SUCCESS;
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

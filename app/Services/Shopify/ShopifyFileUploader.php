<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\DataObjects\Shopify\ShopifyUploadedFile;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Subida de medios a Shopify en dos pasos (RFC-0004).
 *
 * Los originales viven en un disco privado, así que Shopify no puede
 * descargarlos por URL: hay que reservar una URL temporal (`stagedUploadsCreate`),
 * subir los bytes y convertir el resultado en un recurso (`fileCreate`).
 *
 * El `sha256` del archivo local es la clave de reutilización: si una ejecución
 * anterior ya subió esta imagen, se reutiliza su GID en lugar de volver a
 * subirla (RFC-0004: «nunca repetir una carga de medio con mismo sha256»).
 */
class ShopifyFileUploader
{
    public function __construct(private readonly ShopifyGraphQlClient $client) {}

    /**
     * Sube un archivo local y devuelve su GID de Shopify.
     *
     * @param  string  $disk  disco de Laravel donde vive el original
     * @param  string  $path  ruta dentro del disco
     * @param  string  $filename  nombre con el que quedará en Shopify
     * @param  string  $mimeType  tipo MIME real del archivo
     */
    public function upload(string $disk, string $path, string $filename, string $mimeType): ShopifyUploadedFile
    {
        $bytes = $this->read($disk, $path);

        $target = $this->createStagedTarget($filename, $mimeType);

        $this->pushToStagedTarget($target['url'], $target['parameters'], $bytes, $filename, $mimeType);

        $gid = $this->createFile($target['resourceUrl'], $filename);

        return new ShopifyUploadedFile(
            gid: $gid,
            filename: $filename,
            resourceUrl: $target['resourceUrl'],
        );
    }

    /**
     * Lee el original y falla de forma legible si ya no está en el disco.
     */
    private function read(string $disk, string $path): string
    {
        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                throw ShopifyRequestFailed::permanent(
                    "No se encuentra el archivo de imagen «{$path}». Vuelve a subirlo.",
                    'media_missing',
                );
            }

            $bytes = $storage->get($path);
        } catch (ShopifyRequestFailed $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw ShopifyRequestFailed::retryable(
                'No se ha podido leer el archivo de imagen. Se reintentará.',
                'media_unreadable',
                previous: $exception,
            );
        }

        if (! is_string($bytes) || $bytes === '') {
            throw ShopifyRequestFailed::permanent(
                "El archivo de imagen «{$path}» está vacío. Vuelve a subirlo.",
                'media_empty',
            );
        }

        return $bytes;
    }

    /**
     * Reserva la URL temporal de subida.
     *
     * @return array{url: string, resourceUrl: string, parameters: array<int, array{name: string, value: string}>}
     */
    private function createStagedTarget(string $filename, string $mimeType): array
    {
        $data = $this->client->query('stagedUploadsCreate', ShopifyOperations::STAGED_UPLOADS_CREATE, [
            'input' => [[
                'filename' => $filename,
                'mimeType' => $mimeType,
                'resource' => 'IMAGE',
                'httpMethod' => 'POST',
            ]],
        ]);

        $payload = $data['stagedUploadsCreate'] ?? null;

        if (! is_array($payload)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha preparado la subida de la imagen.',
                'staged_upload_missing',
            );
        }

        $this->client->throwIfUserErrors('preparar la subida de la imagen', $this->userErrors($payload));

        $target = $payload['stagedTargets'][0] ?? null;

        if (! is_array($target) || ! is_string($target['url'] ?? null) || ! is_string($target['resourceUrl'] ?? null)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha devuelto una URL de subida válida.',
                'staged_upload_invalid',
            );
        }

        return [
            'url' => $target['url'],
            'resourceUrl' => $target['resourceUrl'],
            'parameters' => $this->parameters($target['parameters'] ?? []),
        ];
    }

    /**
     * Sube los bytes al almacenamiento temporal de Shopify.
     *
     * @param  array<int, array{name: string, value: string}>  $parameters
     */
    private function pushToStagedTarget(string $url, array $parameters, string $bytes, string $filename, string $mimeType): void
    {
        $request = Http::asMultipart()
            ->timeout((int) config('product-studio.shopify.upload_timeout', 120))
            ->connectTimeout((int) config('product-studio.shopify.connect_timeout', 10));

        foreach ($parameters as $parameter) {
            $request = $request->attach($parameter['name'], $parameter['value']);
        }

        // El archivo va siempre el último: la firma de la URL temporal sólo es
        // válida si el resto de campos la preceden.
        $request = $request->attach('file', $bytes, $filename, ['Content-Type' => $mimeType]);

        try {
            $response = $request->post($url);
        } catch (ConnectionException $exception) {
            throw ShopifyRequestFailed::retryable(
                'No se ha podido subir la imagen a Shopify. Se reintentará.',
                'media_upload_failed',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw ShopifyRequestFailed::retryable(
                'Shopify ha rechazado la subida de la imagen. Se reintentará.',
                'media_upload_rejected',
            );
        }
    }

    /**
     * Convierte el archivo subido en un recurso de la tienda.
     */
    private function createFile(string $resourceUrl, string $filename): string
    {
        $data = $this->client->query('fileCreate', ShopifyOperations::FILE_CREATE, [
            'files' => [[
                'originalSource' => $resourceUrl,
                'filename' => $filename,
                'contentType' => 'IMAGE',
                // APPEND_UUID en lugar de REPLACE: con REPLACE Shopify no aplica
                // el ALT en el mismo paso, y el ALT es obligatorio (RFC-0005).
                'duplicateResolutionMode' => 'APPEND_UUID',
            ]],
        ]);

        $payload = $data['fileCreate'] ?? null;

        if (! is_array($payload)) {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha confirmado la creación de la imagen.',
                'file_create_missing',
            );
        }

        $this->client->throwIfUserErrors('crear el recurso de imagen', $this->userErrors($payload));

        $gid = $payload['files'][0]['id'] ?? null;

        if (! is_string($gid) || $gid === '') {
            throw ShopifyRequestFailed::permanent(
                'Shopify no ha devuelto el identificador de la imagen subida.',
                'file_create_invalid',
            );
        }

        return $gid;
    }

    /**
     * Espera a que Shopify termine de procesar los ficheros.
     *
     * Un fichero en `UPLOADED` o `PROCESSING` todavía no se puede asociar al
     * producto. Se espera un número limitado de veces para no bloquear un worker
     * indefinidamente: si no está listo, se devuelve lo que haya y la
     * sincronización queda reintentable (RFC-0004: el reintento continúa).
     *
     * @param  list<string>  $gids
     * @return array<string, string> GID => estado
     */
    public function awaitReady(array $gids): array
    {
        if ($gids === []) {
            return [];
        }

        $attempts = max(1, (int) config('product-studio.shopify.media_poll_attempts', 5));
        $sleepMs = max(0, (int) config('product-studio.shopify.media_poll_sleep_ms', 1000));

        $statuses = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $statuses = $this->fetchStatuses($gids);

            if ($this->allReady($statuses)) {
                return $statuses;
            }

            if ($attempt < $attempts && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $gids
     * @return array<string, string>
     */
    private function fetchStatuses(array $gids): array
    {
        $data = $this->client->query('FilesStatus', ShopifyOperations::FILES_STATUS, ['ids' => $gids]);

        $statuses = [];

        foreach ((array) ($data['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $node['id'] ?? null;
            $status = $node['fileStatus'] ?? null;

            if (is_string($id) && is_string($status)) {
                $statuses[$id] = $status;
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, string>  $statuses
     */
    private function allReady(array $statuses): bool
    {
        if ($statuses === []) {
            return false;
        }

        foreach ($statuses as $status) {
            if ($status !== 'READY') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array<int, array{name: string, value: string}>
     */
    private function parameters(array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            $value = $parameter['value'] ?? null;

            if (is_string($name) && is_string($value)) {
                $normalized[] = ['name' => $name, 'value' => $value];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function userErrors(array $payload): array
    {
        $errors = $payload['userErrors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}

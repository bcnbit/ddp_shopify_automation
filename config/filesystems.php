<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // URI propia. Varios discos locales con serve compartían la misma
            // URI /storage, competían por la misma ruta y sólo sobrevivía una:
            // temporaryUrl() del disco de medios fallaba con «Route
            // [storage.media] not defined» y las imágenes del panel salían rotas.
            'url' => '/storage/private',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],
        /*
         * Originales de producto: nunca se borran (RFC-0000 / RFC-0005).
         * El acceso se sirve a través de controlador autorizado, no en público.
         */

        'media' => [
            'driver' => env('PRODUCT_STUDIO_MEDIA_DRIVER', 'local'),
            'root' => storage_path('app/media'),
            // URI propia: es la que da nombre a la ruta storage.media, que
            // necesitan las URLs firmadas de los originales privados.
            // Si se migra a S3, usar el disco media-s3 (RFC-0007).
            'url' => '/storage/media',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Derivados (miniaturas y copias optimizadas): regenerables.
         */

        'media-derived' => [
            'driver' => env('PRODUCT_STUDIO_DERIVED_DRIVER', 'local'),
            'root' => storage_path('app/media-derived'),
            // URI propia, por el mismo motivo que el disco media.
            'url' => '/storage/media-derived',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Originales de producto en S3-compatible cuando se configure (RFC-0007).
         */

        'media-s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET_MEDIA', env('AWS_BUCKET')),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // **Privado a propósito**: son originales de producto y no deben ser
            // accesibles sin firma. El panel los muestra con URLs temporales.
            'visibility' => 'private',
            // Sólo para un CDN delante del bucket. Sin él, Laravel firma contra la
            // URL de S3 directamente.
            'temporary_url' => env('AWS_TEMPORARY_URL'),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Disco de objetos de propósito general. Lo usa Livewire para las subidas
         * temporales (`livewire-tmp`) cuando `LIVEWIRE_TEMPORARY_UPLOAD_DISK` apunta
         * aquí: el navegador sube **directo al bucket** con una URL firmada y el
         * archivo no pasa por PHP, así que los límites del hosting dejan de aplicar.
         *
         * Es privado: los temporales no deben ser legibles sin firma.
         */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'temporary_url' => env('AWS_TEMPORARY_URL'),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad
    |--------------------------------------------------------------------------
    |
    | Idioma inicial del contenido: español (RFC-0000). CA/EN/FR quedan
    | preparados en la tabla product_content pero no se publican en el MVP.
    |
    */

    'default_locale' => env('PRODUCT_STUDIO_DEFAULT_LOCALE', 'es'),

    'supported_locales' => ['es', 'ca', 'en', 'fr'],

    /*
    |--------------------------------------------------------------------------
    | Opciones de variante
    |--------------------------------------------------------------------------
    |
    | El MVP admite como máximo dos opciones: Color y Talla (RFC-0001).
    |
    */

    'variants' => [
        'max_options' => 2,
        'option_names' => ['Color', 'Talla'],
        'default_inventory_policy' => env('PRODUCT_STUDIO_INVENTORY_POLICY', 'deny'),
        'initial_inventory_quantity' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Medios
    |--------------------------------------------------------------------------
    |
    | Validación de archivos (RFC-0001 y RFC-0005). SVG rechazado en el MVP.
    | Los originales nunca se borran: Shopify recibe una copia optimizada.
    |
    */

    'media' => [
        'disk' => env('PRODUCT_STUDIO_MEDIA_DISK', 'media'),
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'max_kilobytes' => (int) env('PRODUCT_STUDIO_MEDIA_MAX_KB', 20480),
        'min_width' => (int) env('PRODUCT_STUDIO_MEDIA_MIN_WIDTH', 800),
        'min_height' => (int) env('PRODUCT_STUDIO_MEDIA_MIN_HEIGHT', 800),
        'max_width' => (int) env('PRODUCT_STUDIO_MEDIA_MAX_WIDTH', 10000),
        'max_height' => (int) env('PRODUCT_STUDIO_MEDIA_MAX_HEIGHT', 10000),
        'max_per_product' => (int) env('PRODUCT_STUDIO_MEDIA_MAX_PER_PRODUCT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contenido y SEO
    |--------------------------------------------------------------------------
    |
    | Rangos de aviso, no bloqueantes, salvo que se indique lo contrario (RFC-0005).
    |
    */

    'content' => [
        'seo_title_min' => (int) env('PRODUCT_STUDIO_SEO_TITLE_MIN', 50),
        'seo_title_max' => (int) env('PRODUCT_STUDIO_SEO_TITLE_MAX', 60),
        'seo_description_min' => (int) env('PRODUCT_STUDIO_SEO_DESCRIPTION_MIN', 140),
        'seo_description_max' => (int) env('PRODUCT_STUDIO_SEO_DESCRIPTION_MAX', 160),
        'tags_min' => (int) env('PRODUCT_STUDIO_TAGS_MIN', 5),
        'tags_max' => (int) env('PRODUCT_STUDIO_TAGS_MAX', 12),
        'max_generations_per_day' => (int) env('PRODUCT_STUDIO_MAX_GENERATIONS_PER_DAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seguridad
    |--------------------------------------------------------------------------
    */

    'security' => [
        'html_purifier_cache_path' => env('PRODUCT_STUDIO_PURIFIER_CACHE_PATH', storage_path('framework/cache/htmlpurifier')),
        'require_two_factor_for_admins' => (bool) env('PRODUCT_STUDIO_REQUIRE_2FA_FOR_ADMINS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Colas
    |--------------------------------------------------------------------------
    |
    | Nombres lógicos de cola. Redis en producción, database en local (RFC-0001).
    | El bloqueo por producto evita dos sincronizaciones simultáneas (RFC-0002).
    |
    */

    'queues' => [
        'connection' => env('PRODUCT_STUDIO_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'generation' => env('PRODUCT_STUDIO_QUEUE_GENERATION', 'ai'),
        'sync' => env('PRODUCT_STUDIO_QUEUE_SYNC', 'shopify'),
        'media' => env('PRODUCT_STUDIO_QUEUE_MEDIA', 'media'),
        'product_lock_seconds' => (int) env('PRODUCT_STUDIO_PRODUCT_LOCK_SECONDS', 900),
        'tries' => (int) env('PRODUCT_STUDIO_QUEUE_TRIES', 3),
        'backoff_seconds' => [30, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integraciones (sin implementar en RFC-0001)
    |--------------------------------------------------------------------------
    |
    | Valores leídos del entorno. Ningún secreto se persiste en base de datos
    | ni se serializa hacia el navegador (RFC-0000 / RFC-0001).
    | La integración real corresponde a RFC-0003 (IA) y RFC-0004 (Shopify).
    |
    */

    'shopify' => [
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),
        // Shopify retira una versión cada trimestre y, ante una versión no
        // soportada, responde con la más antigua accesible: el contrato dejaría
        // de ser predecible. Se fija la última estable y se sube por entorno.
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
        'connect_timeout' => (int) env('SHOPIFY_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('SHOPIFY_RETRY_TIMES', 2),
        'retry_backoff_ms' => (int) env('SHOPIFY_RETRY_BACKOFF_MS', 2000),
        'upload_timeout' => (int) env('SHOPIFY_UPLOAD_TIMEOUT', 120),
        // Los medios se procesan de forma asíncrona en Shopify: hay que esperar
        // a que el archivo esté READY antes de asociarlo al producto.
        'media_poll_attempts' => (int) env('SHOPIFY_MEDIA_POLL_ATTEMPTS', 5),
        'media_poll_sleep_ms' => (int) env('SHOPIFY_MEDIA_POLL_SLEEP_MS', 1000),
        'metafield_namespace' => env('SHOPIFY_METAFIELD_NAMESPACE', 'product_studio'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        'timeout' => (int) env('SHOPIFY_TIMEOUT', 30),
        'default_status' => 'DRAFT',
    ],

    /*
     * Proveedor de IA mediante OpenRouter, que expone una API compatible con la
     * de OpenAI para varios modelos. La integración real corresponde a RFC-0003:
     * se encapsula detrás de una interfaz propia y ninguna pantalla llama al
     * proveedor directamente.
     *
     * El modelo se identifica con el formato `proveedor/modelo` que usa
     * OpenRouter (por ejemplo `openai/gpt-4.1` o `anthropic/claude-sonnet-4`),
     * de modo que cambiar de modelo no requiere tocar código.
     */

    'ai' => [
        'driver' => env('AI_DRIVER', 'openrouter'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4.1'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),
        'connect_timeout' => (int) env('OPENROUTER_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('OPENROUTER_RETRY_TIMES', 2),
        'retry_backoff_ms' => (int) env('OPENROUTER_RETRY_BACKOFF_MS', 1000),

        // Cabeceras de atribución que OpenRouter usa para clasificar el tráfico.
        // No son credenciales, pero se leen del entorno por si cambian.
        'app_name' => env('OPENROUTER_APP_NAME', 'Shopify Product Studio'),
        'app_url' => env('OPENROUTER_APP_URL', env('APP_URL', 'http://localhost')),

        'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 4096),
        'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.4),

        // Las imágenes se reducen antes de enviarse: mandar originales de 4000 px
        // encarece la llamada y alarga la respuesta sin mejorar la descripción.
        'image_max_side' => (int) env('OPENROUTER_IMAGE_MAX_SIDE', 1024),
        'image_quality' => (int) env('OPENROUTER_IMAGE_QUALITY', 80),
    ],
];

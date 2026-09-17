<?php

declare(strict_types=1);

/**
 * Almacenamiento de medios de producto (RFC-0001 / RFC-0005).
 *
 * `originals` conserva siempre el archivo tal cual se subió.
 * `derived` guarda miniaturas y copias optimizadas, regenerables.
 */
return [

    'disks' => [
        'originals' => env('PRODUCT_STUDIO_MEDIA_DISK', 'media'),
        'derived' => env('PRODUCT_STUDIO_DERIVED_DISK', 'media-derived'),
    ],

    'paths' => [
        'originals' => 'products/originals',
        'derived' => 'products/derived',
    ],

    'thumbnails' => [
        'width' => (int) env('PRODUCT_STUDIO_THUMBNAIL_WIDTH', 400),
        'height' => (int) env('PRODUCT_STUDIO_THUMBNAIL_HEIGHT', 400),
        'quality' => (int) env('PRODUCT_STUDIO_THUMBNAIL_QUALITY', 80),
    ],

    'optimized' => [
        'max_width' => (int) env('PRODUCT_STUDIO_OPTIMIZED_MAX_WIDTH', 2048),
        'max_height' => (int) env('PRODUCT_STUDIO_OPTIMIZED_MAX_HEIGHT', 2048),
        'quality' => (int) env('PRODUCT_STUDIO_OPTIMIZED_QUALITY', 85),
    ],

];

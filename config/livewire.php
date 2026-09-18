<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración de Livewire
|--------------------------------------------------------------------------
|
| Sólo se publican las claves que este proyecto necesita ajustar. El resto las
| aporta Livewire con `mergeConfigFrom()`, así que no hace falta copiar aquí el
| archivo entero (y copiarlo tendría el problema contrario: una clave nueva de
| Livewire quedaría anulada por este archivo sin que nadie lo note).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Subidas temporales
    |--------------------------------------------------------------------------
    |
    | Livewire guarda el archivo en un almacenamiento temporal antes de que la
    | persona confirme el formulario. Estas claves deciden **cómo viaja el
    | archivo**, y un desajuste aquí produce un error opaco en la interfaz
    | («...failed to upload») en lugar de un mensaje de validación.
    |
    */

    'temporary_file_upload' => [

        /*
        | Disco temporal.
        |
        | `null` = se usa el disco por defecto (`FILESYSTEM_DISK`).
        |
        | **Con `local`** el navegador sube el archivo **a este servidor**, así que
        | la subida depende de `upload_max_filesize` y `post_max_size` de PHP. Si el
        | hosting los tiene bajos, la petición se corta antes de llegar a Laravel:
        | el navegador no recibe JSON y muestra el mensaje genérico de fallo.
        |
        | **Con un disco `s3`** el navegador sube el archivo **directo al bucket**
        | mediante una URL firmada, y el límite de PHP deja de aplicar. Es la razón
        | por la que RFC-0007 migra los medios a S3.
        |
        | Se lee del entorno para poder cambiar de proveedor sin tocar código.
        */
        'disk' => env('LIVEWIRE_TEMPORARY_UPLOAD_DISK'),

        /*
        | Reglas del endpoint temporal.
        |
        | **Deben coincidir con las reglas de la aplicación**
        | (`MediaRules::maxKilobytes()`), o la interfaz prometerá un límite que la
        | subida incumple. El valor por defecto de Livewire son **12 MB** mientras
        | la aplicación acepta 20 MB: subir un archivo entre esos dos números daba
        | un fallo de subida sin explicación.
        |
        | Se derivan de la misma configuración que la aplicación para que no puedan
        | volver a divergir: hay una sola cifra.
        */
        'rules' => [
            'required',
            'file',
            'max:'.(int) env('PRODUCT_STUDIO_MEDIA_MAX_KB', 20480),
            'mimes:'.implode(',', (array) config('product-studio.media.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp'])),
        ],

        /*
        | Directorio temporal dentro del disco.
        |
        | Se mantiene el valor por defecto de Livewire, pero se declara para que
        | los comandos de limpieza y las pruebas lo lean del mismo sitio.
        */
        'directory' => env('LIVEWIRE_TEMPORARY_UPLOAD_DIRECTORY', 'livewire-tmp'),

        /*
        | Ventana de validez de la URL firmada, en minutos.
        |
        | Con subidas directas a S3 el archivo puede tardar: una ventana corta
        | produce un 403 de S3 a mitad de subida, que el navegador muestra como el
        | mismo mensaje genérico de fallo. 15 minutos cubre un archivo grande en
        | una conexión lenta.
        */
        'max_upload_time' => (int) env('LIVEWIRE_MAX_UPLOAD_TIME', 15),

        /*
        | Limpieza automática de subidas huérfanas.
        |
        | Se desactiva cuando el disco es externo: borrar en S3 desde el ciclo de
        | una petición añade latencia a cada carga del panel. S3 tiene sus propias
        | reglas de ciclo de vida para esto.
        */
        'cleanup' => env('LIVEWIRE_TEMPORARY_UPLOAD_DISK') === null,

    ],

];

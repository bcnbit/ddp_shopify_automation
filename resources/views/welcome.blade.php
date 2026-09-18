{{--
    Página raíz.

    Es deliberadamente una página en negro y vacía: esta aplicación es un backoffice
    interno y la raíz pública no tiene nada que mostrar. Antes servía la página de
    bienvenida que Laravel trae por defecto, que anunciaba el framework y no tenía
    relación con el proyecto.

    Se evita cualquier dependencia externa a propósito: ni tipografías remotas, ni
    `@vite`. La página no debe fallar ni hacer peticiones a terceros según cómo esté
    el despliegue, y no hay nada que cargar.

    El punto de entrada real es `/admin`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ config('app.name', 'Shopify Product Studio') }}</title>
        <style>html,body{height:100%;margin:0;background-color:#000}</style>
    </head>
    <body></body>
</html>

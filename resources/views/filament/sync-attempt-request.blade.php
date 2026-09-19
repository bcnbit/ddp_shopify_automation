{{--
    Petición exacta enviada a Shopify (RFC-0004).

    Existe para diagnosticar un rechazo de la API sin acceso a la base de datos:
    cuando Shopify responde «File URL is invalid», lo que hace falta es ver qué
    valor concreto se mandó en ese campo. El contenido llega ya redactado por
    SecretRedactor —los secretos y el HTML largo van enmascarados—, así que se
    puede mostrar tal cual.
--}}
@php
    /** @var array<string, mixed> $payload */
@endphp

<div class="space-y-4">
    <pre class="max-h-96 overflow-auto rounded-lg bg-gray-50 p-4 text-xs leading-relaxed text-gray-800 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Los valores enmascarados no se enviaron así: se ocultan aquí para que no queden
        en pantalla. La petición real llevaba su valor completo.
    </p>
</div>
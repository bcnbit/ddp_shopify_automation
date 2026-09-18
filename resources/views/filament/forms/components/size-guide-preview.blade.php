{{--
    Previsualización de una guía de tallas (RFC-0008).

    Muestra la tabla tal y como se insertará en la descripción final: nota
    introductoria, tabla y nota final, en ese orden. Se pinta después de sanear,
    de modo que lo que se ve es exactamente lo que sobrevivirá al guardado.
--}}
@php
    use App\Support\Security\TechnicalSheetHtmlSanitizer;

    $intro = trim((string) ($intro ?? ''));
    $html = (string) ($html ?? '');
    $closing = trim((string) ($closing ?? ''));

    $clean = (new TechnicalSheetHtmlSanitizer)->sanitize($html);
    $hasContent = $intro !== '' || trim((string) $clean) !== '' || $closing !== '';
@endphp

<x-filament::section
    heading="Previsualización"
    description="Así se verá en la descripción enviada a Shopify."
>
    @if (! $hasContent)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Todavía no hay nada que previsualizar.
        </p>
    @else
        <div class="space-y-3 text-sm text-gray-900 dark:text-white">
            @if ($intro !== '')
                <p>{{ $intro }}</p>
            @endif

            @if (trim((string) $clean) !== '')
                <div class="prose prose-sm max-w-none overflow-x-auto dark:prose-invert [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-gray-300 [&_td]:px-3 [&_td]:py-2 dark:[&_td]:border-gray-600 [&_th]:border [&_th]:border-gray-300 [&_th]:bg-gray-50 [&_th]:px-3 [&_th]:py-2 [&_th]:text-left dark:[&_th]:border-gray-600 dark:[&_th]:bg-gray-800">
                    {!! $clean !!}
                </div>
            @endif

            @if ($closing !== '')
                <p>{{ $closing }}</p>
            @endif
        </div>
    @endif
</x-filament::section>
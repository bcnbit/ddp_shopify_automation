{{--
    Aviso de atributos de combinación de celdas en una tabla de tallas (RFC-0008).

    La lista blanca del RFC no admite atributos, así que `colspan` y `rowspan` se
    pierden al sanear y una fila puede quedar con menos columnas de las que su
    autor quería. No se bloquea el guardado —la tabla sigue siendo válida— pero
    se avisa para que nadie publique una tabla distinta de la que escribió.
--}}
@php
    use App\Support\Security\TechnicalSheetHtmlSanitizer;

    $html = (string) ($html ?? '');
    $loses = (new TechnicalSheetHtmlSanitizer)->losesTableCellSpans($html);
    $isDirty = $html !== '' && ! (new TechnicalSheetHtmlSanitizer)->isClean($html);
@endphp

@if ($loses || $isDirty)
    <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
        <div class="flex items-start gap-3">
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400"
            />

            <div class="space-y-2 text-sm">
                @if ($loses)
                    <p class="font-medium text-warning-800 dark:text-warning-200">
                        Esta tabla usa <code>colspan</code> o <code>rowspan</code>.
                    </p>
                    <p class="text-warning-700 dark:text-warning-300">
                        No están permitidos, así que se eliminarán al guardar y la tabla puede quedar
                        con menos columnas de las que has escrito. Reparte el contenido en celdas
                        normales antes de guardar.
                    </p>
                @endif

                @if ($isDirty)
                    <p class="font-medium text-warning-800 dark:text-warning-200">
                        Hay etiquetas o atributos que no están permitidos.
                    </p>
                    <p class="text-warning-700 dark:text-warning-300">
                        Se eliminarán al guardar. Consulta la ayuda del campo para ver la lista permitida.
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif
{{--
    Previsualización de la ficha técnica (RFC-0008).

    Compone los bloques con el **mismo** compositor que usa el envío a Shopify,
    así que lo que se ve aquí es exactamente lo que recibirá la tienda.

    Hay dos lecturas deliberadamente distintas:

    - **Antes de guardar**: se previsualiza la selección *pendiente* del
      formulario. Es lo útil al elegir, porque el guardado automático aún no ha
      corrido.
    - **Ficha ya guardada**: se previsualiza la copia congelada, que es lo que de
      verdad se enviará. Una nota avisa si el mantenimiento ha cambiado desde
      entonces, sin alterar nada por su cuenta.
--}}
@php
    use App\Models\ProductTechnicalSheet;
    use App\Support\Products\TechnicalSheetCatalog;
    use App\Support\Products\TechnicalSheetComposer;

    // En la pantalla de alta todavía no hay registro: se previsualiza igualmente
    // la selección pendiente, que es informativo y no requiere ficha guardada.
    $product = $this->getRecord();
    $data = (array) ($this->data ?? []);
    $catalog = app(TechnicalSheetCatalog::class);
    $composer = new TechnicalSheetComposer;

    $slots = [
        ProductTechnicalSheet::SLOT_COMPOSITION => ['label' => 'Composición', 'field' => 'technical_sheet_composition_id'],
        ProductTechnicalSheet::SLOT_FIT => ['label' => 'Ajuste / tallaje', 'field' => 'technical_sheet_fit_id'],
        ProductTechnicalSheet::SLOT_CARE => ['label' => 'Cuidados', 'field' => 'technical_sheet_care_id'],
        ProductTechnicalSheet::SLOT_SIZE_GUIDE => ['label' => 'Guía de tallas', 'field' => 'technical_sheet_size_guide_id'],
    ];

    // Selección pendiente del formulario, que puede no estar guardada todavía.
    $pending = [];

    foreach ($slots as $slot => $meta) {
        $value = $data[$meta['field']] ?? null;
        $pending[$slot] = is_numeric($value) ? (int) $value : null;
    }


    $composed = [];

    foreach ($slots as $slot => $meta) {
        $entryId = $pending[$slot];

        $snapshot = $entryId !== null
            ? $catalog->previewSelection($slot, $entryId)
            : null;

        $composed[$slot] = [
            'label' => $meta['label'],
            'snapshot' => $snapshot,
            'version' => $entryId === null ? null : $catalog->versionLabel($slot, $entryId),
            'outdated' => false,
        ];

        // Si la ficha ya está guardada y el mantenimiento cambió después, lo que
        // se enviará es la copia congelada, no el texto actual del catálogo.
        if ($snapshot !== null && $product?->exists) {
            $frozen = $product->technicalSheet($slot);

            if ($frozen !== null && (int) $frozen->entry_version !== (int) $snapshot->entry_version) {
                $composed[$slot]['outdated'] = true;
                $composed[$slot]['frozen_version'] = $frozen->entry_version;
            }
        }
    }

    $hasAny = array_filter($composed, static fn (array $item): bool => $item['snapshot'] !== null) !== [];
@endphp

<x-filament::section
    heading="Previsualización en la tienda"
    description="Así quedará la ficha técnica en la descripción enviada a Shopify."
>
    @if (! $hasAny)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Sin mantenimientos seleccionados. La descripción enviada a Shopify será sólo el texto comercial.
        </p>
    @else
        <div class="space-y-6">
            @foreach ($composed as $slot => $item)
                @if ($item['snapshot'] !== null)
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $item['label'] }}</h3>

                            @if ($item['version'])
                                <x-filament::badge color="gray">{{ $item['version'] }}</x-filament::badge>
                            @endif
                        </div>

                        @if ($item['outdated'])
                            <p class="mt-1 text-xs text-warning-700 dark:text-warning-300">
                                Esta ficha conserva la versión {{ $item['frozen_version'] }} copiada.
                                El mantenimiento ha cambiado desde entonces: lo que se enviará sigue
                                siendo la versión copiada, para no alterar la ficha.
                            </p>
                        @endif

                        <div class="prose prose-sm mt-2 max-w-none overflow-x-auto text-gray-900 dark:prose-invert dark:text-white [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-gray-300 [&_td]:px-3 [&_td]:py-2 dark:[&_td]:border-gray-600 [&_th]:border [&_th]:border-gray-300 [&_th]:bg-gray-50 [&_th]:px-3 [&_th]:py-2 [&_th]:text-left dark:[&_th]:border-gray-600 dark:[&_th]:bg-gray-800">
                            {!! $composer->renderBlock($item['snapshot']) !!}
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</x-filament::section>
@php
    use App\Support\Products\ProductReadiness;

    $product = $this->getRecord();
    $validation = $product ? ProductReadiness::validation($product) : null;
@endphp

@if ($validation && $validation->hasWarnings())
    <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
        <div class="flex items-start gap-3">
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400"
            />

            <div class="space-y-2">
                <p class="text-sm font-medium text-warning-800 dark:text-warning-200">
                    {{ trans_choice('Hay :count aviso pendiente|Hay :count avisos pendientes', count($validation->warnings()), ['count' => count($validation->warnings())]) }}
                </p>

                <ul class="list-disc space-y-1 ps-4 text-sm text-warning-700 dark:text-warning-300">
                    @foreach ($validation->warningMessages() as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>

                <p class="text-xs text-warning-600 dark:text-warning-400">
                    Los avisos no impiden enviar a Shopify. Las fichas señaladas quedan así para que decidas.
                </p>
            </div>
        </div>
    </div>
@endif
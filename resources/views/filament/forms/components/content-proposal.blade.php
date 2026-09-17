@php
    use App\Support\Products\ProductReadiness;

    $product = $this->getRecord();
    $content = $product?->contentFor();
    $canEdit = auth()->user()?->can('update', $product ?? $product) ?? false;
@endphp

@if (! $content)
    <x-filament::section>
        <x-slot name="heading">Propuesta de contenido</x-slot>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Todavía no hay ninguna propuesta. Usa <strong>Generar propuesta</strong> para partir de los datos
            que ya has confirmado. La generación con IA llegará en RFC-0003 y respetará esta misma revisión humana.
        </p>
    </x-filament::section>
@else
    <x-filament::section>
        <x-slot name="heading">
            Versión {{ $content->version }}
            @if ($content->isApproved())
                <x-filament::badge color="success" class="ms-2">Aprobada</x-filament::badge>
            @endif
            @if ($content->isAiGenerated())
                <x-filament::badge color="info" class="ms-2">Generada por IA</x-filament::badge>
            @else
                <x-filament::badge color="gray" class="ms-2">Datos confirmados</x-filament::badge>
            @endif
        </x-slot>

        <x-slot name="description">
            @if ($content->isApproved())
                Esta versión está aprobada. Si la editas, se creará una versión nueva y la aprobada se conserva.
            @else
                Revisa cada campo antes de aprobar. Lo que marca la IA se distingue de lo que confirmas tú.
            @endif
        </x-slot>

        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Título</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ $content->title ?: '—' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Handle</dt>
                <dd class="mt-1 font-mono text-sm text-gray-900 dark:text-white">{{ $content->handle ?: '—' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Meta title</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                    {{ $content->seo_title ?: '—' }}
                    @if ($content->seo_title)
                        <span class="text-xs text-gray-500">({{ mb_strlen($content->seo_title) }} car.)</span>
                    @endif
                </dd>
            </div>

            <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Meta description</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                    {{ $content->seo_description ?: '—' }}
                    @if ($content->seo_description)
                        <span class="text-xs text-gray-500">({{ mb_strlen($content->seo_description) }} car.)</span>
                    @endif
                </dd>
            </div>

            <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Descripción</dt>
                <dd class="prose prose-sm mt-1 max-w-none dark:prose-invert">
                    @if ($content->html_description)
                        {!! $content->html_description !!}
                    @else
                        <span class="not-prose text-sm text-gray-500">Todavía sin descripción.</span>
                    @endif
                </dd>
            </div>

            <div class="sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Etiquetas</dt>
                <dd class="mt-1 flex flex-wrap gap-1">
                    @forelse ($content->tags() as $tag)
                        <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
                    @empty
                        <span class="text-sm text-gray-500">—</span>
                    @endforelse
                </dd>
            </div>

            @if ($content->warnings() !== [])
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Datos sin confirmar</dt>
                    <dd class="mt-1">
                        <ul class="list-disc space-y-1 ps-4 text-sm text-warning-700 dark:text-warning-300">
                            @foreach ($content->warnings() as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            @endif
        </dl>
    </x-filament::section>
@endif
{{--
    Guardado automático (RFC-0002).

    `wire:poll` llama a `autoSave()` cada pocos segundos y al cambiar de pestaña
    se fuerza un guardado inmediato, de modo que abandonar el navegador o saltar
    de sección nunca pierde lo escrito.
--}}
<div
    x-data="{
        init() {
            const form = this.$el.closest('form') ?? document;

            form.addEventListener('click', (event) => {
                const tab = event.target.closest('[role=tab]');

                if (tab) {
                    this.$wire.autoSave();
                }
            });
        }
    }"
    wire:poll.{{ $interval }}s="autoSave"
    class="hidden"
    aria-hidden="true"
></div>
{{--
    Pantalla de conexión con Shopify (RFC-0009 §10).

    Muestra el estado de la instalación y ofrece las dos acciones: instalar y
    comprobar. Todo lo que se imprime aquí son cadenas ya formateadas por la
    página (dominio, handles de permiso, fechas). Ninguna credencial —token,
    client secret o `shpss_`— llega a esta vista, ni siquiera enmascarada: no hay
    motivo para mostrarla y un valor enmascarado invita a intentar revelarlo.
--}}
<x-filament-panels::page>
    @if (session('shopify_connection_ok'))
        <x-filament::section>
            <p class="text-sm font-medium text-success-700 dark:text-success-300">
                {{ session('shopify_connection_ok') }}
            </p>
        </x-filament::section>
    @endif

    @if (session('shopify_connection_error'))
        <x-filament::section>
            <p class="text-sm font-medium text-danger-700 dark:text-danger-300">
                {{ session('shopify_connection_error') }}
            </p>
        </x-filament::section>
    @endif

    {{-- Resultado de la última comprobación, con sus cuatro preguntas. --}}
    @if ($result_rows !== [])
        <x-filament::section
            heading="Resultado de la comprobación"
            description="La comprobación es de sólo lectura: no crea ni modifica nada en la tienda."
        >
            <div class="space-y-3">
                @foreach ($result_rows as $row)
                    <div class="flex flex-wrap items-start gap-2">
                        <x-filament::badge :color="$row['ok'] ? 'success' : 'danger'">
                            {{ $row['ok'] ? 'Correcto' : 'Revisar' }}
                        </x-filament::badge>

                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $row['label'] }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $row['detail'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- 1. Estado de la aplicación (las credenciales del entorno). --}}
    <x-filament::section
        heading="Aplicación de Shopify"
        description="Credenciales de la aplicación, definidas en el entorno del servidor. Nunca se muestran ni se guardan en Git."
    >
        @if (! $state['app_configured'])
            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <p class="font-medium text-danger-700 dark:text-danger-300">
                    La aplicación todavía no está configurada.
                </p>
                <p>Un administrador técnico debe rellenar estas variables en el archivo <code>.env</code> del servidor:</p>
                <ul class="list-disc space-y-1 pl-5">
                    <li><code>SHOPIFY_API_KEY</code> — client ID de la aplicación (público).</li>
                    <li><code>SHOPIFY_API_SECRET</code> — API secret key de la aplicación. <strong>Es secreta</strong>: no se copia a Git ni a ningún documento.</li>
                    <li><code>SHOPIFY_SHOP_DOMAIN</code> — dominio de la tienda, terminado en <code>.myshopify.com</code>.</li>
                </ul>
                <p>Cuando estén puestas, recarga esta pantalla y pulsa «Conectar con Shopify».</p>
            </div>
        @else
            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <p>
                    <span class="font-medium text-success-700 dark:text-success-300">Configurada.</span>
                    Client ID y API secret key presentes en el entorno, y el dominio de la tienda está definido.
                </p>
                <p>
                    La API secret key <strong>no</strong> se usa como token de acceso.
                    El token lo entrega Shopify al instalar la aplicación por OAuth y se guarda cifrado.
                </p>
            </div>
        @endif
    </x-filament::section>

    {{-- 2. Estado de la instalación (el token y sus permisos). --}}
    <x-filament::section
        heading="Instalación en la tienda"
        description="El token de acceso es privado: esta pantalla nunca lo muestra."
    >
        @if (! $state['installed'])
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Todavía no hay ninguna tienda conectada.
            </p>
        @else
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Dominio</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">
                        {{ $state['shop_domain'] }}
                        @if (! $state['valid_domain'])
                            <span class="text-danger-700 dark:text-danger-300"> — no termina en .myshopify.com</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Token de acceso</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">
                        @if ($state['token_is_usable'])
                            Instalado y guardado cifrado
                        @else
                            <span class="text-danger-700 dark:text-danger-300">No válido: hay que reinstalar</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Instalada el</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $state['installed_at'] ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Última comprobación</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $state['last_checked_at'] ?? 'Nunca' }}</dd>
                </div>
            </dl>

            @if ($state['last_check_error'])
                <p class="mt-3 text-sm text-danger-700 dark:text-danger-300">
                    Último problema detectado: {{ $state['last_check_error'] }}
                </p>
            @endif

            @if ($state['token_expired'])
                <p class="mt-3 text-sm text-danger-700 dark:text-danger-300">
                    El token instalado ha caducado. Esta versión no renueva tokens automáticamente:
                    hay que volver a instalar la aplicación.
                </p>
            @endif

            <div class="mt-4">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Permisos concedidos</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @forelse ($state['granted_scopes'] as $scope)
                        <x-filament::badge :color="$state['missing_scopes'] === [] || in_array($scope, $state['required_scopes'], true) ? 'success' : 'gray'">
                            {{ $scope }}
                        </x-filament::badge>
                    @empty
                        <span class="text-sm text-gray-500 dark:text-gray-400">Sin información de permisos.</span>
                    @endforelse
                </div>

                @if ($state['missing_scopes'] !== [])
                    <p class="mt-2 text-sm text-warning-700 dark:text-warning-300">
                        Faltan permisos obligatorios: {{ implode(', ', $state['missing_scopes']) }}.
                        Reinstala la aplicación y acepta todos los permisos solicitados.
                    </p>
                @endif
            </div>
        @endif

        @if ($state['oauth_expiring'])
            <p class="mt-4 text-sm text-warning-700 dark:text-warning-300">
                La instalación pide tokens con caducidad (SHOPIFY_OAUTH_EXPIRING) y esta versión todavía no los renueva.
                Está pensado para una futura distribución pública de la aplicación: si no es el caso, desactiva esa variable.
            </p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($state['can_install'])
                <x-filament::button tag="a" :href="$state['install_url']" icon="heroicon-o-arrow-top-right-on-square">
                    {{ $state['installed'] ? 'Reinstalar en Shopify' : 'Conectar con Shopify' }}
                </x-filament::button>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Indica el dominio de la tienda para poder iniciar la conexión.
                </p>
            @endif
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Al conectar se abre la pantalla de autorización de Shopify. Esta aplicación sólo pide
            lectura y escritura de productos y de archivos, y no publica nada por su cuenta.
        </p>
    </x-filament::section>
</x-filament-panels::page>

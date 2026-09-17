<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige segundo factor a los administradores técnicos (RFC-0001).
 *
 * Filament permite declarar `multiFactorAuthentication(isRequired: ...)`, pero
 * ese valor se evalúa al registrar las rutas, cuando todavía no hay usuario
 * autenticado. Por eso la obligación se comprueba aquí, en tiempo de petición.
 *
 * Un administrador sin TOTP configurado es enviado a su perfil, que es donde
 * Filament ofrece el alta del segundo factor; el resto del panel le queda
 * cerrado hasta que lo configure. La propia página de perfil y la de salida
 * quedan excluidas para no provocar un bucle de redirecciones.
 */
class EnsurePrivilegedUsersHaveTwoFactor
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_SUFFIXES = [
        'auth.profile',
        'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('product-studio.security.require_two_factor_for_admins')) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        if ($user === null || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (! $user->hasRole(Role::AdminTecnico->value)) {
            return $next($request);
        }

        if ($this->isAllowedRoute($request)) {
            return $next($request);
        }

        $profileUrl = Filament::getProfileUrl();

        if ($profileUrl === null) {
            abort(403, 'Se requiere configurar la verificación en dos pasos.');
        }

        return redirect()->guest($profileUrl);
    }

    private function isAllowedRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName)) {
            return false;
        }

        foreach (self::ALLOWED_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($routeName, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

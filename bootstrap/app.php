<?php

declare(strict_types=1);

use App\Support\Audit\AssignsRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Identificador de petición correlacionable (RFC-0007).
        $middleware->append(AssignsRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

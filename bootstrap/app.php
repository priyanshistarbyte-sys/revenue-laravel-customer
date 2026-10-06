<?php

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
        $middleware->alias([
            'auth.pin'   => \App\Http\Middleware\EnsureAuthenticated::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'auth.customer' => \App\Http\Middleware\EnsureCustomer::class,
        ]);

        // The original Amaira used no CSRF tokens; all POST endpoints are
        // behind PIN auth. Exempt them so the ported forms and app.js keep working.
        $middleware->validateCsrfTokens(except: [
            'admin/login', 'save_value', 'delete_data',
            'domains', 'links', 'expenses', 'accounts', 'adx',
            'currencies', 'settings', 'users', 'roles', 'invoices', 'invoices/*',
            'upload', 'gam_check', 'meta_campaigns', 'meta_media_upload',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

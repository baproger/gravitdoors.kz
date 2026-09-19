<?php

use App\Http\Middleware\SecurityHeaders;
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
        // Заголовки безопасности на каждый ответ. Глобально, а не в группе web:
        // маршруты панели Filament идут со своим набором middleware, минуя группу.
        $middleware->append(SecurityHeaders::class);

        // За nginx хостинга (Plesk) или своим nginx: иначе Laravel не узнает, что
        // соединение было HTTPS, и подписанные ссылки на файлы выйдут с http://.
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

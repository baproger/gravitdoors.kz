<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки, которые закрывают типовые атаки на браузер пользователя.
 *
 * Не заменяют права и валидацию — они уже есть. Это второй слой: даже если
 * где-то проскочит чужой скрипт или сайт попробуют встроить в чужой iframe,
 * браузер откажет сам.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        // Панель нельзя встроить в чужой сайт (clickjacking).
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        // Браузер не угадывает тип файла: загруженный «чек» не исполнится как скрипт.
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Адреса карточек (с номерами сделок) не утекают в Referer на чужие сайты.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Камера нужна только полю загрузки на планшете; остальное — запрещено.
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');

        // HTTPS на год вперёд: после первого захода браузер не пойдёт по http даже по ссылке.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

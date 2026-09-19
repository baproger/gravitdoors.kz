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
    /**
     * Политика содержимого — только те правила, которые панель точно переживёт.
     *
     * Здесь намеренно нет `script-src`. Alpine, на котором держится вся
     * интерактивность Filament, собирает обработчики из строк в атрибутах
     * `x-on:`, то есть требует `unsafe-inline` и `unsafe-eval`. С ними
     * `script-src` не защищает ни от чего и при этом ломается на каждом
     * обновлении панели. Лучше четыре правила, которые действительно
     * работают, чем длинная строка, дающая ложное спокойствие.
     */
    private const CSP = "base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'";

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
        // Чужая вкладка не получает ссылку на окно панели через window.opener.
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Плагины Adobe могли читать домен по своему crossdomain.xml.
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Content-Security-Policy', self::CSP);

        // HTTPS на год вперёд: после первого захода браузер не пойдёт по http даже по ссылке.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Uploads\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Правила веб-сервера не должны закрывать собственные маршруты приложения.
 *
 * Так уже случилось на бою: `public/.htaccess` закрывает `/storage/` — туда
 * однажды попал каталог развёрнутого не туда приложения с логами и чеками
 * клиентов. Но с того же адреса Laravel по умолчанию отдаёт подписанные
 * ссылки на загрузки, и аватар после сохранения стал возвращать 404.
 * Apache отвечает раньше, чем запрос доходит до PHP, поэтому обычный
 * тест этого не видел: он ходит в приложение напрямую.
 *
 * Здесь проверяется то, что видит браузер: адрес прогоняется через те же
 * выражения, что стоят в `.htaccess`.
 */
class PrivateFileRouteTest extends TestCase
{
    use RefreshDatabase;

    /** Подписанная ссылка на загрузку доходит до приложения, а не гасится сервером. */
    public function test_signed_file_link_is_not_blocked_by_the_web_server(): void
    {
        Storage::disk(PrivateFiles::DISK)->put('avatars/photo.jpg', 'содержимое');
        $this->beforeApplicationDestroyed(fn () => Storage::disk(PrivateFiles::DISK)->delete('avatars/photo.jpg'));

        $url = PrivateFiles::url('avatars/photo.jpg');
        $this->assertNotNull($url);

        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->assertNull(
            $this->blockedBy($path),
            "Веб-сервер закрывает {$path} — браузер получит 404 до того, как запрос дойдёт до PHP"
        );

        // И само приложение файл по этой ссылке отдаёт.
        $this->get($url)->assertOk();
    }

    /**
     * Ни один маршрут приложения не попадает под запреты веб-корня.
     *
     * Сплошная проверка, а не один адрес: следующий маршрут могут назвать
     * `/resources/...` или `/config/...` и получить ту же тихую поломку —
     * тесты зелёные, на бою 404.
     */
    public function test_no_route_collides_with_the_web_root_blocklist(): void
    {
        $collisions = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Вместо {record} и {path} — обычный отрезок пути: важно начало адреса.
            $path = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/');

            if (($pattern = $this->blockedBy($path)) !== null) {
                $collisions[] = $path.'  ←  '.$pattern;
            }
        }

        $this->assertSame([], $collisions, "Маршруты закрыты правилами public/.htaccess:\n".implode("\n", $collisions));
    }

    /**
     * Выражение из `.htaccess`, которое гасит этот адрес, или null.
     *
     * Apache разбирает `RedirectMatch` тем же PCRE, что и PHP, поэтому
     * выражения применяются как есть — без перевода и без упрощений,
     * иначе тест проверял бы не то, что стоит на сервере.
     */
    private function blockedBy(string $path): ?string
    {
        preg_match_all('/^RedirectMatch\s+404\s+(\S+)/m', (string) file_get_contents(public_path('.htaccess')), $rules);

        foreach ($rules[1] as $pattern) {
            if (preg_match('#'.$pattern.'#', $path) === 1) {
                return $pattern;
            }
        }

        return null;
    }
}

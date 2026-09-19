<?php

declare(strict_types=1);

namespace App\Support\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Кружок с инициалами, нарисованный у себя.
 *
 * Filament по умолчанию просит такую картинку у ui-avatars.com, передавая
 * туда имя сотрудника. Это два лишних запроса на каждой странице, чужой
 * сервер, падение которого видно нашим людям, и фамилии кадрового состава
 * в чужих логах. Рисуем сами: SVG собирается строкой и уезжает в атрибут
 * `src` как data-адрес — ни одного обращения наружу.
 *
 * Цвет берётся из имени, а не случайно: у одного человека кружок всегда
 * одного цвета, и в списке ответственных люди различаются с одного взгляда.
 */
final class InitialsAvatar implements AvatarProvider
{
    /**
     * Насыщенные цвета, на которых белый текст читается.
     *
     * Взяты из палитры Tailwind (оттенок 600) — той же, что у всей панели,
     * поэтому кружки не выбиваются из оформления.
     *
     * @var list<string>
     */
    private const COLORS = [
        '#4f46e5', // indigo
        '#0891b2', // cyan
        '#059669', // emerald
        '#d97706', // amber
        '#dc2626', // red
        '#7c3aed', // violet
        '#db2777', // pink
        '#0284c7', // sky
        '#65a30d', // lime
        '#ea580c', // orange
    ];

    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        return $this->svg($this->initials($name), $this->color($name));
    }

    /**
     * Первые буквы имени и фамилии — максимум две.
     *
     * Ведущие символы вроде скобок пропускаются: у служебной записи
     * «[Система] Импорт» инициалами должны стать «СИ», а не «[И».
     */
    private function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', $name) ?: [])
            ->map(fn (string $part): string => (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part))
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $letters !== '' ? $letters : '?';
    }

    /** Один и тот же человек — всегда один и тот же цвет. */
    private function color(string $name): string
    {
        $index = abs(crc32(mb_strtolower($name))) % count(self::COLORS);

        return self::COLORS[$index];
    }

    /**
     * Готовый data-адрес с картинкой.
     *
     * base64, а не сырой SVG: в имени могут быть кавычки и решётки, которые
     * в незакодированном data-адресе обрывают атрибут.
     */
    private function svg(string $initials, string $color): string
    {
        // Размер шрифта чуть меньше для двух букв, чтобы они не упирались в края.
        $fontSize = mb_strlen($initials) > 1 ? 38 : 46;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
            .'<rect width="100" height="100" rx="50" fill="'.$color.'"/>'
            .'<text x="50" y="50" fill="#ffffff" font-size="'.$fontSize.'"'
            .' font-family="Inter Variable, ui-sans-serif, system-ui, sans-serif" font-weight="600"'
            .' text-anchor="middle" dominant-baseline="central">'.e($initials).'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

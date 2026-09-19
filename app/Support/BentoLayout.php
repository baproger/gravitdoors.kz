<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Раскладка bento-сетки инфопанели без дыр.
 *
 * У каждой роли свой набор плиток: у директора их двадцать, у замерщика две.
 * CSS-сетка с плотной укладкой (`grid-auto-flow: dense`) закрывает дыру, только
 * если какая-то из следующих плиток в неё помещается, — и у менеджера справа от
 * воронки оставалась пустая колонка. Здесь та же укладка повторяется на сервере,
 * и в каждую оставшуюся дыру растягивается соседняя плитка: слева — вширь,
 * сверху — вниз. Начало ни одной плитки не сдвигается, поэтому браузер разложит
 * плитки ровно так же, только без пустот.
 *
 * Работает для широкого экрана (12 колонок). На планшете и телефоне плитки
 * укладываются по классам размера — там колонок мало и дыр почти не бывает.
 */
final class BentoLayout
{
    /** Ширина размера плитки в колонках широкой сетки. */
    public const SPANS = ['s' => 3, 'm' => 4, 'w' => 6, 'l' => 8, 'full' => 12];

    /**
     * @param  array<string, array{size: string, tall?: bool}>  $tiles  в порядке вывода
     * @return array<string, array{span: int, rows: int}>
     */
    public static function fill(array $tiles, int $columns = 12): array
    {
        /** @var array<string, array{row: int, col: int, span: int, rows: int}> $placed */
        $placed = [];
        /** @var array<int, array<int, string>> $grid  [строка][колонка] => ключ плитки */
        $grid = [];

        foreach ($tiles as $key => $tile) {
            $span = min($columns, self::SPANS[$tile['size']] ?? 3);
            $rows = ($tile['tall'] ?? false) ? 2 : 1;
            [$row, $col] = self::firstFree($grid, $span, $rows, $columns);

            $placed[$key] = ['row' => $row, 'col' => $col, 'span' => $span, 'rows' => $rows];
            self::occupy($grid, $key, $row, $col, $span, $rows);
        }

        $lastRow = $grid === [] ? -1 : max(array_keys($grid));

        // Повторять, пока что-то растягивается: заполненная дыра может открыть
        // соседке возможность дотянуться до следующей.
        do {
            $changed = false;

            for ($row = 0; $row <= $lastRow; $row++) {
                for ($col = 0; $col < $columns; $col++) {
                    if (isset($grid[$row][$col])) {
                        continue;
                    }

                    $changed = self::widenLeft($grid, $placed, $row, $col, $columns)
                        || self::stretchDown($grid, $placed, $row, $col)
                        || $changed;
                }
            }
        } while ($changed);

        return array_map(fn (array $p): array => ['span' => $p['span'], 'rows' => $p['rows']], $placed);
    }

    /**
     * Первая свободная позиция сверху вниз, слева направо — как у браузера
     * при `grid-auto-flow: row dense`.
     *
     * @param  array<int, array<int, string>>  $grid
     * @return array{0: int, 1: int}
     */
    private static function firstFree(array $grid, int $span, int $rows, int $columns): array
    {
        for ($row = 0; ; $row++) {
            for ($col = 0; $col + $span <= $columns; $col++) {
                if (self::isFree($grid, $row, $col, $span, $rows)) {
                    return [$row, $col];
                }
            }
        }
    }

    /** @param array<int, array<int, string>> $grid */
    private static function isFree(array $grid, int $row, int $col, int $span, int $rows): bool
    {
        for ($r = $row; $r < $row + $rows; $r++) {
            for ($c = $col; $c < $col + $span; $c++) {
                if (isset($grid[$r][$c])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<int, array<int, string>> $grid */
    private static function occupy(array &$grid, string $key, int $row, int $col, int $span, int $rows): void
    {
        for ($r = $row; $r < $row + $rows; $r++) {
            for ($c = $col; $c < $col + $span; $c++) {
                $grid[$r][$c] = $key;
            }
        }
    }

    /**
     * Плитка слева от дыры растягивается вправо до конца дыры — если по всей её
     * высоте эти клетки свободны.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  array<string, array{row: int, col: int, span: int, rows: int}>  $placed
     */
    private static function widenLeft(array &$grid, array &$placed, int $row, int $col, int $columns): bool
    {
        $key = $col > 0 ? ($grid[$row][$col - 1] ?? null) : null;

        if ($key === null) {
            return false;
        }

        $tile = $placed[$key];
        $end = $col;

        while ($end < $columns && self::isFree($grid, $tile['row'], $end, 1, $tile['rows'])) {
            $end++;
        }

        if ($end === $col) {
            return false;
        }

        self::occupy($grid, $key, $tile['row'], $tile['col'] + $tile['span'], $end - ($tile['col'] + $tile['span']), $tile['rows']);
        $placed[$key]['span'] = $end - $tile['col'];

        return true;
    }

    /**
     * Плитка над дырой вытягивается вниз на строку — если под всей её шириной
     * пусто.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  array<string, array{row: int, col: int, span: int, rows: int}>  $placed
     */
    private static function stretchDown(array &$grid, array &$placed, int $row, int $col): bool
    {
        $key = $row > 0 ? ($grid[$row - 1][$col] ?? null) : null;

        if ($key === null) {
            return false;
        }

        $tile = $placed[$key];

        if ($tile['row'] + $tile['rows'] !== $row || ! self::isFree($grid, $row, $tile['col'], $tile['span'], 1)) {
            return false;
        }

        self::occupy($grid, $key, $row, $tile['col'], $tile['span'], 1);
        $placed[$key]['rows']++;

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BentoLayout;
use PHPUnit\Framework\TestCase;

/**
 * Раскладка инфопанели: у любой роли ряды заполнены целиком, а начало плиток
 * не сдвигается — иначе браузер разложил бы их иначе, чем посчитал сервер.
 */
class BentoLayoutTest extends TestCase
{
    public function test_full_rows_stay_as_they_are(): void
    {
        $layout = BentoLayout::fill([
            'a' => ['size' => 'w', 'tall' => true],
            'b' => ['size' => 's'], 'c' => ['size' => 's'],
            'd' => ['size' => 's'], 'e' => ['size' => 's'],
        ]);

        $this->assertSame(['span' => 6, 'rows' => 2], $layout['a']);
        foreach (['b', 'c', 'd', 'e'] as $key) {
            $this->assertSame(['span' => 3, 'rows' => 1], $layout[$key]);
        }
    }

    public function test_hole_beside_a_tall_tile_is_filled_by_widening_it(): void
    {
        // Как у менеджера: склад (¼) и высокая воронка (½) — справа пустая четверть в две строки.
        $layout = BentoLayout::fill([
            'stock' => ['size' => 's'],
            'funnel' => ['size' => 'w', 'tall' => true],
            'sources' => ['size' => 'w'],
        ]);

        $this->assertSame(['span' => 9, 'rows' => 2], $layout['funnel'], 'воронка дотянулась до края');
        $this->assertSame(['span' => 3, 'rows' => 2], $layout['stock'], 'склад вытянулся вниз под себя');
        $this->assertSame(['span' => 12, 'rows' => 1], $layout['sources'], 'последний ряд — во всю ширину');
    }

    public function test_last_lonely_tile_takes_the_whole_row(): void
    {
        $layout = BentoLayout::fill([
            'a' => ['size' => 'm'], 'b' => ['size' => 'm'], 'c' => ['size' => 'm'],
            'd' => ['size' => 'm'],
        ]);

        $this->assertSame(4, $layout['c']['span']);
        $this->assertSame(12, $layout['d']['span']);
    }

    public function test_every_role_like_set_leaves_no_holes(): void
    {
        $sets = [
            'директор' => ['m' => ['w', true], 'k1' => ['s'], 'k2' => ['s'], 'k3' => ['s'], 'k4' => ['s'], 'p' => ['s'], 'f1' => ['s'], 'f2' => ['s'], 'f3' => ['s'], 'st' => ['s'], 'pay' => ['s'], 'fun' => ['w', true], 'load' => ['w'], 'x1' => ['w'], 'x2' => ['w'], 'x3' => ['w'], 'x4' => ['w']],
            'менеджер' => ['me' => ['w'], 'bon' => ['s'], 'inc' => ['s'], 'k1' => ['s'], 'k2' => ['s'], 'k3' => ['s'], 'due' => ['s'], 'st' => ['s'], 'fun' => ['w', true], 'src' => ['w']],
            'рабочий' => ['earn' => ['w'], 'work' => ['w']],
            'замерщик' => ['me' => ['w', true]],
            'мастер' => ['me' => ['w'], 'f1' => ['s'], 'f2' => ['s'], 'f3' => ['s'], 'st' => ['s'], 'load' => ['w'], 'buy' => ['w']],
        ];

        foreach ($sets as $role => $set) {
            $tiles = array_map(fn (array $t): array => ['size' => $t[0], 'tall' => $t[1] ?? false], $set);
            $layout = BentoLayout::fill($tiles);

            $cells = array_sum(array_map(fn (array $t): int => $t['span'] * $t['rows'], $layout));
            $this->assertSame(0, $cells % 12, "{$role}: занятых клеток {$cells} — не целое число рядов");
        }
    }
}

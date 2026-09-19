<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Closure;

/**
 * Память на один объект: результат вычисления запоминается по ключу.
 *
 * Сводки считают одну и ту же сумму по нескольку раз за отрисовку — прибыль
 * зовёт поступления, тренд зовёт поступления, плитка зовёт поступления.
 * На сервере с одним гигабайтом памяти каждый лишний запрос к базе — это
 * лишние миллисекунды и лишний разогрев SQLite, поэтому считаем один раз.
 */
trait RemembersResults
{
    /** @var array<string, mixed> */
    private array $remembered = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    protected function once(string $key, Closure $compute): mixed
    {
        if (! array_key_exists($key, $this->remembered)) {
            $this->remembered[$key] = $compute();
        }

        return $this->remembered[$key];
    }
}

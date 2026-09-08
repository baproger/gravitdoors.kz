<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Ежедневная сводка руководству и мастерам: низкие остатки и застрявшие этапы.
 | Время выбрано на начало смены, чтобы закуп можно было сделать в тот же день.
 */
Schedule::command('gravit:daily-check')->dailyAt('08:30');

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
Schedule::command('gravit:daily-check')->dailyAt('08:30')->withoutOverlapping();

/*
 | Ночная копия базы и файлов: 14 снимков базы, 3 архива файлов. Диск маленький,
 | поэтому ротация встроена в саму команду. `withoutOverlapping`: если архив
 | папки с документами затянулся, второй tar поверх первого не запустится.
 */
Schedule::command('gravit:backup')->dailyAt('03:00')->withoutOverlapping();

/*
 | Утренняя проверка самого сервера: диск, память, отработал ли ночной бэкап.
 | Раньше сводки по складу, чтобы директор первым увидел, если машина на грани.
 */
Schedule::command('gravit:server-check')->dailyAt('08:00')->withoutOverlapping();

<?php

namespace Tests;

use App\Models\FactoryStage;
use App\Models\Setting;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Память запроса живёт в статических свойствах, а тесты идут в одном
     * процессе: без сброса права и настройки перетекали бы между тестами.
     */
    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        Setting::flushMemo();
        FactoryStage::flushMemo();
    }
}

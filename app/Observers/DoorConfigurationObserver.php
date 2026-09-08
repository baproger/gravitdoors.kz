<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DoorConfiguration;

/**
 * Номер позиции проставляется сам.
 *
 * Раньше его вводил менеджер: лишнее поле, в котором легко ошибиться и получить
 * две «Позиции 1» в одном заказе. После удаления позиции остальные
 * перенумеровываются подряд, чтобы в наряде не было дыр.
 */
class DoorConfigurationObserver
{
    public function creating(DoorConfiguration $configuration): void
    {
        if ($configuration->position === null || $configuration->position < 1) {
            $configuration->position = self::nextPosition($configuration->deal_id);
        }
    }

    public function deleted(DoorConfiguration $configuration): void
    {
        $this->renumber($configuration->deal_id);
    }

    public static function nextPosition(?int $dealId): int
    {
        if ($dealId === null) {
            return 1;
        }

        return (int) DoorConfiguration::query()->where('deal_id', $dealId)->max('position') + 1;
    }

    private function renumber(?int $dealId): void
    {
        if ($dealId === null) {
            return;
        }

        DoorConfiguration::query()
            ->where('deal_id', $dealId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(function (DoorConfiguration $configuration, int $index): void {
                if ($configuration->position !== $index + 1) {
                    $configuration->forceFill(['position' => $index + 1])->saveQuietly();
                }
            });
    }
}

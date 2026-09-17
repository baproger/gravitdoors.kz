<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BonusStatus;
use App\Models\Bonus;
use Illuminate\Validation\ValidationException;

class BonusObserver
{
    public function creating(Bonus $bonus): void
    {
        $bonus->created_by ??= auth()->id();
        $bonus->status ??= BonusStatus::Pending;
        $bonus->month ??= now()->format('Y-m');
    }

    public function saving(Bonus $bonus): void
    {
        if ((float) $bonus->amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма бонуса должна быть больше нуля.']);
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bonus->month)) {
            throw ValidationException::withMessages(['month' => 'Месяц указывается как ГГГГ-ММ.']);
        }

        if (trim($bonus->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите, за что бонус.']);
        }

        if ($bonus->status === BonusStatus::Approved && $bonus->approved_at === null) {
            $bonus->approved_at = now();
            $bonus->approved_by ??= auth()->id();
        }

        if ($bonus->status !== BonusStatus::Approved) {
            $bonus->approved_at = null;
            $bonus->approved_by = null;
        }
    }

    public function deleting(Bonus $bonus): bool
    {
        return $bonus->canBeDeleted();
    }
}

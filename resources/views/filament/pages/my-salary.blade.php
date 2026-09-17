{{-- Моя зарплата: только свои цифры. --}}
<x-filament-panels::page>
    @php
        $sheet = $this->sheet();
        $preview = $this->preview();
        $bonuses = $this->bonuses();
        $stages = $this->stages();
        $m = fn ($v) => \App\Support\Money::format($v);
    @endphp

    <div class="gravit-toolbar">
        <select wire:model.live="month" class="gravit-select">
            @foreach ($this->monthOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="gravit-bento">
        <div class="gravit-tile gravit-tile--third" style="background: rgb(15 23 42); color: #fff; border-color: rgb(15 23 42);">
            <p class="gravit-tile__label" style="color: rgb(203 213 225);">{{ $sheet ? 'К выплате по ведомости' : 'Предварительно за месяц' }}</p>
            <p class="gravit-tile__value" style="color: rgb(74 222 128);">{{ $m($sheet ? $sheet->total : $preview['total']) }}</p>
            <p class="gravit-tile__hint" style="color: rgb(148 163 184);">
                @if ($sheet)
                    {{ $sheet->status->getLabel() }} · выплачено {{ $m($sheet->paid_amount) }} · остаток {{ $m($sheet->remaining()) }}
                @else
                    ведомость за месяц ещё не сформирована
                @endif
            </p>
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Из чего складывается</p>
            <div class="gravit-lines" style="margin-top: 0.6rem;">
                <div class="gravit-line"><span>Оклад</span><span>{{ $m($sheet ? $sheet->salary : $preview['salary']) }}</span></div>
                <div class="gravit-line"><span>Сдельно</span><span>{{ $m($sheet ? $sheet->piecework : $preview['piecework']) }}</span></div>
                <div class="gravit-line"><span>Бонусы</span><span>{{ $m($sheet ? $sheet->bonuses : $preview['bonuses']) }}</span></div>
                @if ($sheet && ((float) $sheet->deductions + (float) $sheet->advances) > 0)
                    <div class="gravit-line"><span>Удержания и авансы</span><span>− {{ $m((float) $sheet->deductions + (float) $sheet->advances) }}</span></div>
                @endif
            </div>
            @if ($sheet?->comment)
                <p class="gravit-tile__hint" style="margin-top: 0.5rem;">{{ $sheet->comment }}</p>
            @endif
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Выплаты</p>
            <div class="gravit-lines" style="margin-top: 0.6rem;">
                @forelse ($sheet?->payments ?? [] as $payment)
                    <div class="gravit-line"><span>{{ $payment->paid_at->format('d.m.Y') }} · {{ $payment->method->getLabel() }}</span><span>{{ $m($payment->amount) }}</span></div>
                @empty
                    <p class="gravit-tile__hint">выплат за месяц пока нет</p>
                @endforelse
            </div>
        </div>

        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Бонусы за месяц</p>
            <div class="gravit-lines" style="margin-top: 0.6rem;">
                @forelse ($bonuses as $bonus)
                    <div class="gravit-line">
                        <span>{{ $bonus->reason }} <span style="color: var(--gravit-muted); font-weight: 400;">· {{ $bonus->status->getLabel() }}</span></span>
                        <span>{{ $m($bonus->amount) }}</span>
                    </div>
                @empty
                    <p class="gravit-tile__hint">бонусов за месяц нет</p>
                @endforelse
            </div>
        </div>

        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Закрытые этапы цеха</p>
            <div class="gravit-lines" style="margin-top: 0.6rem;">
                @forelse ($stages as $log)
                    <div class="gravit-line">
                        <span>{{ $log->finished_at?->format('d.m') }} · {{ $log->stage->name }} <span style="color: var(--gravit-muted); font-weight: 400;">· {{ $log->deal?->number }}</span></span>
                        <span>{{ $m($log->payout) }}</span>
                    </div>
                @empty
                    <p class="gravit-tile__hint">этапов за месяц нет</p>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>

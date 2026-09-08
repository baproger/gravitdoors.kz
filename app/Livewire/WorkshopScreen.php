<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\Setting;
use App\Models\User;
use App\Services\DoorProductionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Планшет цеха: вход по коду, без пароля и без сумм.
 *
 * Отдельный экран, а не админка, потому что рабочий у станка не будет
 * логиниться в Filament и искать нужный раздел — ему нужны крупные карточки
 * и одна кнопка. Данные и правила те же: двигает этапы всё тот же
 * DoorProductionService.
 */
#[Layout('components.layouts.app')]
class WorkshopScreen extends Component
{
    public string $code = '';

    public ?int $workerId = null;

    public ?string $error = null;

    public bool $authorized = false;

    public function mount(): void
    {
        $this->authorized = session()->get('workshop.authorized', false) === true;
        $this->workerId = session()->get('workshop.worker_id');
    }

    public function enter(): void
    {
        // Код короткий, поэтому перебор ограничиваем по IP.
        $key = 'workshop-code:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->error = 'Слишком много попыток. Подождите минуту.';

            return;
        }

        if (trim($this->code) !== Setting::workshopCode()) {
            RateLimiter::hit($key, 60);
            $this->error = 'Неверный код';
            $this->code = '';

            return;
        }

        RateLimiter::clear($key);
        session()->put('workshop.authorized', true);

        $this->authorized = true;
        $this->error = null;
        $this->code = '';
    }

    public function chooseWorker(int $workerId): void
    {
        $this->workerId = $workerId;
        session()->put('workshop.worker_id', $workerId);
    }

    public function leave(): void
    {
        session()->forget(['workshop.authorized', 'workshop.worker_id']);

        $this->authorized = false;
        $this->workerId = null;
    }

    /** @return Collection<int, FactoryStage> */
    public function getStagesProperty(): Collection
    {
        return app(DoorProductionService::class)->board(PipelineType::Factory, perColumn: 50);
    }

    /** @return Collection<int, User> */
    public function getWorkersProperty(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Worker->value, UserRole::Master->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function start(int $dealId): void
    {
        $this->guard();

        $order = Deal::query()->factoryOrders()->find($dealId);
        $worker = $this->worker();

        if (! $order || ! $worker) {
            $this->error = 'Сначала выберите, кто работает.';

            return;
        }

        app(DoorProductionService::class)->startStage($order, $worker);
        $this->error = null;
    }

    public function complete(int $dealId): void
    {
        $this->guard();

        $order = Deal::query()->factoryOrders()->find($dealId);

        if (! $order) {
            return;
        }

        try {
            app(DoorProductionService::class)->completeCurrentStage($order, $this->worker());
            $this->error = null;
        } catch (ProductionException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.workshop-screen');
    }

    private function worker(): ?User
    {
        return $this->workerId ? User::query()->find($this->workerId) : null;
    }

    /** Экран публичный, поэтому каждое действие перепроверяет вход по коду. */
    private function guard(): void
    {
        abort_unless(session()->get('workshop.authorized') === true, 403);
    }
}

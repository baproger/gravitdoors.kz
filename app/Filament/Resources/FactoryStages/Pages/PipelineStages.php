<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages\Pages;

use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use App\Exceptions\PipelineException;
use App\Filament\Resources\FactoryStages\FactoryStageResource;
use App\Models\FactoryStage;
use App\Services\PipelineStageService;
use App\Support\Plural;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * Настройка воронок в духе Битрикс24.
 *
 * Вместо таблицы с полями «Порядок» и «Код» — этапы строками в порядке
 * воронки: перетащить, переименовать прямо в строке, сменить цвет кликом.
 * Редкие настройки (нормативы, оплата, обязательные поля) — в окне по
 * шестерёнке. Все правки идут через PipelineStageService, который не даёт
 * сломать воронку: оставить сделки без этапа или стереть историю цеха.
 */
class PipelineStages extends Page
{
    protected static string $resource = FactoryStageResource::class;

    protected string $view = 'filament.resources.factory-stages.pipeline-stages';

    #[Url(as: 'pipeline')]
    public string $pipeline = 'sales';

    public string $newStageName = '';

    public string $newFinalStageName = '';

    public function getTitle(): string
    {
        return 'Этапы воронок';
    }

    public function getSubheading(): ?string
    {
        return 'Перетаскивайте этапы за «⋮⋮», переименовывайте прямо в строке, цвет — по клику на кружок. Изменения сохраняются сразу.';
    }

    public function pipelineType(): PipelineType
    {
        return PipelineType::tryFrom($this->pipeline) ?? PipelineType::Sales;
    }

    public function updatedPipeline(): void
    {
        $this->reset('newStageName', 'newFinalStageName');
    }

    /** @return Collection<int, FactoryStage> */
    public function stages(): Collection
    {
        return $this->service()->stagesOf($this->pipelineType());
    }

    /** @return array<string, int> */
    public function stageCounts(): array
    {
        return FactoryStage::query()
            ->selectRaw('pipeline_type, count(*) as total')
            ->groupBy('pipeline_type')
            ->pluck('total', 'pipeline_type')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    public function automationStage(): ?FactoryStage
    {
        return $this->service()->automationStage($this->pipelineType());
    }

    /** @return array<string, string> */
    public function colors(): array
    {
        return PipelineStageService::COLORS;
    }

    public function dealsWord(int $count): string
    {
        return $this->pipelineType() === PipelineType::Sales
            ? Plural::choose($count, 'сделка', 'сделки', 'сделок')
            : Plural::choose($count, 'наряд', 'наряда', 'нарядов');
    }

    public function addStage(bool $final = false): void
    {
        $property = $final ? 'newFinalStageName' : 'newStageName';

        $this->attempt(function () use ($final, $property): void {
            $stage = $this->service()->add($this->pipelineType(), $this->{$property}, $final);
            $this->{$property} = '';
            $this->notify("Этап «{$stage->name}» добавлен");
        });
    }

    public function renameStage(int $id, string $name): void
    {
        $stage = $this->stage($id);

        $renamed = $this->attempt(fn () => $this->service()->rename($stage, $name));

        // Поле ввода хранит набранный текст в браузере — при отказе возвращаем
        // в него настоящее название, иначе на экране осталось бы то, чего нет в базе.
        if (! $renamed) {
            $this->dispatch('stage-name-reset', id: $stage->id, name: $stage->name);
        }
    }

    public function recolorStage(int $id, string $color): void
    {
        $this->attempt(fn () => $this->service()->recolor($this->stage($id), $color));
    }

    public function moveStage(int $id, int $direction): void
    {
        $this->attempt(fn () => $this->service()->move($this->stage($id), $direction));
    }

    public function dropStage(int $id, ?int $beforeId = null): void
    {
        $this->attempt(fn () => $this->service()->placeBefore(
            $this->stage($id),
            $beforeId ? $this->stage($beforeId) : null,
        ));
    }

    public function toggleActive(int $id): void
    {
        $stage = $this->stage($id);

        $this->attempt(function () use ($stage): void {
            $this->service()->setActive($stage, ! $stage->is_active);
            $this->notify($stage->is_active ? "«{$stage->name}» снова в воронке" : "«{$stage->name}» скрыт из воронки");
        });
    }

    public function toggleAutomation(int $id): void
    {
        $stage = $this->stage($id);
        $column = PipelineStageService::automationColumn($stage->pipeline_type);
        $enable = ! $stage->{$column};

        $this->attempt(function () use ($stage, $enable): void {
            $this->service()->setAutomation($stage, $enable);
            $this->notify($enable ? "Автоматика завода теперь на этапе «{$stage->name}»" : 'Автоматика завода выключена');
        });
    }

    public function toggleFinal(int $id): void
    {
        $stage = $this->stage($id);

        $this->attempt(function () use ($stage): void {
            $this->service()->setFinal($stage, ! $stage->is_final);
            $this->notify($stage->is_final ? "«{$stage->name}» теперь завершающий" : "«{$stage->name}» теперь рабочий этап");
        });
    }

    public function stageSettingsAction(): Action
    {
        return Action::make('stageSettings')
            ->modalHeading(fn (array $arguments): string => 'Настройки этапа «'.$this->stageFrom($arguments)->name.'»')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Сохранить')
            ->fillForm(function (array $arguments): array {
                $stage = $this->stageFrom($arguments);

                return [
                    'description' => $stage->description,
                    'estimated_hours' => (float) $stage->estimated_hours,
                    'operation_cost' => (float) $stage->operation_cost,
                    'required_fields' => $stage->required_fields ?? [],
                ];
            })
            ->schema(fn (array $arguments): array => $this->settingsSchema($this->stageFrom($arguments)))
            ->action(function (array $data, array $arguments, Action $action): void {
                $stage = $this->stageFrom($arguments);

                try {
                    $this->service()->updateSettings($stage, $data);
                    $this->notify('Настройки этапа сохранены');
                } catch (PipelineException $e) {
                    $this->fail($e);
                    $action->halt();
                }
            });
    }

    public function deleteStageAction(): Action
    {
        return Action::make('deleteStage')
            ->requiresConfirmation()
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->modalHeading(fn (array $arguments): string => 'Удалить этап «'.$this->stageFrom($arguments)->name.'»?')
            ->modalDescription(function (array $arguments): string {
                $stage = $this->stageFrom($arguments);
                $count = $this->service()->dealsOn($stage);

                return $count > 0
                    ? "На этапе {$count} ".$this->dealsWord($count).' — они перейдут на выбранный этап, в истории каждой появится запись.'
                    : 'Этап пустой. Удаление нельзя отменить.';
            })
            ->modalSubmitActionLabel('Удалить')
            ->schema(fn (array $arguments): array => $this->deleteSchema($this->stageFrom($arguments)))
            ->action(function (array $data, array $arguments, Action $action): void {
                $stage = $this->stageFrom($arguments);
                $target = filled($data['move_to'] ?? null) ? $this->stage((int) $data['move_to']) : null;
                $name = $stage->name;

                try {
                    $this->service()->delete($stage, $target, auth()->user());
                    $this->notify("Этап «{$name}» удалён");
                } catch (PipelineException $e) {
                    $this->fail($e);
                    $action->halt();
                }
            });
    }

    /** @return list<mixed> */
    private function settingsSchema(FactoryStage $stage): array
    {
        $isSales = $stage->pipeline_type === PipelineType::Sales;

        return [
            Textarea::make('description')
                ->label('Что происходит на этапе')
                ->helperText('Подсказка для сотрудников — видна в полосе этапов карточки.')
                ->rows(2)
                ->maxLength(500),

            Grid::make(2)->schema([
                TextInput::make('estimated_hours')
                    ->label('Норматив')
                    ->helperText($isSales ? 'Сколько сделка может стоять на этапе' : 'Сколько длится операция')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->step(0.25)
                    ->suffix('ч'),

                TextInput::make('operation_cost')
                    ->label('Сдельная оплата')
                    ->helperText('За одну операцию, идёт в зарплату цеха')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->visible(! $isSales),
            ]),

            CheckboxList::make('required_fields')
                ->label('Что обязательно заполнить, чтобы сделку пустили на этап')
                ->options(StageRequirement::class)
                ->columns(2)
                ->bulkToggleable()
                ->visible($isSales),
        ];
    }

    /** @return list<mixed> */
    private function deleteSchema(FactoryStage $stage): array
    {
        if ($this->service()->dealsOn($stage) === 0) {
            return [];
        }

        return [
            Select::make('move_to')
                ->label('Перенести на этап')
                ->options(fn (): array => FactoryStage::query()
                    ->ofPipeline($stage->pipeline_type)
                    ->active()
                    ->whereKeyNot($stage->id)
                    ->ordered()
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->native(false),
        ];
    }

    private function attempt(Closure $change): bool
    {
        try {
            $change();

            return true;
        } catch (PipelineException $e) {
            $this->fail($e);

            return false;
        }
    }

    /** @param array<string, mixed> $arguments */
    private function stageFrom(array $arguments): FactoryStage
    {
        return $this->stage((int) ($arguments['stage'] ?? 0));
    }

    private function stage(int $id): FactoryStage
    {
        $stage = FactoryStage::query()->findOrFail($id);

        abort_unless(auth()->user()?->can('update', $stage), 403);

        return $stage;
    }

    private function service(): PipelineStageService
    {
        return app(PipelineStageService::class);
    }

    private function notify(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }

    private function fail(PipelineException $e): void
    {
        Notification::make()->danger()->title('Не получилось')->body($e->getMessage())->send();
    }
}

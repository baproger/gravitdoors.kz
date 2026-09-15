<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DealEventType;
use App\Models\Deal;
use App\Models\DealStageVisit;
use App\Models\FactoryStage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Пересобирает журнал заходов на этапы из истории сделки.
 *
 * Журнал появился позже самих сделок, а история смен этапов велась с самого
 * начала — с точным временем и названиями «откуда → куда». Из неё заходы
 * восстанавливаются без домыслов; сделка без событий получает один заход
 * с времени создания.
 */
class RebuildStageVisits extends Command
{
    protected $signature = 'gravit:rebuild-stage-visits {--deal= : Только одна сделка (id)}';

    protected $description = 'Восстановить журнал заходов на этапы из истории сделок';

    public function handle(): int
    {
        $deals = Deal::query()
            ->withTrashed()
            ->when($this->option('deal'), fn ($q, $id) => $q->whereKey($id))
            ->with('events')
            ->get();

        $rebuilt = 0;

        foreach ($deals as $deal) {
            $visits = $this->reconstruct($deal);

            if ($visits === null) {
                $this->warn("{$deal->number}: не удалось сопоставить этапы из истории — журнал оставлен как есть");

                continue;
            }

            DB::transaction(function () use ($deal, $visits): void {
                $deal->stageVisits()->delete();

                foreach ($visits as $visit) {
                    DealStageVisit::create(['deal_id' => $deal->id, ...$visit]);
                }
            });

            $rebuilt++;
        }

        $this->info("Пересобрано сделок: {$rebuilt} из {$deals->count()}");

        return self::SUCCESS;
    }

    /**
     * @return list<array{stage_id: int, entered_at: Carbon, left_at: ?Carbon}>|null
     */
    private function reconstruct(Deal $deal): ?array
    {
        $stages = FactoryStage::query()->ofPipeline($deal->pipeline_type)->get()->keyBy(fn (FactoryStage $s): string => mb_strtolower($s->name));
        $events = $deal->events->where('type', DealEventType::StageChanged)->sortBy('id')->values();

        $moves = [];

        foreach ($events as $event) {
            if (! preg_match('/^Этап: (?:«(.+?)» → )?«(.+?)»$/u', $event->description, $m)) {
                continue;
            }

            $from = filled($m[1]) ? ($stages[mb_strtolower($m[1])] ?? null) : null;
            $to = $stages[mb_strtolower($m[2])] ?? null;

            if ($to === null || (filled($m[1]) && $from === null)) {
                return null;
            }

            $moves[] = ['from' => $from?->id, 'to' => $to->id, 'at' => $event->created_at];
        }

        // Стартовый этап: «откуда» первого перехода, иначе текущий.
        $current = $moves[0]['from'] ?? $deal->current_stage_id;

        if ($current === null) {
            return [];
        }

        $visits = [];
        $enteredAt = $deal->created_at;

        foreach ($moves as $move) {
            $visits[] = ['stage_id' => $current, 'entered_at' => $enteredAt, 'left_at' => $move['at']];
            $current = $move['to'];
            $enteredAt = $move['at'];
        }

        // Последний заход открыт, если сделка всё ещё на этом этапе.
        $visits[] = ['stage_id' => $current, 'entered_at' => $enteredAt, 'left_at' => $current === $deal->current_stage_id ? null : now()];

        return $visits;
    }
}

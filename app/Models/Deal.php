<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Observers\DealObserver;
use App\Services\AccessControl;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Сделка отдела продаж или производственный наряд завода — различаются
 * значением pipeline_type. Наряд знает свою сделку через parent_deal_id,
 * сделка свой наряд — через productionOrder().
 *
 * @property int $id
 * @property string $number
 * @property string $title
 * @property string $client_name
 * @property string|null $client_phone
 * @property string|null $client_address
 * @property numeric $total_price
 * @property numeric $cost_price
 * @property DealStatus $status_id
 * @property PipelineType $pipeline_type
 * @property int|null $current_stage_id
 * @property string $qr_code_hash
 * @property int|null $parent_deal_id
 * @property int|null $manager_id
 * @property Carbon|null $due_date
 * @property Carbon|null $stage_entered_at
 * @property Carbon|null $production_started_at
 * @property Carbon|null $production_finished_at
 * @property Carbon|null $shipment_blocked_at
 * @property string|null $shipment_block_reason
 * @property int|null $shipment_blocked_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property ClientType $client_type
 * @property string|null $client_company
 * @property string|null $client_bin
 * @property string|null $client_email
 * @property string|null $client_phone_extra
 * @property string|null $city
 * @property DealSource|null $source
 * @property string|null $contract_number
 * @property Carbon|null $contract_date
 * @property Carbon|null $measured_at дата и время выезда замерщика
 * @property int|null $measurement_height высота проёма по замеру, мм
 * @property int|null $measurement_width ширина проёма по замеру, мм
 * @property string|null $measurement_comment
 * @property Carbon|null $measurement_done_at когда замер фактически проведён
 * @property int|null $measurement_by_id
 * @property numeric $prepayment
 * @property PaymentMethod|null $payment_method
 * @property numeric $delivery_cost
 * @property numeric $installation_cost
 * @property array<array-key, mixed>|null $documents
 * @property-read FactoryStage|null $currentStage
 * @property-read User|null $measuredBy
 * @property-read DealStageVisit|null $latestStageVisit
 * @property-read Collection<int, DoorConfiguration> $doorConfigurations
 * @property-read int|null $door_configurations_count
 * @property-read Collection<int, DealEvent> $events
 * @property-read int|null $events_count
 * @property-read float $hours_on_stage
 * @property-read float $margin
 * @property-read float $profit
 * @property-read string $track_url
 * @property-read User|null $manager
 * @property-read Deal|null $parentDeal
 * @property-read Collection<int, DealPayment> $payments
 * @property-read int|null $payments_count
 * @property-read Collection<int, ProductionLog> $productionLogs
 * @property-read int|null $production_logs_count
 * @property-read Deal|null $productionOrder
 * @property-read Collection<int, StockMovement> $stockMovements
 * @property-read int|null $stock_movements_count
 *
 * @method static \Database\Factories\DealFactory factory($count = null, $state = [])
 * @method static Builder<static>|Deal factoryOrders()
 * @method static Builder<static>|Deal newModelQuery()
 * @method static Builder<static>|Deal newQuery()
 * @method static Builder<static>|Deal onlyTrashed()
 * @method static Builder<static>|Deal open()
 * @method static Builder<static>|Deal query()
 * @method static Builder<static>|Deal sales()
 * @method static Builder<static>|Deal whereCity($value)
 * @method static Builder<static>|Deal whereClientAddress($value)
 * @method static Builder<static>|Deal whereClientBin($value)
 * @method static Builder<static>|Deal whereClientCompany($value)
 * @method static Builder<static>|Deal whereClientEmail($value)
 * @method static Builder<static>|Deal whereClientName($value)
 * @method static Builder<static>|Deal whereClientPhone($value)
 * @method static Builder<static>|Deal whereClientPhoneExtra($value)
 * @method static Builder<static>|Deal whereClientType($value)
 * @method static Builder<static>|Deal whereContractDate($value)
 * @method static Builder<static>|Deal whereContractNumber($value)
 * @method static Builder<static>|Deal whereCostPrice($value)
 * @method static Builder<static>|Deal whereCreatedAt($value)
 * @method static Builder<static>|Deal whereCurrentStageId($value)
 * @method static Builder<static>|Deal whereDeletedAt($value)
 * @method static Builder<static>|Deal whereDeliveryCost($value)
 * @method static Builder<static>|Deal whereDocuments($value)
 * @method static Builder<static>|Deal whereDueDate($value)
 * @method static Builder<static>|Deal whereId($value)
 * @method static Builder<static>|Deal whereInstallationCost($value)
 * @method static Builder<static>|Deal whereManagerId($value)
 * @method static Builder<static>|Deal whereMeasuredAt($value)
 * @method static Builder<static>|Deal whereNotes($value)
 * @method static Builder<static>|Deal whereNumber($value)
 * @method static Builder<static>|Deal whereParentDealId($value)
 * @method static Builder<static>|Deal wherePaymentMethod($value)
 * @method static Builder<static>|Deal wherePipelineType($value)
 * @method static Builder<static>|Deal wherePrepayment($value)
 * @method static Builder<static>|Deal whereProductionFinishedAt($value)
 * @method static Builder<static>|Deal whereProductionStartedAt($value)
 * @method static Builder<static>|Deal whereQrCodeHash($value)
 * @method static Builder<static>|Deal whereSource($value)
 * @method static Builder<static>|Deal whereStageEnteredAt($value)
 * @method static Builder<static>|Deal whereStatusId($value)
 * @method static Builder<static>|Deal whereTitle($value)
 * @method static Builder<static>|Deal whereTotalPrice($value)
 * @method static Builder<static>|Deal whereUpdatedAt($value)
 * @method static Builder<static>|Deal withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Deal withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(DealObserver::class)]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'title',
        'client_name', 'client_type', 'client_company', 'client_bin',
        'client_phone', 'client_email', 'client_phone_extra', 'client_address', 'city', 'source',
        'contract_number', 'contract_date', 'documents', 'measured_at',
        'measurement_height', 'measurement_width', 'measurement_comment',
        'measurement_done_at', 'measurement_by_id',
        'total_price', 'cost_price', 'prepayment', 'payment_method',
        'delivery_cost', 'installation_cost',
        'status_id', 'pipeline_type', 'current_stage_id',
        'qr_code_hash', 'parent_deal_id', 'manager_id', 'due_date',
        'stage_entered_at', 'production_started_at', 'production_finished_at', 'notes',
        'shipment_blocked_at', 'shipment_block_reason', 'shipment_blocked_by',
    ];

    protected function casts(): array
    {
        return [
            'status_id' => DealStatus::class,
            'pipeline_type' => PipelineType::class,
            'client_type' => ClientType::class,
            'source' => DealSource::class,
            'payment_method' => PaymentMethod::class,
            'total_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'prepayment' => 'decimal:2',
            'delivery_cost' => 'decimal:2',
            'installation_cost' => 'decimal:2',
            'due_date' => 'date',
            'contract_date' => 'date',
            'measured_at' => 'datetime',
            'measurement_height' => 'integer',
            'measurement_width' => 'integer',
            'measurement_done_at' => 'datetime',
            'documents' => 'array',
            'stage_entered_at' => 'datetime',
            'production_started_at' => 'datetime',
            'production_finished_at' => 'datetime',
            'shipment_blocked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal): void {
            $deal->qr_code_hash ??= static::generateHash();
            $deal->number ??= static::generateNumber($deal->pipeline_type ?? PipelineType::Sales);
            $deal->stage_entered_at ??= now();
        });
    }

    public static function generateHash(): string
    {
        do {
            $hash = Str::lower(Str::random(24));
        } while (static::withTrashed()->where('qr_code_hash', $hash)->exists());

        return $hash;
    }

    /**
     * Сквозная нумерация внутри воронки и года: GRV-26-0001, PRD-26-0001.
     * Считаем по количеству, а не по max(id), иначе номера перескакивали бы
     * через записи соседней воронки, и бухгалтерия видела бы дыры в реестре.
     */
    public static function generateNumber(PipelineType|string $type): string
    {
        $type = $type instanceof PipelineType ? $type : PipelineType::from($type);
        $prefix = $type === PipelineType::Factory ? 'PRD' : 'GRV';
        $year = now()->format('y');

        $sequence = static::withTrashed()
            ->where('pipeline_type', $type->value)
            ->where('number', 'like', "{$prefix}-{$year}-%")
            ->count();

        do {
            $sequence++;
            $number = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
        } while (static::withTrashed()->where('number', $number)->exists());

        return $number;
    }

    /** @return BelongsTo<FactoryStage, $this> */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(FactoryStage::class, 'current_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Кто снял замер. @return BelongsTo<User, $this> */
    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measurement_by_id');
    }

    /** Сделка продаж, породившая этот наряд. @return BelongsTo<Deal, $this> */
    public function parentDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'parent_deal_id');
    }

    /**
     * Все наряды по сделке, включая отменённые. Для подсчётов «есть ли живой
     * наряд»: whereHas поверх связи с latestOfMany() в SQLite считает неверно.
     *
     * @return HasMany<Deal, $this>
     */
    public function productionOrders(): HasMany
    {
        return $this->hasMany(Deal::class, 'parent_deal_id')
            ->where('pipeline_type', PipelineType::Factory->value);
    }

    /** Последний наряд завода по этой сделке. @return HasOne<Deal, $this> */
    public function productionOrder(): HasOne
    {
        // Последний наряд: после отмены и повторной передачи в цех у сделки их
        // два, и без latestOfMany() связь возвращала отменённый.
        return $this->hasOne(Deal::class, 'parent_deal_id')
            ->where('pipeline_type', PipelineType::Factory->value)
            ->latestOfMany();
    }

    /**
     * Позиции сделки. В один заказ может входить несколько разных дверей,
     * поэтому связь множественная: суммы и списание материалов считаются
     * по всем позициям сразу.
     *
     * @return HasMany<DoorConfiguration, $this>
     */
    public function doorConfigurations(): HasMany
    {
        return $this->hasMany(DoorConfiguration::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<DealPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(DealPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** @return HasMany<DealStageVisit, $this> */
    public function stageVisits(): HasMany
    {
        return $this->hasMany(DealStageVisit::class)->orderBy('entered_at');
    }

    /**
     * Последний заход на этап: по нему видно, кто и когда двинул сделку.
     *
     * @return HasOne<DealStageVisit, $this>
     */
    public function latestStageVisit(): HasOne
    {
        return $this->hasOne(DealStageVisit::class)->latestOfMany();
    }

    /** @return HasMany<DealEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DealEvent::class);
    }

    /** @return HasMany<ProductionLog, $this> */
    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param Builder<Deal> $query */
    public function scopeSales(Builder $query): void
    {
        $query->where('pipeline_type', PipelineType::Sales->value);
    }

    /**
     * Только производственные наряды.
     *
     * Назван не `factory`: `Deal::factory()` уже занято Eloquent-фабрикой,
     * и одинаковое имя означало бы разное в статическом вызове и на билдере.
     *
     * @param  Builder<Deal>  $query
     */
    public function scopeFactoryOrders(Builder $query): void
    {
        $query->where('pipeline_type', PipelineType::Factory->value);
    }

    /**
     * Что пользователю вообще видно: воронки по правам и «только свои» —
     * по владельцу. Один scope на список, канбан, поиск и просроченные,
     * иначе ограничение легко забыть в очередном экране.
     *
     * @param  Builder<Deal>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }

        $sales = AccessControl::levelFor($user, Permission::WorkSalesKanban);
        $factory = AccessControl::levelFor($user, Permission::WorkFactoryKanban);

        match (true) {
            ! $sales->allows() && ! $factory->allows() => $query->whereRaw('1 = 0'),
            ! $sales->allows() => $query->factoryOrders(),
            ! $factory->allows() => $query->sales(),
            default => null,
        };

        if (AccessControl::levelFor($user, Permission::WorkDeals)->isOwnOnly()) {
            $query->where(function (Builder $inner) use ($user): void {
                // Сделка без ответственного видна всем «своим»: иначе запись потеряется.
                $inner->where('manager_id', $user->id)->orWhereNull('manager_id');
            });
        }
    }

    /**
     * Убрать из списка наряды, которые цех уже закончил.
     *
     * Наряд — копия своей сделки: тот же клиент, та же сумма, тот же срок.
     * Пока он в цеху, это отдельная работа, и своя строка ему нужна. Но как
     * только цех закончил и заказ вернулся в продажи, строка начинает
     * дублировать сделку — и при этом не попадает ни в одну вкладку, кроме
     * «Все». Получалось «Закрытые 1, Все 2», а вторую запись было не найти.
     *
     * Прячем только у тех, кому видна сама сделка. У мастера цеха воронки
     * продаж нет, и для него наряд — единственная запись о заказе: спрятав
     * её, мы стёрли бы всю историю его работы.
     *
     * Наряд без сделки (сделку удалили) остаётся: иначе он потеряется совсем.
     */
    public function scopeWithoutFinishedOrders(Builder $query, ?User $user): void
    {
        if (! AccessControl::allows($user, Permission::WorkSalesKanban)) {
            return;
        }

        $query->where(function (Builder $inner): void {
            $inner->where('pipeline_type', '!=', PipelineType::Factory->value)
                ->orWhereNull('parent_deal_id')
                ->orWhereNotIn('status_id', DealStatus::closedValues());
        });
    }

    /** Завершённые и отменённые: архив, из которого растёт база клиентов. */
    public function scopeClosed(Builder $query): void
    {
        $query->whereIn('status_id', DealStatus::closedValues());
    }

    /** @param Builder<Deal> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status_id', DealStatus::closedValues());
    }

    /**
     * Незавершённый наряд завода по этой сделке продаж.
     *
     * Пока он есть, сделку по воронке ведёт производство, а не менеджер.
     * Если связь уже подгружена (канбан берёт её одним запросом), новый
     * запрос не делается.
     */
    public function activeProductionOrder(): ?self
    {
        if ($this->isFactoryOrder()) {
            return null;
        }

        $order = $this->relationLoaded('productionOrder')
            ? $this->productionOrder
            : $this->productionOrder()->with('currentStage')->first();

        return $order && ! $order->status_id->isClosed() ? $order : null;
    }

    /**
     * Можно ли удалить запись. Наряд удаляется только закрытый (живой —
     * отменяется), сделка — только без наряда в цеху: иначе на заводе останется
     * наряд-сирота с невозвращёнными материалами. Проверяется здесь, а не только
     * в политике: администратор проходит любую политику через Gate::before.
     */
    public function canBeDeleted(): bool
    {
        if ($this->isFactoryOrder()) {
            return $this->status_id->isClosed();
        }

        return $this->activeProductionOrder() === null;
    }

    /** Готовый (закрытый, не отменённый) наряд по сделке — двери уже сделаны. */
    public function completedProductionOrder(): ?self
    {
        if ($this->isFactoryOrder()) {
            return null;
        }

        /** @var self|null $order */
        $order = $this->productionOrder()
            ->where('status_id', DealStatus::Completed->value)
            ->latest('id')
            ->first();

        return $order;
    }

    /**
     * Есть ли позиции — без запроса, если список их уже посчитал.
     *
     * В списке сделок кнопка «Пересчитать цену» спрашивала это у каждой
     * строки: 25 строк на странице — 25 запросов, и чем больше сделок, тем
     * медленнее главная рабочая страница. Теперь ответ приходит вместе со
     * списком (`withExists`), а запрос остаётся только для одиночной карточки.
     */
    public function hasDoorConfigurations(): bool
    {
        $deal = $this->salesDeal();
        $loaded = $deal->getAttribute('door_configurations_exists');

        return $loaded !== null ? (bool) $loaded : $deal->doorConfigurations()->exists();
    }

    /**
     * Делались ли двери по этой сделке хоть раз — тоже из списка, если он спросил.
     *
     * Смысл тот же, что у `completedProductionOrder()`: важно не «какой наряд»,
     * а «был ли завершённый». Отменённые наряды не в счёт — после отмены
     * сделку передают в цех заново.
     */
    public function hasCompletedProductionOrder(): bool
    {
        $loaded = $this->getAttribute('has_completed_order');

        return $loaded !== null ? (bool) $loaded : $this->completedProductionOrder() !== null;
    }

    /** Финансы придержали отгрузку: дальше «Готово к отгрузке» сделка не пойдёт. */
    public function isShipmentBlocked(): bool
    {
        return $this->shipment_blocked_at !== null;
    }

    public function isFactoryOrder(): bool
    {
        return $this->pipeline_type === PipelineType::Factory;
    }

    /** Сделка продаж, к которой относится запись: для наряда — родитель, для сделки — она сама. */
    public function salesDeal(): self
    {
        return $this->isFactoryOrder() ? ($this->parentDeal ?? $this) : $this;
    }

    /** Позиции сделки продаж — у наряда своих позиций нет, он берёт родительские. */
    public function configurations(): Collection
    {
        return $this->salesDeal()->doorConfigurations;
    }

    /** Сколько дверей всего в заказе, с учётом количества в каждой позиции. */
    public function doorsCount(): int
    {
        return (int) $this->configurations()->sum('quantity');
    }

    /** Сколько клиент ещё должен. */
    public function remainingPayment(): float
    {
        return max(0.0, round((float) $this->total_price - (float) $this->prepayment, 2));
    }

    public function isPaidInFull(): bool
    {
        return $this->remainingPayment() <= 0.0 && (float) $this->total_price > 0.0;
    }

    /** Услуги сверх стоимости самих дверей. */
    public function servicesCost(): float
    {
        return round((float) $this->delivery_cost + (float) $this->installation_cost, 2);
    }

    /** Как клиента показывать в списках: компанию — по названию, физлицо — по имени. */
    public function clientTitle(): string
    {
        return $this->client_type === ClientType::Company && filled($this->client_company)
            ? $this->client_company
            : $this->client_name;
    }

    public function getProfitAttribute(): float
    {
        return (float) $this->total_price - (float) $this->cost_price;
    }

    public function getMarginAttribute(): float
    {
        $total = (float) $this->total_price;

        return $total > 0.0 ? round($this->profit / $total * 100, 2) : 0.0;
    }

    /** Срок сдачи прошёл, а сделка ещё открыта. У закрытой срок уже не важен. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->status_id->isClosed()
            && $this->due_date->lt(today());
    }

    /** На сколько дней просрочен срок сдачи; 0 — не просрочен. */
    public function overdueDays(): int
    {
        return $this->isOverdue() ? (int) $this->due_date->diffInDays(today()) : 0;
    }

    /** Замерщик съездил и записал результат. */
    public function hasMeasurement(): bool
    {
        return $this->measurement_done_at !== null;
    }

    /** Размеры проёма по замеру: «2100 × 900 мм». */
    public function measurementSize(): ?string
    {
        if ($this->measurement_height === null || $this->measurement_width === null) {
            return null;
        }

        return "{$this->measurement_height} × {$this->measurement_width} мм";
    }

    /**
     * Замер назначен, дата прошла, а сделка так и не дошла до этапа, которому
     * замер нужен. Если ни один этап замера не требует — не отслеживается.
     */
    public function isMeasurementOverdue(): bool
    {
        if ($this->isFactoryOrder() || $this->measured_at === null || $this->status_id->isClosed() || ! $this->measured_at->lt(today())) {
            return false;
        }

        // Ворота одни на всю воронку и запоминаются на весь запрос (FactoryStage).
        $gate = FactoryStage::measurementGate();

        return $gate !== null && ($this->currentStage->order ?? 0) < $gate->order;
    }

    public function measurementOverdueDays(): int
    {
        // По календарным дням: замер вчера в 14:00 — это один день просрочки, а не 0,41.
        return $this->isMeasurementOverdue() ? (int) $this->measured_at->copy()->startOfDay()->diffInDays(today()) : 0;
    }

    /** Замеры, назначенные на сегодня (по календарному дню). */
    public function scopeMeasurementToday(Builder $query): void
    {
        $query->sales()->open()->whereDate('measured_at', today())->orderBy('measured_at');
    }

    /** Открытые сделки с просроченным замером — самые давние первыми. */
    public function scopeMeasurementOverdue(Builder $query): void
    {
        $gate = FactoryStage::measurementGate();

        if ($gate === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->sales()->open()
            ->whereNotNull('measured_at')
            ->whereDate('measured_at', '<', today())
            ->whereHas('currentStage', fn (Builder $q) => $q->where('order', '<', $gate->order))
            ->orderBy('measured_at');
    }

    /** Стоит на этапе дольше норматива (estimated_hours этапа, если он задан). */
    public function isStageOverdue(): bool
    {
        $limit = (float) ($this->currentStage?->estimated_hours ?? 0);

        return $limit > 0
            && ! $this->status_id->isClosed()
            && $this->stage_entered_at !== null
            && $this->hours_on_stage > $limit;
    }

    /** На сколько часов превышен норматив этапа; 0 — в норме. */
    public function stageOverdueHours(): float
    {
        return $this->isStageOverdue()
            ? round($this->hours_on_stage - (float) $this->currentStage->estimated_hours, 1)
            : 0.0;
    }

    /** Открытые сделки и наряды с прошедшим сроком сдачи — самые просроченные первыми. */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', today())->orderBy('due_date');
    }

    /**
     * Сколько сделка провела на текущем этапе — за все заходы, не только с
     * последнего входа. Вернули на этап — прежние часы никуда не деваются.
     */
    public function getHoursOnStageAttribute(): float
    {
        if ($this->current_stage_id === null) {
            return 0.0;
        }

        $closed = $this->relationLoaded('stageVisits')
            ? $this->stageVisits->where('stage_id', $this->current_stage_id)->whereNotNull('left_at')
            : $this->stageVisits()->where('stage_id', $this->current_stage_id)->whereNotNull('left_at')->get();

        $minutes = $closed->sum(fn (DealStageVisit $visit): int => (int) $visit->entered_at->diffInMinutes($visit->left_at));

        if ($this->stage_entered_at) {
            $minutes += (int) $this->stage_entered_at->diffInMinutes(now());
        }

        return round($minutes / 60, 1);
    }

    /** Время только текущего захода — для подписи «в этот заход N ч». */
    public function hoursOnCurrentVisit(): float
    {
        return $this->stage_entered_at ? round($this->stage_entered_at->diffInMinutes(now()) / 60, 1) : 0.0;
    }

    /** Который это заход на текущий этап: 1 — первый, 2 — сделку вернули. */
    public function visitsOnCurrentStage(): int
    {
        if ($this->current_stage_id === null) {
            return 0;
        }

        return max(1, $this->relationLoaded('stageVisits')
            ? $this->stageVisits->where('stage_id', $this->current_stage_id)->count()
            : $this->stageVisits()->where('stage_id', $this->current_stage_id)->count());
    }

    /**
     * Сколько часов сделка провела на каждом этапе за всё время — для полосы этапов.
     *
     * @return array<int, float> stage_id => часы
     */
    public function hoursByStage(): array
    {
        return $this->stageVisits()->get()
            ->groupBy('stage_id')
            ->map(fn ($visits): float => round($visits->sum(fn (DealStageVisit $v): int => (int) $v->entered_at->diffInMinutes($v->left_at ?? now())) / 60, 1))
            ->all();
    }

    public function getTrackUrlAttribute(): string
    {
        return route('track.show', $this->qr_code_hash);
    }
}

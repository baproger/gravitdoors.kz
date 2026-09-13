<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Observers\DealObserver;
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
 * @property Carbon|null $measured_at
 * @property numeric $prepayment
 * @property PaymentMethod|null $payment_method
 * @property numeric $delivery_cost
 * @property numeric $installation_cost
 * @property array<array-key, mixed>|null $documents
 * @property-read FactoryStage|null $currentStage
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
        'total_price', 'cost_price', 'prepayment', 'payment_method',
        'delivery_cost', 'installation_cost',
        'status_id', 'pipeline_type', 'current_stage_id',
        'qr_code_hash', 'parent_deal_id', 'manager_id', 'due_date',
        'stage_entered_at', 'production_started_at', 'production_finished_at', 'notes',
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
            'measured_at' => 'date',
            'documents' => 'array',
            'stage_entered_at' => 'datetime',
            'production_started_at' => 'datetime',
            'production_finished_at' => 'datetime',
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

    /** @param Builder<Deal> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status_id', [DealStatus::Completed->value, DealStatus::Cancelled->value]);
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

    /** Сколько часов сделка стоит на текущем этапе — для «⏱» на канбане. */
    public function getHoursOnStageAttribute(): float
    {
        return $this->stage_entered_at ? round($this->stage_entered_at->diffInMinutes(now()) / 60, 1) : 0.0;
    }

    public function getTrackUrlAttribute(): string
    {
        return route('track.show', $this->qr_code_hash);
    }
}

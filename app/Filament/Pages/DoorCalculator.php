<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DoorConfigurationSchema;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Services\DoorPriceCalculator;
use App\Services\DoorProductionService;
use App\Support\PriceBreakdown;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Калькулятор стоимости двери.
 *
 * Считает тот же DoorPriceCalculator, что и сделки, — отдельной «калькуляторной»
 * математики не существует, иначе КП и счёт расходились бы в цене.
 */
class DoorCalculator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Калькулятор двери';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'calculator';

    protected string $view = 'filament.pages.door-calculator';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Калькулятор показывает себестоимость и маржу — цеху он закрыт. */
    public static function canAccess(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Спецификация')
                    // Форма делит ширину с итоговой панелью, поэтому колонок
                    // меньше, чем в карточке сделки, — иначе поля сжимаются.
                    ->columns(['default' => 1, 'md' => 2, '2xl' => 3])
                    ->schema(DoorConfigurationSchema::components(withPreview: false)),
            ])
            ->statePath('data');
    }

    public function getTitle(): string
    {
        return 'Калькулятор двери';
    }

    public function getSubheading(): ?string
    {
        return 'Габариты × металл + панели МДФ + фурнитура. Прайс настраивается в разделе «Прайс конфигуратора».';
    }

    public function breakdown(): PriceBreakdown
    {
        return app(DoorPriceCalculator::class)->calculate($this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDeal')
                ->label('Создать сделку с этим расчётом')
                ->icon('heroicon-o-plus-circle')
                ->schema([
                    TextInput::make('title')->label('Название сделки')->required(),
                    TextInput::make('client_name')->label('Заказчик')->required(),
                    TextInput::make('client_phone')->label('Телефон')->tel(),
                ])
                ->action(function (array $data, DoorProductionService $production) {
                    $deal = Deal::create([
                        'title' => $data['title'],
                        'client_name' => $data['client_name'],
                        'client_phone' => $data['client_phone'] ?? null,
                        'status_id' => DealStatus::New,
                        'pipeline_type' => PipelineType::Sales,
                        'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
                        'manager_id' => auth()->id(),
                    ]);

                    DoorConfiguration::create([
                        'deal_id' => $deal->id,
                        'position' => 1,
                        'height' => $this->data['height'] ?? config('gravit.pricing.default_height'),
                        'width' => $this->data['width'] ?? config('gravit.pricing.default_width'),
                        'opening_side' => $this->data['opening_side'] ?? 'right',
                        'quantity' => $this->data['quantity'] ?? 1,
                        'metal_thickness' => $this->data['metal_thickness'] ?? null,
                        'outer_mdf_panel' => $this->data['outer_mdf_panel'] ?? null,
                        'inner_mdf_panel' => $this->data['inner_mdf_panel'] ?? null,
                        'lock_system' => $this->data['lock_system'] ?? null,
                        'insulation_type' => $this->data['insulation_type'] ?? null,
                        'color_coating' => $this->data['color_coating'] ?? null,
                        'additional_options' => $this->data['additional_options'] ?? [],
                        'comment' => $this->data['comment'] ?? null,
                    ]);

                    $production->syncPricing($deal->refresh());

                    Notification::make()->success()->title("Сделка {$deal->number} создана")->send();

                    return redirect(DealResource::getUrl('edit', ['record' => $deal]));
                }),
        ];
    }
}

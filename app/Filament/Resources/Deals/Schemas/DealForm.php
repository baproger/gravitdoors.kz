<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\FactoryStage;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Клиент и сделка')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label('Название сделки')
                        ->placeholder('ЖК «Алатау», кв. 45')
                        ->required()
                        ->columnSpanFull(),

                    TextInput::make('client_name')->label('Заказчик')->required(),
                    TextInput::make('client_phone')->label('Телефон')->tel()->placeholder('+7 700 000 00 00'),
                    TextInput::make('client_address')->label('Адрес монтажа')->columnSpanFull(),

                    Select::make('manager_id')
                        ->label('Менеджер')
                        ->options(fn (): array => User::query()
                            ->whereIn('role', [UserRole::Manager->value, UserRole::Admin->value])
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false),

                    DatePicker::make('due_date')->label('Срок сдачи')->displayFormat('d.m.Y'),

                    Select::make('pipeline_type')
                        ->label('Воронка')
                        ->options(PipelineType::class)
                        ->default(PipelineType::Sales->value)
                        ->required()
                        ->live()
                        ->native(false)
                        // Наряды создаются автоматикой из сделки продаж, а не руками:
                        // иначе появились бы наряды без родительской сделки.
                        ->disabled(fn (?string $operation): bool => $operation === 'edit'),

                    Select::make('current_stage_id')
                        ->label('Этап воронки')
                        ->options(fn (Get $get): array => FactoryStage::query()
                            ->ofPipeline(self::pipelineOf($get('pipeline_type')))
                            ->active()
                            ->ordered()
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => FactoryStage::firstOf(PipelineType::Sales)?->id)
                        ->helperText('Смена этапа здесь не запускает автоматизацию — для этого используйте канбан или действия в списке.')
                        ->native(false),

                    Select::make('status_id')
                        ->label('Статус')
                        ->options(DealStatus::class)
                        ->default(DealStatus::New->value)
                        ->required()
                        ->native(false),

                    Textarea::make('notes')->label('Заметка')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Двери заказа')
                ->description('Каждая позиция — отдельная дверь. Из позиций считается цена сделки и списываются материалы при передаче в цех.')
                ->visible(fn (Get $get): bool => self::isSalesPipeline($get('pipeline_type')))
                ->schema([
                    Repeater::make('doorConfigurations')
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(['default' => 1, 'sm' => 2, '2xl' => 4])
                        ->schema(DoorConfigurationSchema::components())
                        ->itemLabel(fn (array $state): string => filled($state['label'] ?? null)
                            ? "Позиция {$state['position']} · {$state['label']}"
                            : 'Позиция '.($state['position'] ?? 1))
                        ->addActionLabel('Добавить дверь')
                        ->defaultItems(1)
                        ->reorderable(false)
                        ->collapsible()
                        // Номер позиции проставляем сами: он попадает в наряд и в
                        // печатные формы, и менеджер не должен вести его вручную.
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $livewire): array {
                            $data['position'] ??= 1;

                            return $data;
                        }),
                ]),

            Section::make('Финансы')
                // Цех не видит ни себестоимости, ни маржи — ни в списке, ни в карточке.
                ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false)
                ->columns(3)
                ->schema([
                    TextInput::make('total_price')
                        ->label('Сумма сделки')
                        ->numeric()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol'))
                        ->helperText('Пересчитывается из спецификации при сохранении.'),

                    TextInput::make('cost_price')
                        ->label('Себестоимость')
                        ->numeric()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    Placeholder::make('margin')
                        ->label('Маржа')
                        ->content(function (Get $get): string {
                            $total = (float) $get('total_price');
                            $cost = (float) $get('cost_price');

                            if ($total <= 0.0) {
                                return '—';
                            }

                            return number_format($total - $cost, 0, ',', ' ').' '.config('gravit.currency.symbol')
                                .' · '.round(($total - $cost) / $total * 100, 1).' %';
                        }),
                ]),
        ]);
    }

    /**
     * Состояние формы приходит по-разному: при создании это строка из default(),
     * при редактировании — enum из каста модели. Сравнение «в лоб» со строкой
     * молча скрывало секцию позиций на странице редактирования.
     */
    private static function pipelineOf(mixed $state): PipelineType
    {
        if ($state instanceof PipelineType) {
            return $state;
        }

        return PipelineType::tryFrom((string) ($state ?: '')) ?? PipelineType::Sales;
    }

    private static function isSalesPipeline(mixed $state): bool
    {
        return self::pipelineOf($state) === PipelineType::Sales;
    }
}

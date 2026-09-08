<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages\Schemas;

use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class FactoryStageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Этап')
                ->columns(2)
                ->schema([
                    Select::make('pipeline_type')
                        ->label('Воронка')
                        ->options(PipelineType::class)
                        ->default(PipelineType::Sales->value)
                        ->required()
                        ->native(false),

                    TextInput::make('name')
                        ->label('Название этапа')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, callable $get): void {
                            // Код нужен для ссылок и сидеров; заполняем сами,
                            // чтобы менеджер видел в форме только название.
                            if (blank($get('code')) && filled($state)) {
                                $set('code', Str::slug($state, '_'));
                            }
                        }),

                    TextInput::make('code')
                        ->label('Код')
                        ->helperText('Технический идентификатор. Уникален внутри воронки.')
                        ->required()
                        ->maxLength(64)
                        ->alphaDash(),

                    TextInput::make('order')
                        ->label('Порядок')
                        ->helperText('Меньше — левее на канбане. Порядок также меняется перетаскиванием в таблице.')
                        ->numeric()
                        ->default(fn () => 10)
                        ->required(),

                    Select::make('color')
                        ->label('Цвет колонки')
                        ->options([
                            'gray' => 'Серый',
                            'info' => 'Синий',
                            'primary' => 'Основной',
                            'success' => 'Зелёный',
                            'warning' => 'Оранжевый',
                            'danger' => 'Красный',
                        ])
                        ->default('gray')
                        ->native(false),

                    TextInput::make('icon')
                        ->label('Иконка')
                        ->placeholder('heroicon-o-cube')
                        ->helperText('Имя heroicon, необязательно.'),

                    Textarea::make('description')
                        ->label('Что происходит на этапе')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Нормативы и оплата')
                ->description('Заполняется для этапов цеха: норматив времени и сдельная расценка операции.')
                ->columns(2)
                ->schema([
                    TextInput::make('estimated_hours')
                        ->label('Норматив, часов')
                        ->numeric()
                        ->step(0.25)
                        ->default(0)
                        ->suffix('ч'),

                    TextInput::make('operation_cost')
                        ->label('Сдельная оплата за операцию')
                        ->numeric()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),
                ]),

            Section::make('Что обязательно заполнить')
                ->description('Без этих данных сделку на этап не пустят. Так менеджеры не «проскакивают» воронку с пустой карточкой.')
                ->schema([
                    CheckboxList::make('required_fields')
                        ->hiddenLabel()
                        ->options(StageRequirement::class)
                        ->columns(2)
                        ->bulkToggleable(),
                ]),

            Section::make('Автоматизация')
                ->description('Связка воронок «Продажи ⇄ Завод». Флаги можно переносить на другие этапы — код менять не нужно.')
                ->columns(2)
                ->schema([
                    Toggle::make('triggers_production')
                        ->label('Создаёт наряд на заводе')
                        ->helperText('Вход сделки на этот этап автоматически открывает производственный наряд и списывает материалы.'),

                    Toggle::make('completes_production')
                        ->label('Завершает производство')
                        ->helperText('Закрытие этапа переводит сделку продаж в «Готово к отгрузке».'),

                    Toggle::make('is_initial')->label('Первый этап воронки'),
                    Toggle::make('is_final')->label('Последний этап воронки'),
                    Toggle::make('is_active')->label('Активен')->default(true),
                ]),
        ]);
    }
}

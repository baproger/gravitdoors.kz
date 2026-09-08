<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Models\User;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Форма не должна пропускать мусор: кривой телефон, БИН не из 12 цифр и т.п. */
class DealValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_required_fields_are_enforced(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm(['title' => '', 'client_name' => '', 'client_phone' => ''])
            ->call('create')
            ->assertHasFormErrors(['title', 'client_name', 'client_phone']);
    }

    public function test_phone_must_be_a_kazakhstan_number(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm(['client_phone' => '12345'])
            ->call('create')
            ->assertHasFormErrors(['client_phone']);

        Livewire::test(CreateDeal::class)
            ->fillForm($this->validPayload())
            ->call('create')
            ->assertHasNoFormErrors(['client_phone']);
    }

    public function test_email_must_be_valid(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([...$this->validPayload(), 'client_email' => 'не-почта'])
            ->call('create')
            ->assertHasFormErrors(['client_email']);
    }

    public function test_company_requires_name_and_twelve_digit_bin(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([
                ...$this->validPayload(),
                'client_type' => ClientType::Company->value,
                'client_company' => '',
                'client_bin' => '123',
            ])
            ->call('create')
            ->assertHasFormErrors(['client_company', 'client_bin']);
    }

    public function test_door_dimensions_are_limited_to_what_the_workshop_makes(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([
                ...$this->validPayload(),
                'doorConfigurations' => [
                    ['category' => 'comfort', 'model' => 'lion', 'position' => 1, 'quantity' => 1,
                        'height' => 5000, 'width' => 100, 'opening_side' => 'right',
                        'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_prepayment_cannot_exceed_the_deal_total(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([...$this->validPayload(), 'total_price' => 100_000, 'prepayment' => 250_000])
            ->call('create')
            ->assertHasFormErrors(['prepayment']);
    }

    public function test_a_correct_deal_is_created(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm($this->validPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('deals', ['client_name' => 'Асхат Жумабеков']);
        $this->assertDatabaseHas('door_configurations', ['category' => 'premium', 'model' => 'lion']);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'title' => 'ЖК «Тест», кв. 5',
            'client_name' => 'Асхат Жумабеков',
            'client_phone' => '+7 (707) 111-22-33',
            'client_type' => ClientType::Individual->value,
            'doorConfigurations' => [
                [
                    'category' => 'premium',
                    'model' => 'lion',
                    'position' => 1,
                    'quantity' => 1,
                    'height' => 2050,
                    'width' => 950,
                    'opening_side' => 'right',
                    'metal_thickness' => 'metal_1_5',
                    'lock_system' => 'lock_kale',
                ],
            ],
        ];
    }
}

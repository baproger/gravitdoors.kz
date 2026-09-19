<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Сжатие подключено ко всем полям загрузки сразу: фото чека и аватар
 * приходят с телефона в 3000 px, а на диск ложатся не больше 1600 px.
 */
class UploadCompressionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
    }

    public function test_receipt_photo_is_compressed_when_payment_is_accepted(): void
    {
        $deal = $this->deal();
        $photo = UploadedFile::fake()->image('check.png', 3000, 2000);

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->callAction('pay', data: [
                'amount' => 300_000,
                'method' => PaymentMethod::Card->value,
                'paid_at' => now()->toDateString(),
                'receipt_path' => [$photo],
            ])
            ->assertHasNoErrors();

        $path = $deal->payments()->firstOrFail()->receipt_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringEndsWith('.jpg', $path, 'PNG без прозрачности становится JPEG');

        [$w, $h] = getimagesize(Storage::disk('local')->path($path));
        $this->assertSame(1600, $w);
        $this->assertSame(1067, $h);
    }

    public function test_avatar_is_compressed_too(): void
    {
        Livewire::actingAs($this->manager)
            ->test(EditProfile::class)
            ->fillForm(['avatar_path' => UploadedFile::fake()->image('me.jpg', 2400, 2400)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $this->manager->refresh()->avatar_path;
        $this->assertNotEmpty($path);

        [$w, $h] = getimagesize(Storage::disk('local')->path($path));
        $this->assertSame(1600, $w);
        $this->assertSame(1600, $h);
    }

    public function test_documents_that_cannot_be_compressed_are_stored_unchanged(): void
    {
        $deal = $this->deal();
        $docx = UploadedFile::fake()->createWithContent('contract.docx', str_repeat('PK docx ', 2000));

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['documents' => [$docx]])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $deal->refresh()->documents;
        $this->assertCount(1, $stored);
        $this->assertStringEndsWith('.docx', $stored[0]);
        $this->assertSame(strlen(str_repeat('PK docx ', 2000)), Storage::disk('local')->size($stored[0]));
    }

    private function deal(): Deal
    {
        return Deal::create([
            'title' => 'Сделка со сжатием',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 300_000,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $this->manager->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenderPlatform;
use App\Enums\TenderStatus;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tender> */
class TenderFactory extends Factory
{
    protected $model = Tender::class;

    public function definition(): array
    {
        return [
            'announcement_number' => fake()->numerify('1######-1'),
            'title' => 'Поставка металлических дверей',
            'platform' => TenderPlatform::Goszakup,
            'customer_name' => 'КГУ «Школа-гимназия № '.fake()->numberBetween(1, 200).'»',
            'customer_bin' => '150340012345',
            'contact_name' => fake()->name(),
            'contact_phone' => '+7 7'.fake()->numerify('## ### ## ##'),
            'city' => 'Алматы',
            'delivery_address' => 'ул. Абая, 10',
            'deadline_at' => now()->addDays(10)->setTime(10, 0),
            'status' => TenderStatus::Preparing,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\SmsSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsSetting>
 */
class SmsSettingFactory extends Factory
{
    /**
     * A provider set up and turned on, sending with a user name and password.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...SmsSetting::DEFAULTS,
            'enabled' => true,
            'provider_name' => 'جوال',
            'url' => 'https://sms.example.ps/api/send',
            'username_param' => 'user',
            'username' => 'af-power',
            'password_param' => 'pass',
            'password' => 'secret-password',
            'sender' => 'AF-Power',
        ];
    }

    /** Saved but turned off. */
    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}

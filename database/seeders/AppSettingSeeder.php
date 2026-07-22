<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AppSetting::firstOrCreate([], [
            'app_name' => 'Payment Gateway Bridge',
            'logo' => null,
            'favicon' => null,
            'primary_color' => '#1db954',
            'secondary_color' => '#535353',
        ]);
    }
}

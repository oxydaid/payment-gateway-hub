<?php

namespace App\Models;

use App\Traits\FileUploadHandler;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class AppSetting extends Model
{
    use FileUploadHandler;

    public array $fileFields = ['favicon', 'logo'];

    public array $fileDirectories = [
        'favicon' => 'app-settings',
        'logo' => 'app-settings',
    ];

    public static function getSettings()
    {
        return self::first() ?? self::create([]);
    }
}

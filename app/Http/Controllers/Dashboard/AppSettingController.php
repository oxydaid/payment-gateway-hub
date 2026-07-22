<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingController extends Controller
{
    public function index(): Response
    {
        $settings = AppSetting::getSettings();

        return Inertia::render('AppSettings/Index', [
            'settings' => [
                'app_name' => $settings->app_name,
                'logo_url' => $settings->logo ? asset('storage/'.$settings->logo) : null,
                'favicon_url' => $settings->favicon ? asset('storage/'.$settings->favicon) : null,
                'primary_color' => $settings->primary_color,
                'secondary_color' => $settings->secondary_color,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = AppSetting::getSettings();

        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:255'],
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'mimes:png,jpg,jpeg,gif,webp,svg,bmp,avif', 'max:2048'],
            'favicon' => ['nullable', 'mimes:png,jpg,jpeg,gif,webp,svg,bmp,avif', 'max:1024'],
        ]);

        // If files are null/not uploaded, remove them from the array to prevent overwriting with null
        $data = array_filter($validated, function ($val) {
            return $val !== null;
        });

        $settings->update($data);

        return Inertia::flash('success', 'Application settings updated successfully.')->back();
    }
}

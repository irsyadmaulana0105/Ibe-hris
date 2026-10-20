<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompanyTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ThemeController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'theme.manage', 'Theme access required.');
        $theme = CompanyTheme::query()->firstOrCreate(['company_id' => $request->user()->company_id]);
        return $this->success($theme);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'theme.manage', 'Theme access required.');

        $data = $request->validate([
            'primary_color' => ['sometimes', 'string', 'max:20'],
            'accent_color'  => ['sometimes', 'string', 'max:20'],
            'font_family'   => ['sometimes', 'string', 'max:100'],
            'radius'        => ['sometimes', 'integer', 'min:0', 'max:50'],
            'logo'          => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $theme = CompanyTheme::query()->firstOrCreate(['company_id' => $request->user()->company_id]);

        if ($request->hasFile('logo')) {
            if ($theme->logo_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', parse_url($theme->logo_url, PHP_URL_PATH) ?? ''));
            }
            $path = $request->file('logo')->store('theme', 'public');
            $data['logo_url'] = Storage::disk('public')->url($path);
        }
        unset($data['logo']);

        $theme->update($data);

        return $this->success($theme->fresh(), 'Tema disimpan.');
    }
}

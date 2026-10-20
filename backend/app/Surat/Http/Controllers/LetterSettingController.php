<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\LetterSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterSettingController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $this->requireSettingCapability($request, 'documents.settings.view', 'view');

        $s = LetterSetting::readForCompany($request->user()->company_id);

        return $this->success($s);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireSettingCapability($request, 'documents.settings.manage', 'update');

        $s = LetterSetting::forCompany($request->user()->company_id);
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:200'],
            'brand_name' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'string', 'max:150'],
            'kop_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);
        if ($request->hasFile('logo_image')) {
            $data['logo_path'] = $request->file('logo_image')->store('letters/logo', 'public');
            \App\Helpers::shrinkStoredImage('public', $data['logo_path']);
        }
        unset($data['logo_image']);
        $s->update($data);

        return $this->success($s->fresh());
    }

    private function requireSettingCapability(Request $request, string $capability, string $action): void
    {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter setting capability required.',
            [
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_setting',
                'action' => $action,
            ],
        );
    }
}

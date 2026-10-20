<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\R2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandingController extends ApiController
{
    public function __construct(private readonly R2Service $r2) {}

    public function default(): JsonResponse
    {
        return $this->success(config('branding.default'));
    }

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 404, 'Company not found.');

        return $this->success($company->brandingArray());
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $company = $request->user()->company;
        abort_unless($company, 404, 'Company not found.');

        $validated = $request->validate([
            'short_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand_color_primary' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_color_accent' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $company->update($validated);

        return $this->success($company->fresh()->brandingArray(), 'Branding updated.');
    }

    public function logo(Request $request): JsonResponse
    {
        return $this->upload($request, 'logo_url', 'logo');
    }

    public function logoDark(Request $request): JsonResponse
    {
        return $this->upload($request, 'logo_dark_url', 'logo-dark');
    }

    public function favicon(Request $request): JsonResponse
    {
        return $this->upload($request, 'favicon_url', 'favicon');
    }

    private function upload(Request $request, string $field, string $prefix): JsonResponse
    {
        $this->requireAdmin($request->user());
        $company = $request->user()->company;
        abort_unless($company, 404, 'Company not found.');

        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
        $file = $validated['image'];
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $key = "branding/{$company->id}/{$prefix}-".now()->format('YmdHisv').".{$extension}";
        $url = $this->r2->putFile($key, $file);

        $company->update([$field => $url]);

        return $this->success($company->fresh()->brandingArray(), 'Branding image uploaded.');
    }
}

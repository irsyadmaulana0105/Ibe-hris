<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\PrivateAssetStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyController extends ApiController
{
    private const PRIVATE_ASSETS = [
        'director-signature' => ['path' => 'director_signature_path', 'checksum' => 'director_signature_sha256', 'upload' => 'director_signature'],
        'hrd-signature' => ['path' => 'hrd_signature_path', 'checksum' => 'hrd_signature_sha256', 'upload' => 'hrd_signature'],
        'finance-signature' => ['path' => 'finance_signature_path', 'checksum' => 'finance_signature_sha256', 'upload' => 'finance_signature'],
        'stamp' => ['path' => 'stamp_path', 'checksum' => 'stamp_sha256', 'upload' => 'stamp'],
    ];

    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    /**
     * GET /api/v1/admin/company
     */
    public function show(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'company.manage', 'Company management access required.');

        $company = Company::findOrFail($request->user()->company_id);

        return $this->success($this->format($company));
    }

    /**
     * POST /api/v1/admin/company
     * Update company details + optional logo upload.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'company.manage', 'Company management access required.');

        $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'regency' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'finance_email' => ['nullable', 'email', 'max:150'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'nib' => ['nullable', 'string', 'max:20'],
            'director_name' => ['nullable', 'string', 'max:150'],
            'website' => ['nullable', 'string', 'max:200'],
            'letterhead_style' => ['nullable', 'in:formal,modern'],
            'contract_watermark_text' => ['nullable', 'string', 'max:40'],
            'contract_watermark_color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contract_watermark_opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            // Same-origin public SVG is active content. Branding logos are raster-only.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'director_signature' => ['nullable', 'file', 'mimetypes:image/png', 'max:2048'],
            'hrd_signature' => ['nullable', 'file', 'mimetypes:image/png', 'max:2048'],
            'finance_signature' => ['nullable', 'file', 'mimetypes:image/png', 'max:2048'],
            'stamp' => ['nullable', 'file', 'mimetypes:image/png', 'max:2048'],
        ]);

        $company = Company::findOrFail($user->company_id);
        $before = $company->only(['name', 'address', 'city', 'province', 'regency', 'district', 'village', 'postal_code', 'phone', 'email', 'finance_email', 'npwp', 'nib', 'logo']);

        $data = $request->only(['name', 'address', 'city', 'province', 'regency', 'district', 'village', 'postal_code', 'phone', 'email', 'finance_email', 'npwp', 'nib', 'director_name', 'website', 'letterhead_style', 'contract_watermark_text', 'contract_watermark_color', 'contract_watermark_opacity']);

        $newPrivatePaths = [];
        $oldPrivatePaths = [];
        $oldLogo = $company->logo;
        try {
            if ($request->hasFile('logo')) {
                $data['logo'] = $request->file('logo')->store('logos', 'public');
                \App\Helpers::shrinkStoredImage('public', $data['logo']);
            }

            foreach (self::PRIVATE_ASSETS as $asset => $definition) {
                $formField = $definition['upload'];
                if (! $request->hasFile($formField)) {
                    continue;
                }

                $stored = $this->privateAssets->storeUploadedPng(
                    (int) $company->id,
                    'company-signatures',
                    (string) $company->id,
                    $asset,
                    $request->file($formField),
                );
                $data[$definition['path']] = $stored['path'];
                $data[$definition['checksum']] = $stored['sha256'];
                $newPrivatePaths[] = $stored['path'];

                if ($this->privateAssets->isPrivatePathFor(
                    (int) $company->id,
                    'company-signatures',
                    (string) $company->id,
                    $company->{$definition['path']},
                )) {
                    $oldPrivatePaths[] = $company->{$definition['path']};
                }
            }

            DB::transaction(fn () => $company->update(array_filter($data, fn ($v) => ! is_null($v))));
        } catch (PrivateAssetIntegrityException $e) {
            foreach ($newPrivatePaths as $path) {
                $this->privateAssets->deletePrivate((int) $company->id, 'company-signatures', (string) $company->id, $path);
            }
            abort($e->httpStatus, $e->getMessage());
        } catch (\Throwable $e) {
            foreach ($newPrivatePaths as $path) {
                $this->privateAssets->deletePrivate((int) $company->id, 'company-signatures', (string) $company->id, $path);
            }
            throw $e;
        }

        foreach (array_unique($oldPrivatePaths) as $path) {
            $this->privateAssets->deletePrivate((int) $company->id, 'company-signatures', (string) $company->id, $path);
        }
        if (isset($data['logo']) && $oldLogo && $oldLogo !== $data['logo']) {
            Storage::disk('public')->delete($oldLogo);
        }

        app(AuditLogService::class)->log(
            'company.updated',
            $user,
            'company',
            $company->id,
            [
                'before' => $before,
                'after' => $company->fresh()->only(['name', 'address', 'city', 'phone', 'email', 'finance_email', 'logo']),
            ],
            $request
        );

        return $this->success($this->format($company->fresh()), 'Data perusahaan berhasil diperbarui.');
    }

    public function asset(Request $request, string $asset): Response
    {
        $user = $request->user();
        $this->requireCapability($user, 'company.manage', 'Company management access required.');
        $definition = self::PRIVATE_ASSETS[$asset] ?? null;
        abort_if($definition === null, 404);

        $company = Company::query()->findOrFail($user->company_id);
        $path = $company->{$definition['path']};
        abort_if(! is_string($path) || $path === '', 404);

        return $this->privateAssets->downloadResponse(
            (int) $company->id,
            'company-signatures',
            (string) $company->id,
            $path,
            $company->{$definition['checksum']},
            'png',
            $asset.'.png',
        );
    }

    private function format(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'city' => $c->city,
            'province' => $c->province,
            'regency' => $c->regency,
            'district' => $c->district,
            'village' => $c->village,
            'postal_code' => $c->postal_code,
            'phone' => $c->phone,
            'email' => $c->email,
            'finance_email' => $c->finance_email,
            'npwp' => $c->npwp,
            'nib' => $c->nib,
            'logo_url' => $c->logo ? Storage::disk('public')->url($c->logo) : null,
            'website' => $c->website,
            'letterhead_style' => $c->letterhead_style ?? 'modern',
            'director_name' => $c->director_name,
            'director_signature_available' => $this->assetAvailable($c, 'director-signature'),
            'hrd_signature_available' => $this->assetAvailable($c, 'hrd-signature'),
            'finance_signature_available' => $this->assetAvailable($c, 'finance-signature'),
            'stamp_available' => $this->assetAvailable($c, 'stamp'),
            'is_active' => $c->is_active,
        ];
    }

    private function assetAvailable(Company $company, string $asset): bool
    {
        $definition = self::PRIVATE_ASSETS[$asset];

        return $this->privateAssets->isPrivatePathFor(
            (int) $company->id,
            'company-signatures',
            (string) $company->id,
            $company->{$definition['path']},
        ) && is_string($company->{$definition['checksum']});
    }
}

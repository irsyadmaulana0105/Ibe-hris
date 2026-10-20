<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\LetterSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class LetterSignatureController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireSignatureCapability($request, 'documents.signature.view', 'view');

        $rows = LetterSignature::query()
            ->where('company_id', $request->user()->company_id)
            ->when(
                (string) config('enterprise_rbac.mode', 'shadow') !== 'enforce',
                fn ($query) => $query->where('is_active', true),
            )
            ->orderByDesc('is_company_default')
            ->orderBy('name')
            ->get();

        return $this->success($rows->map(fn (LetterSignature $signature): array => $this->signaturePayload($signature)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireSignatureCapability($request, 'documents.signature.prepare', 'prepare');

        $data = $request->validate([
            'user_id' => [
                'nullable',
                'string',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'name' => ['required', 'string', 'max:150'],
            'title' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'default_for_categories' => ['nullable', 'array'],
            'default_for_categories.*' => [
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'is_company_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'signature_image' => ['nullable', 'image', 'mimes:png', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096'],
            'stamp_image' => ['nullable', 'image', 'mimes:png', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096'],
        ]);

        $enforcing = (string) config('enterprise_rbac.mode', 'shadow') === 'enforce';
        if ($enforcing && ($request->boolean('is_active') || $request->boolean('is_company_default'))) {
            abort(403, 'Signature preparation cannot activate or select a company default.');
        }

        $storedPaths = [];
        if ($request->hasFile('signature_image')) {
            $data['signature_path'] = $this->storePrivateAsset($request, 'signature_image');
            $storedPaths[] = $data['signature_path'];
        }
        if ($request->hasFile('stamp_image')) {
            $data['stamp_path'] = $this->storePrivateAsset($request, 'stamp_image');
            $storedPaths[] = $data['stamp_path'];
        }
        unset($data['signature_image'], $data['stamp_image']);

        // FIX: FormData kirim boolean sebagai string "true"/"false" - PHP cast
        // non-empty string ke true. Pakai $request->boolean() yang proper
        // normalize ("0", "false", "no", "off" → false).
        $isDefault = ! $enforcing && $request->boolean('is_company_default');
        $data['is_company_default'] = $isDefault;
        $data['is_active'] = ! $enforcing;

        try {
            $sig = DB::transaction(function () use ($data, $enforcing, $isDefault, $request): LetterSignature {
                if ($isDefault) {
                    LetterSignature::where('company_id', $request->user()->company_id)
                        ->update(['is_company_default' => false]);
                }

                return LetterSignature::create(array_merge($data, [
                    'company_id' => $request->user()->company_id,
                    'prepared_by_id' => $request->user()->id,
                    'approved_by_id' => $enforcing ? null : $request->user()->id,
                    'approved_at' => $enforcing ? null : now(),
                ]));
            });
        } catch (Throwable $exception) {
            $this->deletePrivateAssets($storedPaths);

            throw $exception;
        }

        return $this->created($this->signaturePayload($sig));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireSignatureCapability($request, 'documents.signature.prepare', 'prepare_update', $id);

        $sig = LetterSignature::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $enforcing = (string) config('enterprise_rbac.mode', 'shadow') === 'enforce';
        if ($enforcing && ($sig->is_active || $sig->approved_at !== null)) {
            abort(422, 'Approved signatures are immutable. Deactivate and prepare a replacement.');
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'title' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'default_for_categories' => ['nullable', 'array'],
            'default_for_categories.*' => [
                'integer',
                Rule::exists('letter_categories', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'is_company_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'signature_image' => ['nullable', 'image', 'mimes:png', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096'],
            'stamp_image' => ['nullable', 'image', 'mimes:png', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096'],
        ]);
        if ($enforcing && ($request->boolean('is_company_default') || $request->boolean('is_active'))) {
            abort(403, 'Signature preparation cannot activate or select a company default.');
        }
        $storedPaths = [];
        $replacedPaths = [];
        if ($request->hasFile('signature_image')) {
            $data['signature_path'] = $this->storePrivateAsset($request, 'signature_image');
            $storedPaths[] = $data['signature_path'];
            $replacedPaths[] = $sig->signature_path;
        }
        if ($request->hasFile('stamp_image')) {
            $data['stamp_path'] = $this->storePrivateAsset($request, 'stamp_image');
            $storedPaths[] = $data['stamp_path'];
            $replacedPaths[] = $sig->stamp_path;
        }
        unset($data['signature_image'], $data['stamp_image']);

        // FIX: normalize FormData boolean strings (same bug as store())
        if ($request->has('is_company_default')) {
            $data['is_company_default'] = $request->boolean('is_company_default');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($enforcing) {
            $data['is_company_default'] = false;
            $data['is_active'] = false;
            $data['prepared_by_id'] = $request->user()->id;
            $data['approved_by_id'] = null;
            $data['approved_at'] = null;
        }

        try {
            DB::transaction(function () use ($data, $request, $sig): void {
                if (($data['is_company_default'] ?? false) === true) {
                    LetterSignature::where('company_id', $request->user()->company_id)
                        ->where('id', '!=', $sig->id)
                        ->update(['is_company_default' => false]);
                }
                $sig->update($data);
            });
        } catch (Throwable $exception) {
            $this->deletePrivateAssets($storedPaths);

            throw $exception;
        }
        $this->deletePrivateAssets($replacedPaths);

        return $this->success($this->signaturePayload($sig->fresh()));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $sig = LetterSignature::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();

        $this->requireSignatureCapability(
            $request,
            'documents.signature.approve',
            'approve_activate',
            $id,
            [
                'maker_id' => $sig->prepared_by_id,
                'irreversible' => true,
            ],
        );

        $request->validate([
            'is_company_default' => ['nullable', 'boolean'],
        ]);
        $isDefault = $request->boolean('is_company_default');

        return DB::transaction(function () use ($isDefault, $request, $sig): JsonResponse {
            if ($isDefault) {
                LetterSignature::where('company_id', $request->user()->company_id)
                    ->where('id', '!=', $sig->id)
                    ->update(['is_company_default' => false]);
            }

            $sig->update([
                'is_active' => true,
                'is_company_default' => $isDefault,
                'approved_by_id' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $this->success(
                $this->signaturePayload($sig->fresh()),
                'Signature approved and activated.',
            );
        });
    }

    public function asset(Request $request, int $id, string $asset)
    {
        abort_unless(in_array($asset, ['signature', 'stamp'], true), 404);
        $this->requireSignatureCapability($request, 'documents.signature.view', 'view_asset', $id, [
            'asset' => $asset,
        ]);

        $sig = LetterSignature::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $path = $asset === 'signature' ? $sig->signature_path : $sig->stamp_path;
        $path = $this->validatedPrivatePath($sig, $path);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404, 'Aset tanda tangan tidak ditemukan.');

        return $disk->response($path, "{$asset}-{$sig->id}.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireSignatureCapability($request, 'documents.signature.approve', 'deactivate', $id);

        $sig = LetterSignature::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $sig->update(['is_active' => false, 'is_company_default' => false]);

        return $this->success(null, 'Signature deactivated');
    }

    /** @return array<string, mixed> */
    private function signaturePayload(LetterSignature $signature): array
    {
        return [
            'id' => $signature->id,
            'user_id' => $signature->user_id,
            'name' => $signature->name,
            'title' => $signature->title,
            'email' => $signature->email,
            'default_for_categories' => $signature->default_for_categories ?? [],
            'is_active' => (bool) $signature->is_active,
            'is_company_default' => (bool) $signature->is_company_default,
            'prepared_by_id' => $signature->prepared_by_id,
            'approved_by_id' => $signature->approved_by_id,
            'approved_at' => optional($signature->approved_at)->toIso8601String(),
            'signature_available' => filled($signature->signature_path),
            'stamp_available' => filled($signature->stamp_path),
            'created_at' => optional($signature->created_at)->toIso8601String(),
            'updated_at' => optional($signature->updated_at)->toIso8601String(),
        ];
    }

    private function storePrivateAsset(Request $request, string $field): string
    {
        $file = $request->file($field);
        $checksum = hash_file('sha256', $file->getRealPath());
        if (! is_string($checksum) || ! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            abort(500, 'Checksum aset tanda tangan gagal dibuat.');
        }
        $directory = 'private/letters/signatures/'.(int) $request->user()->company_id;
        $filename = Str::ulid().'-'.$checksum.'.png';
        $path = $file->storeAs($directory, $filename, 'local');
        if (! is_string($path) || $path === '') {
            abort(500, 'Aset tanda tangan gagal disimpan ke penyimpanan privat.');
        }

        return $path;
    }

    /** @param array<int, string|null> $paths */
    private function deletePrivateAssets(array $paths): void
    {
        $disk = Storage::disk('local');
        foreach (array_filter($paths) as $path) {
            $normalized = ltrim((string) $path, '/');
            if (str_starts_with($normalized, 'private/letters/signatures/')
                && ! preg_match('#(?:\.\.|\\\\|\x00)#', $normalized)) {
                $disk->delete($normalized);
            }
        }
    }

    private function validatedPrivatePath(LetterSignature $signature, ?string $path): string
    {
        $normalized = ltrim((string) $path, '/');
        $expectedPrefix = 'private/letters/signatures/'.(int) $signature->company_id.'/';
        abort_unless(
            $normalized !== ''
                && str_starts_with($normalized, $expectedPrefix)
                && ! preg_match('#(?:\.\.|\\\\|\x00)#', $normalized),
            409,
            'Lokasi aset tanda tangan bukan penyimpanan privat tenant ini.',
        );

        return $normalized;
    }

    private function requireSignatureCapability(
        Request $request,
        string $capability,
        string $action,
        ?int $resourceId = null,
        array $extraContext = [],
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter signature capability required.',
            array_merge(array_filter([
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_signature',
                'resource_id' => $resourceId,
                'action' => $action,
            ], static fn (mixed $value): bool => $value !== null), $extraContext),
        );
    }
}

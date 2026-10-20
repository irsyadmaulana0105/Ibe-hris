<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekening koran yang diunggah bagian keuangan.
 *
 * Berkas disimpan di disk "local" (storage/app), BUKAN "public". Dokumen ini
 * memuat seluruh mutasi rekening perusahaan; menaruhnya di disk publik membuat
 * siapa pun yang menebak nama berkas bisa mengunduhnya tanpa login.
 */
class BankStatementController extends ApiController
{
    private const DIR = 'bank-statements';

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance access required.');

        $rows = BankStatement::query()
            ->with('uploader:id,name')
            ->orderByDesc('period_from')
            ->orderByDesc('id')
            ->paginate(min(100, max(10, (int) $request->integer('per_page', 20))));

        return $this->paginated($rows, $rows->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'payroll.manage', 'Finance access required.', [
            'company_id' => $user->company_id,
            'resource_type' => 'bank_statement',
            'action' => 'upload',
        ]);

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Hasil cetak rekening koran umumnya PDF; gambar hasil scan juga
            // diterima. Maksimal 20 MB supaya cetakan setahun tetap muat.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ]);

        $file = $request->file('file');
        $path = $file->store(self::DIR, 'local');

        if (! is_string($path) || $path === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'Berkas gagal disimpan di server.',
            ]);
        }

        $row = BankStatement::create([
            'company_id' => $user->company_id,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'] ?? null,
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'notes' => $data['notes'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        app(\App\Services\AuditLogService::class)->log(
            'bank_statement.uploaded', $user, 'bank_statement', $row->id,
            ['bank_name' => $row->bank_name, 'period' => $row->period_from . ' s/d ' . $row->period_to, 'size' => $row->size]
        );

        return $this->success($row->fresh('uploader'), 'Rekening koran diunggah.');
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance access required.');

        $row = BankStatement::query()->findOrFail($id);

        abort_unless(Storage::disk('local')->exists($row->file_path), 404, 'Berkas tidak ditemukan.');

        return Storage::disk('local')->download($row->file_path, $row->original_name);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = BankStatement::query()->findOrFail($id);

        $this->requireCapability($user, 'payroll.manage', 'Finance access required.', [
            'company_id' => $row->company_id,
            'resource_type' => 'bank_statement',
            'resource_id' => $row->id,
            'irreversible' => true,
            'action' => 'delete',
        ]);

        $snapshot = [
            'bank_name' => $row->bank_name,
            'account_number' => $row->account_number,
            'period' => $row->period_from . ' s/d ' . $row->period_to,
            'original_name' => $row->original_name,
        ];

        Storage::disk('local')->delete($row->file_path);
        $row->delete();

        app(\App\Services\AuditLogService::class)->log(
            'bank_statement.deleted', $user, 'bank_statement', $id, $snapshot
        );

        return $this->success(message: 'Rekening koran dihapus.');
    }
}

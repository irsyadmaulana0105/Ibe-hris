<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Campaign;
use App\Marketing\Models\CampaignRecipient;
use App\Marketing\Services\KampanyeService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kampanye email CRM. Lihat: crm.view. Buat, ubah, mulai, jeda: crm.manage.
 * Pengiriman sebenarnya dilakukan penjadwal (crm:kampanye-kirim), bukan
 * permintaan HTTP, supaya batas harian dan jeda antarkiriman selalu berlaku.
 */
class CampaignController extends ApiController
{
    public function __construct(
        private readonly KampanyeService $kampanye,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $rows = Campaign::query()
            ->where('company_id', $user->company_id)
            ->withCount([
                'recipients as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'recipients as pending_count' => fn ($q) => $q->whereIn('status', ['pending', 'processing']),
                'recipients as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->orderByDesc('id')
            ->paginate(30);

        return $this->paginated($rows, $rows->items(), ['quota' => $this->kuota()]);
    }

    public function quota(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'crm.view');

        return $this->success($this->kuota());
    }

    /** POST /crm/campaigns/audience-preview - jumlah & contoh penerima untuk filter yang dipilih. */
    public function audiencePreview(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $audience = $this->validateAudience($request);
        $calon = $this->kampanye->calonPenerima($user->company_id, $audience);

        return $this->success([
            'count' => $calon->count(),
            'by_source' => $calon->countBy('source_type'),
            'sample' => $calon->take(20)->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $c = $this->temukan($user, $id);

        return $this->success($c->toArray() + ['stats' => $this->kampanye->statistik($c)]);
    }

    /** GET /crm/campaigns/{id}/render - pratinjau HTML dengan contoh nama. */
    public function render(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $c = $this->temukan($user, $id);
        $isi = $this->kampanye->render($c, 'Budi Santoso', 'PT Contoh Industri', $this->kampanye->urlBerhenti(KampanyeService::TOKEN_CONTOH));

        return $this->success($isi + ['from_name' => $this->kampanye->namaPengirim($c), 'from_email' => config('crm.kampanye.pengirim')]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $this->validateData($request);
        $data['company_id'] = $user->company_id;
        $data['created_by'] = $user->id;
        $data['status'] = 'draft';

        $c = Campaign::create($data);
        $this->audit->log('crm.campaign.created', $user, 'crm_campaign', (string) $c->id, ['name' => $c->name]);

        return $this->created($c, "Kampanye '{$c->name}' disimpan sebagai draf.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $c = $this->temukan($user, $id);
        if ($c->status !== 'draft') {
            $this->tolak('Kampanye yang sudah dimulai tidak bisa diubah. Buat kampanye baru.');
        }
        $c->update($this->validateData($request, true));

        return $this->success($c->fresh(), 'Kampanye diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $c = $this->temukan($user, $id);
        if ($c->status === 'sending') {
            $this->tolak('Jeda kampanye dulu sebelum menghapusnya.');
        }
        $c->delete();
        $this->audit->log('crm.campaign.deleted', $user, 'crm_campaign', (string) $c->id, ['name' => $c->name]);

        return $this->success(message: 'Kampanye dihapus.');
    }

    /** POST /crm/campaigns/{id}/test - kirim uji ke satu alamat (default: email pengguna). */
    public function test(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $c = $this->temukan($user, $id);
        $data = $request->validate(['email' => ['nullable', 'email:rfc', 'max:190']]);
        $ke = $data['email'] ?? $user->email;

        $kunci = 'crm-kampanye-uji:'.$user->company_id;
        $batas = (int) config('crm.kampanye.batas_uji_harian');
        if (RateLimiter::tooManyAttempts($kunci, $batas)) {
            $this->tolak("Batas kirim uji ({$batas} per hari) sudah tercapai.");
        }
        RateLimiter::hit($kunci, (int) now('Asia/Jakarta')->diffInSeconds(now('Asia/Jakarta')->endOfDay()) + 1);

        try {
            $this->kampanye->kirimUji($c, $ke, $user->name);
        } catch (\Throwable $e) {
            report($e);
            $this->tolak('Kirim uji gagal: '.$e->getMessage());
        }

        return $this->success(['email' => $ke], "Email uji dikirim ke {$ke}.");
    }

    /** POST /crm/campaigns/{id}/start - bekukan daftar penerima lalu masuk antrean penjadwal. */
    public function start(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $c = $this->temukan($user, $id);
        if (! in_array($c->status, ['draft', 'paused'], true)) {
            $this->tolak('Kampanye ini tidak bisa dimulai dari status '.$c->status.'.');
        }

        if ($c->status === 'draft') {
            $jumlah = $this->kampanye->siapkanPenerima($c);
            if ($jumlah === 0) {
                $this->tolak('Tidak ada penerima yang cocok dengan filter (atau semuanya sudah berhenti berlangganan).');
            }
            $c->started_at = now();
        }
        $c->status = 'sending';
        $c->save();
        $this->audit->log('crm.campaign.started', $user, 'crm_campaign', (string) $c->id, [
            'name' => $c->name, 'recipients' => $c->recipient_count,
        ]);

        $kuota = $this->kuota();

        return $this->success($c->fresh()->toArray() + ['stats' => $this->kampanye->statistik($c)],
            "Kampanye berjalan: {$c->recipient_count} penerima. Dikirim bertahap, maksimal {$kuota['limit']} email per hari pada jam kerja.");
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $c = $this->temukan($user, $id);
        if ($c->status !== 'sending') {
            $this->tolak('Hanya kampanye yang sedang berjalan yang bisa dijeda.');
        }
        $c->update(['status' => 'paused']);
        $this->audit->log('crm.campaign.paused', $user, 'crm_campaign', (string) $c->id, ['name' => $c->name]);

        return $this->success($c->fresh(), 'Kampanye dijeda.');
    }

    public function recipients(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $c = $this->temukan($user, $id);
        $rows = CampaignRecipient::where('campaign_id', $c->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    // ── helper ────────────────────────────────────────────────────────────

    private function kuota(): array
    {
        return [
            'limit' => (int) config('crm.kampanye.batas_harian'),
            'sent_today' => $this->kampanye->terkirimHariIni(),
            'remaining' => $this->kampanye->sisaKuotaHariIni(),
            'in_window' => $this->kampanye->dalamJendelaKirim(),
            'window' => sprintf('%02d.00-%02d.00 WIB%s',
                config('crm.kampanye.jam_mulai'), config('crm.kampanye.jam_selesai'),
                config('crm.kampanye.hari_kerja_saja') ? ', Senin-Jumat' : ''),
            'sender' => config('crm.kampanye.pengirim'),
        ];
    }

    private function temukan($user, int $id): Campaign
    {
        return Campaign::query()->where('company_id', $user->company_id)->findOrFail($id);
    }

    private function tolak(string $pesan): never
    {
        throw ValidationException::withMessages(['campaign' => $pesan]);
    }

    private function validateAudience(Request $request, string $awalan = ''): array
    {
        $p = $awalan ? "{$awalan}." : '';
        $v = $request->validate([
            "{$p}sources" => ['nullable', 'array'],
            "{$p}sources.*" => [Rule::in(['contacts', 'leads', 'companies'])],
            "{$p}city" => ['nullable', 'string', 'max:100'],
            "{$p}lead_statuses" => ['nullable', 'array'],
            "{$p}lead_statuses.*" => [Rule::in(['new', 'contacted', 'qualified', 'disqualified'])],
            "{$p}manual" => ['nullable', 'string', 'max:100000'],
        ]);

        return $awalan ? ($v[$awalan] ?? []) : $v;
    }

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$rule, 'string', 'max:150'],
            'subject' => [$rule, 'string', 'max:200'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'body' => [$rule, 'string', 'max:20000'],
            'audience' => ['nullable', 'array'],
        ]);
        if ($request->has('audience')) {
            $data['audience'] = $this->validateAudience($request, 'audience');
        }

        return $data;
    }
}

<?php

namespace App\Marketing\Services;

use App\Keuangan\Models\Customer;
use App\Keuangan\Models\Invoice;
use App\Marketing\Models\Activity;
use App\Marketing\Models\Company as CrmCompany;
use App\Marketing\Models\Contact;
use App\Marketing\Models\Deal;
use App\Marketing\Models\Stage;
use App\Marketing\Support\PenggunaAktif;
use App\Models\AppNotification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\EnterpriseAuthorizationService;
use App\Services\NotificationDispatcher;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales pipeline core logic. Stage move adalah satu-satunya cara legit
 * untuk ubah `stage_id` + `status` - supaya hook closed-won auto-trigger
 * Finance integration konsisten.
 */
class DealService
{
    public function listForUser(User $user, array $filters = []): Builder
    {
        $q = Deal::query()->where('company_id', $user->company_id);

        if ($pipelineId = $filters['pipeline_id'] ?? null) {
            $q->where('pipeline_id', $pipelineId);
        }
        if ($stageId = $filters['stage_id'] ?? null) {
            $q->where('stage_id', $stageId);
        }
        if ($status = $filters['status'] ?? null) {
            $q->where('status', $status);
        }
        if ($ownerId = $filters['owner_id'] ?? null) {
            $q->where('owner_id', $ownerId);
        }
        if ($search = $filters['q'] ?? null) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $q;
    }

    public function create(User $user, array $data): Deal
    {
        return DB::transaction(function () use ($user, $data) {
            // Auto-assign first open stage kalau gak ada
            if (empty($data['stage_id'])) {
                $stage = Stage::query()
                    ->where('company_id', $user->company_id)
                    ->where('pipeline_id', $data['pipeline_id'])
                    ->where('type', 'open')
                    ->orderBy('sort_order')
                    ->first();
                if ($stage) {
                    $data['stage_id'] = $stage->id;
                    $data['probability'] = $data['probability'] ?? $stage->default_probability;
                }
            }

            $data['company_id'] = $user->company_id;
            $data['code'] = $this->generateCode((int) $user->company_id);
            $data['owner_id'] = $data['owner_id'] ?? $user->id;

            return Deal::create($data);
        });
    }

    /**
     * Pindahin deal ke stage lain. Kalau target stage type='won' atau 'lost',
     * status deal auto-set sesuai + closed_at di-stamp + Finance hook firing
     * (untuk 'won' aja).
     */
    public function changeStage(Deal $deal, Stage $targetStage, User $actor, ?string $lostReason = null): Deal
    {
        return DB::transaction(function () use ($deal, $targetStage, $actor, $lostReason) {
            $fromStage = $deal->stage_id ? Stage::query()->find($deal->stage_id) : null;
            $deal->stage_id = $targetStage->id;
            $deal->probability = $targetStage->default_probability;

            if ($targetStage->type === 'won') {
                $deal->status = 'won';
                $deal->closed_at = Carbon::today();
                $deal->lost_reason = null;
            } elseif ($targetStage->type === 'lost') {
                $deal->status = 'lost';
                $deal->closed_at = Carbon::today();
                $deal->lost_reason = $lostReason;
            } else {
                // Kembali ke stage open - reset closed flags.
                // fin_invoice_id SENGAJA tidak dikosongkan. Dulu dikosongkan,
                // padahal draft invoice-nya tetap ada di Keuangan -> kalau deal
                // dimenangkan lagi, onClosedWon() membuat invoice DAN pelanggan
                // kedua. Dengan tautan dipertahankan, menang ulang memakai
                // invoice yang sama.
                $deal->status = 'open';
                $deal->closed_at = null;
                $deal->lost_reason = null;
            }
            $deal->save();

            // Jejak perpindahan tahap di Activity Timeline. Dulu hanya masuk
            // audit log teknis, jadi tim sales tidak bisa melihat siapa
            // memindahkan deal dan kapan.
            if ($fromStage?->id !== $targetStage->id) {
                Activity::create([
                    'company_id' => $deal->company_id,
                    'type' => 'note',
                    'subject' => 'Tahap: '.($fromStage?->name ?? '-').' → '.$targetStage->name,
                    'body' => $targetStage->type === 'lost' && $lostReason ? "Alasan kalah: {$lostReason}" : null,
                    'subjectable_type' => 'deal',
                    'subjectable_id' => $deal->id,
                    'user_id' => $actor->id,
                    'happened_at' => now(),
                ]);
            }

            // Hook closed-won: proyek selalu dibuat; invoice hanya kalau aktor
            // berhak (lihat onClosedWon).
            if ($targetStage->type === 'won' && ! $deal->fin_invoice_id) {
                $this->onClosedWon($deal, $actor);
            }

            // Relasi dimuat supaya respons menyebut nama tahap tujuan
            // (UI dulu terpaksa menulis "tahap baru").
            return $deal->fresh(['stage', 'crmCompany', 'crmContact']);
        });
    }

    /**
     * Closed-won automation.
     *
     * Proyek delivery selalu dibuat. Pelanggan + draft invoice Keuangan HANYA
     * dibuat kalau aktor berhak mengelola invoice. Dulu selalu dibuat, sehingga
     * tim Marketing menerbitkan data Keuangan yang tidak boleh mereka lihat
     * (audit 30 Sep 2026: Marketing 403 di Master Pelanggan & Invoice). Kini
     * tim Keuangan diberi notifikasi dan menerbitkan invoice sendiri dari
     * halaman deal (issueInvoice) - pemisahan tugas sales vs keuangan.
     */
    private function onClosedWon(Deal $deal, User $actor): void
    {
        $customer = null;
        if ($this->canIssueInvoice($actor)) {
            $customer = $this->createDraftInvoice($deal, $actor);
        } else {
            $this->notifyFinance($deal, $actor);
        }

        $this->createDeliveryProject($deal, $actor, $customer?->name ?? $this->customerLabel($deal));
    }

    /**
     * Terbitkan draft invoice untuk deal yang sudah won tapi belum punya
     * invoice. Dipanggil tim Keuangan dari halaman deal.
     */
    public function issueInvoice(Deal $deal, User $actor): Deal
    {
        return DB::transaction(function () use ($deal, $actor) {
            // Kunci baris supaya dua klik cepat tidak membuat dua invoice.
            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if ($deal->status !== 'won') {
                throw ValidationException::withMessages([
                    'status' => 'Invoice hanya bisa diterbitkan untuk deal yang sudah Closed Won.',
                ]);
            }
            if ($deal->fin_invoice_id) {
                throw ValidationException::withMessages([
                    'fin_invoice_id' => 'Deal ini sudah memiliki invoice.',
                ]);
            }

            $this->createDraftInvoice($deal, $actor);

            Activity::create([
                'company_id' => $deal->company_id,
                'type' => 'note',
                'subject' => 'Draft invoice diterbitkan oleh Keuangan',
                'subjectable_type' => 'deal',
                'subjectable_id' => $deal->id,
                'user_id' => $actor->id,
                'happened_at' => now(),
            ]);

            return $deal->fresh(['stage', 'crmCompany', 'crmContact']);
        });
    }

    public function canIssueInvoice(User $user): bool
    {
        $authz = app(EnterpriseAuthorizationService::class);

        return $authz->allows($user, 'finance.invoice.manage')
            || ((string) config('enterprise_rbac.mode', 'shadow') !== 'enforce'
                && $authz->allows($user, 'finance.module.access'));
    }

    /**
     * Beri tahu semua pengguna aktif yang berhak menerbitkan invoice. Pakai
     * legacyHasCapability (bukan allows) supaya tidak mencatat satu keputusan
     * RBAC per pengguna setiap kali deal dimenangkan.
     */
    private function notifyFinance(Deal $deal, User $actor): void
    {
        $sudahAda = AppNotification::query()
            ->where('company_id', $deal->company_id)
            ->where('type', 'crm_deal_invoice_needed')
            ->where('ref_table', 'crm_deals')
            ->where('ref_id', $deal->id)
            ->whereNull('read_at')
            ->exists();
        if ($sudahAda) {
            return;
        }

        $penerima = User::query()
            ->where('company_id', $deal->company_id)
            ->where(fn ($q) => PenggunaAktif::saring($q))
            ->where('id', '!=', $actor->id)
            ->get()
            ->filter(fn (User $u) => $u->legacyHasCapability('finance.invoice.manage')
                || $u->legacyHasCapability('finance.module.access'));

        $base = rtrim((string) config('sso.platforms.crm.base_url', 'https://crm.ibereality.com'), '/');
        $tautan = "{$base}/deals/{$deal->id}";
        $nilai = number_format((float) $deal->amount, 0, ',', '.');
        $pelanggan = $this->customerLabel($deal);
        $notifikasi = app(NotificationService::class);
        foreach ($penerima as $u) {
            $notifikasi->notifyUser($u->id, (int) $deal->company_id, [
                'type' => 'crm_deal_invoice_needed',
                'severity' => 'info',
                'title' => "Deal {$deal->code} menang - invoice perlu diterbitkan",
                'body' => "{$deal->title} · {$pelanggan} · Rp {$nilai}. Ditandai menang oleh {$actor->name}.",
                'link_url' => $tautan,
                'ref_table' => 'crm_deals',
                'ref_id' => $deal->id,
            ]);
        }

        // Email juga. Lonceng in-app saja tidak cukup: tim Keuangan jarang
        // membuka ERP, jadi invoice bisa mengendap tanpa ada yang tahu.
        // Kegagalan email TIDAK boleh menggagalkan deal yang sudah menang.
        try {
            $this->ensureInvoiceNeededTemplate((int) $deal->company_id);
            $dispatcher = app(NotificationDispatcher::class);
            foreach ($penerima as $u) {
                $dispatcher->dispatch('crm_deal_invoice_needed', $u, [
                    'kode_deal' => $deal->code,
                    'judul_deal' => $deal->title,
                    'pelanggan' => $pelanggan,
                    'nilai' => $nilai,
                    'pemenang' => $actor->name,
                    'tautan' => $tautan,
                ],
                    // Tombol di email (didukung NotificationDispatcher produksi
                    // sejak 19 Agu 2026; versi lama mengabaikan argumen ini dan
                    // tautan tetap ada di isi email).
                    $tautan, 'Buka Deal');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Email serah-terima invoice deal {$deal->id} gagal: ".$e->getMessage());
        }
    }

    /**
     * Templat email serah terima ke Keuangan. Dibuat saat pertama dipakai
     * (bukan lewat migrasi) dan tidak pernah ditimpa, jadi admin bebas
     * menyuntingnya di Pengaturan > Notifikasi seperti templat lain.
     * NotificationDispatcher diam-diam melewati event tanpa templat.
     */
    private function ensureInvoiceNeededTemplate(int $companyId): void
    {
        NotificationTemplate::query()->firstOrCreate(
            ['company_id' => $companyId, 'event_key' => 'crm_deal_invoice_needed'],
            [
                'event_label' => 'Deal Menang - Invoice Perlu Diterbitkan',
                'body' => "Halo {{user_name}},\n\nDeal {{kode_deal}} \"{{judul_deal}}\" untuk {{pelanggan}} "
                    ."senilai Rp {{nilai}} sudah ditandai MENANG oleh {{pemenang}}.\n\n"
                    ."Invoice-nya belum dibuat karena menunggu tim Keuangan. Buka deal berikut lalu tekan "
                    ."\"Terbitkan draft invoice\":\n{{tautan}}",
                'email_enabled' => true,
                'whatsapp_enabled' => false,
                'slack_enabled' => false,
            ],
        );
    }

    private function customerLabel(Deal $deal): string
    {
        if ($deal->crm_company_id) {
            $nama = CrmCompany::query()->whereKey($deal->crm_company_id)->value('name');
            if ($nama) {
                return $nama;
            }
        }
        if ($deal->crm_contact_id) {
            $kontak = Contact::query()->find($deal->crm_contact_id);
            if ($kontak?->full_name) {
                return $kontak->full_name;
            }
        }

        return $deal->title;
    }

    /**
     * Pelanggan Keuangan (cari/buat dari company CRM atau kontak) + draft
     * invoice satu baris, lalu tautkan ke deal.
     */
    private function createDraftInvoice(Deal $deal, User $actor): ?Customer
    {
        $customerId = null;

        if ($deal->crm_company_id) {
            $crmCompany = CrmCompany::query()
                ->where('company_id', $deal->company_id)
                ->find($deal->crm_company_id);
            if ($crmCompany) {
                if ($crmCompany->fin_customer_id) {
                    $customerId = $crmCompany->fin_customer_id;
                } else {
                    $customer = Customer::create([
                        'company_id' => $deal->company_id,
                        'name' => $crmCompany->name,
                        'email' => $crmCompany->email,
                        'phone' => $crmCompany->phone,
                        'address' => $crmCompany->address,
                        'city' => $crmCompany->city,
                        'province' => $crmCompany->province,
                        'code' => $this->generateCustomerCode((int) $deal->company_id),
                        'is_active' => true,
                    ]);
                    $customerId = $customer->id;
                    $crmCompany->update(['fin_customer_id' => $customerId]);
                }
            }
        }

        // Fallback kalau deal tidak tertaut ke company. Pakai nama KONTAK bila
        // ada - dulu selalu judul deal, sehingga master pelanggan Keuangan
        // terisi nama seperti "Pengadaan Mesin CV X" alih-alih nama pelanggan.
        if (! $customerId) {
            $contact = $deal->crm_contact_id ? Contact::query()->find($deal->crm_contact_id) : null;
            $customer = Customer::create([
                'company_id' => $deal->company_id,
                'name' => $contact?->full_name ?: $deal->title,
                'email' => $contact?->email,
                'phone' => $contact?->mobile ?: $contact?->phone,
                'code' => $this->generateCustomerCode((int) $deal->company_id),
                'is_active' => true,
            ]);
            $customerId = $customer->id;
        }

        // Customer snapshot fields - Invoice butuh customer_name + address
        $customer = Customer::find($customerId);

        // Draft invoice - admin bisa edit lines, tax, dll sebelum issue
        $invoice = Invoice::create([
            'company_id' => $deal->company_id,
            'number' => $this->generateInvoiceNo((int) $deal->company_id),
            'date' => Carbon::today(),
            'due_date' => Carbon::today()->addDays(30),
            'customer_id' => $customerId,
            'customer_name' => $customer?->name ?? $deal->title,
            'customer_npwp' => $customer?->npwp,
            'customer_address' => $customer?->address,
            'description' => "Auto-generated dari CRM Deal {$deal->code} - {$deal->title}",
            'reference' => $deal->code,
            'status' => 'draft',
            'subtotal' => $deal->amount,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $deal->amount,
            'created_by' => $actor->id,
        ]);

        // Single-line invoice - admin bisa breakdown lebih detail nanti
        DB::table('fin_invoice_lines')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'invoice_id' => $invoice->id,
            'description' => $deal->title,
            'quantity' => 1,
            'unit_price' => $deal->amount,
            'amount' => $deal->amount,
            'line_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deal->update(['fin_invoice_id' => $invoice->id]);

        return $customer;
    }

    /**
     * Bridge handoff sales→delivery: auto-create Project di Projects module
     * supaya tim delivery langsung ada workspace untuk eksekusi deal yang sudah won.
     * Best-effort - gagal create project gak block closed-won (e.g. Project model
     * unavailable, project name collision, dll). Log saja kalau error.
     */
    private function createDeliveryProject(Deal $deal, User $actor, string $customerName): void
    {
        try {
            $projectName = "[{$deal->code}] {$deal->title}";
            $exists = \App\Models\Project::query()
                ->where('company_id', $deal->company_id)
                ->where('name', $projectName)
                ->exists();
            if (! $exists) {
                \App\Models\Project::create([
                    'company_id' => $deal->company_id,
                    'name' => $projectName,
                    'description' => "Auto-created dari CRM Deal {$deal->code}.\n\nCustomer: {$customerName}\nNilai: Rp ".number_format((float) $deal->amount, 0, ',', '.')."\n\n".($deal->description ?? ''),
                    'status' => 'active',
                    'created_by' => $actor->id,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("CRM closed-won project bridge failed for deal {$deal->id}: ".$e->getMessage());
        }
    }

    private function generateCode(int $companyId): string
    {
        $year = date('Y');
        $last = Deal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', "DEAL-{$year}-%")
            ->orderByDesc('code')
            ->lockForUpdate()
            ->value('code');
        $seq = 1;
        if ($last) {
            $seq = ((int) substr($last, -4)) + 1;
        }

        return "DEAL-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function generateCustomerCode(int $companyId): string
    {
        $last = Customer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'CUST-%')
            ->orderByDesc('code')
            ->value('code');
        $seq = $last ? ((int) substr($last, 5)) + 1 : 1;

        return 'CUST-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function generateInvoiceNo(int $companyId): string
    {
        $month = date('Y/m');
        $last = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('number', 'like', "INV-{$month}/%")
            ->orderByDesc('number')
            ->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return "INV-{$month}/".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

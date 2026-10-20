<?php

namespace App\Marketing\Services;

use App\Marketing\Models\Company;
use App\Marketing\Models\Contact;
use App\Marketing\Models\Deal;
use App\Marketing\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(private readonly DealService $dealService) {}

    public function listForUser(User $user, array $filters = []): Builder
    {
        $q = Lead::query()->where('company_id', $user->company_id);

        if ($status = $filters['status'] ?? null) {
            $q->where('status', $status);
        }
        if ($ownerId = $filters['owner_id'] ?? null) {
            $q->where('owner_id', $ownerId);
        }
        if ($search = $filters['q'] ?? null) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        return $q;
    }

    public function create(User $user, array $data): Lead
    {
        $data['company_id'] = $user->company_id;
        $data['owner_id'] = $data['owner_id'] ?? $user->id;

        return Lead::create($data);
    }

    /**
     * Convert qualified lead → deal. Lead di-mark converted (gak deleted)
     * supaya history kebaca.
     */
    public function convertToDeal(Lead $lead, User $user, array $dealData): Deal
    {
        return DB::transaction(function () use ($lead, $user, $dealData) {
            $dealData['title'] = $dealData['title'] ?? "Deal from {$lead->name}";
            $dealData['source_lead_id'] = $lead->id;
            $dealData['owner_id'] = $dealData['owner_id'] ?? $lead->owner_id ?? $user->id;

            // Dulu deal hasil konversi tidak tertaut ke company maupun kontak,
            // padahal lead menyimpan nama perusahaan, email, dan telepon. Akibat
            // berantai: halaman deal "Contact: Belum di-set" permanen, dan saat
            // Closed Won pelanggan Keuangan dibuat dengan nama JUDUL DEAL.
            // Pilihan eksplisit dari pengguna tetap diutamakan.
            if (empty($dealData['crm_company_id']) && filled($lead->company_name)) {
                $dealData['crm_company_id'] = $this->findOrCreateCompany($lead, $user)->id;
            }
            if (empty($dealData['crm_contact_id']) && filled($lead->name)) {
                $dealData['crm_contact_id'] = $this->findOrCreateContact(
                    $lead, $user, $dealData['crm_company_id'] ?? null
                )->id;
            }

            $deal = $this->dealService->create($user, $dealData);

            $lead->update([
                'status' => 'qualified',
                'converted_deal_id' => $deal->id,
                'converted_at' => now(),
            ]);

            return $deal;
        });
    }

    /** Pakai company bernama sama (tanpa peduli huruf besar/kecil) bila sudah ada. */
    private function findOrCreateCompany(Lead $lead, User $user): Company
    {
        $nama = trim($lead->company_name);

        return Company::query()
            ->where('company_id', $user->company_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])
            ->first()
            ?? Company::create([
                'company_id' => $user->company_id,
                'name' => $nama,
                'country' => 'Indonesia',
                'notes' => "Dibuat otomatis dari konversi lead #{$lead->id}.",
                'owner_id' => $lead->owner_id ?? $user->id,
            ]);
    }

    /**
     * Pakai kontak dengan email sama bila ada. Kontak baru jadi primary hanya
     * kalau company-nya belum punya primary contact.
     */
    private function findOrCreateContact(Lead $lead, User $user, ?int $crmCompanyId): Contact
    {
        if (filled($lead->email)) {
            $ada = Contact::query()
                ->where('company_id', $user->company_id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($lead->email))])
                ->first();
            if ($ada) {
                if (! $ada->crm_company_id && $crmCompanyId) {
                    $ada->update(['crm_company_id' => $crmCompanyId]);
                }

                return $ada;
            }
        }

        $bagian = preg_split('/\s+/', trim($lead->name), 2);
        $sudahAdaPrimary = $crmCompanyId && Contact::query()
            ->where('crm_company_id', $crmCompanyId)
            ->where('is_primary', true)
            ->exists();

        return Contact::create([
            'company_id' => $user->company_id,
            'crm_company_id' => $crmCompanyId,
            'first_name' => $bagian[0],
            'last_name' => $bagian[1] ?? null,
            'email' => $lead->email,
            'mobile' => $lead->phone,
            'notes' => "Dibuat otomatis dari konversi lead #{$lead->id}.",
            'is_primary' => $crmCompanyId && ! $sudahAdaPrimary,
            'owner_id' => $lead->owner_id ?? $user->id,
        ]);
    }
}

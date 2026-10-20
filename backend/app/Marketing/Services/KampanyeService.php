<?php

namespace App\Marketing\Services;

use App\Marketing\Models\Campaign;
use App\Marketing\Models\CampaignRecipient;
use App\Marketing\Models\EmailSuppression;
use App\Models\Company as Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Logika kampanye email: menyusun penerima, merender isi, dan mengirim
 * dengan batas harian keras.
 *
 * Batas harian dihitung dari baris berstatus `sent` hari ini (WIB) di SEMUA
 * perusahaan, karena semuanya keluar dari IP dan domain yang sama.
 */
class KampanyeService
{
    public function __construct(private readonly PengirimKampanye $pengirim) {}

    // ── Penerima ──────────────────────────────────────────────────────────

    /**
     * Calon penerima sesuai filter, sudah dibuang duplikat, alamat tidak sah,
     * dan alamat yang ada di daftar tekan (berhenti berlangganan/bounce).
     *
     * @return Collection<int, array{email:string,name:?string,organization:?string,source_type:string,source_id:?int}>
     */
    public function calonPenerima(int $companyId, array $audience): Collection
    {
        $sumber = $audience['sources'] ?? [];
        $kota = trim((string) ($audience['city'] ?? ''));
        $statusLead = array_filter((array) ($audience['lead_statuses'] ?? []));
        $hasil = collect();

        if (in_array('contacts', $sumber, true)) {
            $q = DB::table('crm_contacts as c')
                ->leftJoin('crm_companies as p', 'p.id', '=', 'c.crm_company_id')
                ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
                ->whereNotNull('c.email')->where('c.email', '!=', '');
            if ($kota !== '') {
                $q->where('p.city', 'like', "%{$kota}%");
            }
            $q->select('c.id', 'c.email', 'c.first_name', 'c.last_name', 'p.name as org')->orderBy('c.id')
                ->get()->each(fn ($r) => $hasil->push([
                    'email' => $r->email, 'name' => trim("{$r->first_name} {$r->last_name}") ?: null,
                    'organization' => $r->org, 'source_type' => 'contact', 'source_id' => $r->id,
                ]));
        }

        if (in_array('leads', $sumber, true)) {
            $q = DB::table('crm_leads')->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotNull('email')->where('email', '!=', '');
            if ($statusLead) {
                $q->whereIn('status', $statusLead);
            }
            $q->orderBy('id')->get(['id', 'email', 'name', 'company_name'])
                ->each(fn ($r) => $hasil->push([
                    'email' => $r->email, 'name' => $r->name, 'organization' => $r->company_name,
                    'source_type' => 'lead', 'source_id' => $r->id,
                ]));
        }

        if (in_array('companies', $sumber, true)) {
            $q = DB::table('crm_companies')->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotNull('email')->where('email', '!=', '');
            if ($kota !== '') {
                $q->where('city', 'like', "%{$kota}%");
            }
            $q->orderBy('id')->get(['id', 'email', 'name'])
                ->each(fn ($r) => $hasil->push([
                    'email' => $r->email, 'name' => null, 'organization' => $r->name,
                    'source_type' => 'company', 'source_id' => $r->id,
                ]));
        }

        foreach ($this->uraiDaftarManual((string) ($audience['manual'] ?? '')) as $m) {
            $hasil->push($m + ['source_type' => 'manual', 'source_id' => null]);
        }

        $ditekan = EmailSuppression::where('company_id', $companyId)->pluck('email')
            ->map(fn ($e) => strtolower($e))->flip();

        return $hasil
            ->map(fn ($r) => ['email' => strtolower(trim($r['email']))] + $r)
            ->filter(fn ($r) => filter_var($r['email'], FILTER_VALIDATE_EMAIL) && ! $ditekan->has($r['email']))
            ->unique('email')
            ->values();
    }

    /**
     * Satu alamat per baris. Diterima: "email", "Nama <email>", "email, Nama, Perusahaan".
     */
    public function uraiDaftarManual(string $teks): array
    {
        $hasil = [];
        foreach (preg_split('/\r\n|\r|\n/', $teks) as $baris) {
            $baris = trim($baris);
            if ($baris === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]+)>\s*$/', $baris, $m)) {
                $hasil[] = ['email' => trim($m[2]), 'name' => trim($m[1], " \"'") ?: null, 'organization' => null];
                continue;
            }
            $bagian = array_map('trim', preg_split('/[,;\t]/', $baris));
            $hasil[] = ['email' => $bagian[0], 'name' => ($bagian[1] ?? '') ?: null, 'organization' => ($bagian[2] ?? '') ?: null];
        }

        return $hasil;
    }

    /** Membekukan daftar penerima saat kampanye dimulai. Mengembalikan jumlah penerima. */
    public function siapkanPenerima(Campaign $kampanye): int
    {
        $sekarang = now();
        $baris = $this->calonPenerima($kampanye->company_id, $kampanye->audience ?? [])
            ->map(fn ($r) => [
                'campaign_id' => $kampanye->id,
                'company_id' => $kampanye->company_id,
                'email' => Str::limit($r['email'], 190, ''),
                'name' => $r['name'] ? Str::limit($r['name'], 150, '') : null,
                'organization' => $r['organization'] ? Str::limit($r['organization'], 150, '') : null,
                'source_type' => $r['source_type'],
                'source_id' => $r['source_id'],
                'status' => 'pending',
                'token' => Str::random(40),
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);

        foreach ($baris->chunk(500) as $potong) {
            CampaignRecipient::insertOrIgnore($potong->all());
        }

        $jumlah = CampaignRecipient::where('campaign_id', $kampanye->id)->count();
        $kampanye->update(['recipient_count' => $jumlah]);

        return $jumlah;
    }

    // ── Kuota ─────────────────────────────────────────────────────────────

    public function terkirimHariIni(): int
    {
        return CampaignRecipient::where('status', 'sent')
            ->where('sent_at', '>=', $this->awalHariIni())
            ->count();
    }

    public function sisaKuotaHariIni(): int
    {
        return max(0, (int) config('crm.kampanye.batas_harian') - $this->terkirimHariIni());
    }

    public function dalamJendelaKirim(?Carbon $waktu = null): bool
    {
        $w = ($waktu ?? now())->copy()->setTimezone('Asia/Jakarta');
        if (config('crm.kampanye.hari_kerja_saja') && $w->isWeekend()) {
            return false;
        }

        return $w->hour >= (int) config('crm.kampanye.jam_mulai')
            && $w->hour < (int) config('crm.kampanye.jam_selesai');
    }

    private function awalHariIni(): Carbon
    {
        return now('Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone'));
    }

    // ── Render ────────────────────────────────────────────────────────────

    public function urlBerhenti(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/crm/public/unsubscribe/'.$token;
    }

    /**
     * Isi kampanye adalah teks biasa (bukan HTML bebas) - aman dipratinjau dan
     * lebih kecil kemungkinan ditandai spam. Versi HTML dibuat dari teks itu.
     *
     * @return array{subject:string,text:string,html:string}
     */
    public function render(Campaign $kampanye, ?string $nama, ?string $organisasi, ?string $urlBerhenti): array
    {
        $ganti = [
            '{nama}' => $nama ?: 'Bapak/Ibu',
            '{perusahaan}' => $organisasi ?: 'perusahaan Anda',
        ];
        $subjek = strtr($kampanye->subject, $ganti);
        $isi = strtr(str_replace("\r\n", "\n", $kampanye->body), $ganti);

        $tenant = Tenant::find($kampanye->company_id);
        $identitas = array_filter([
            $tenant?->name,
            $tenant?->address,
            $tenant?->phone ? 'Telp. '.$tenant->phone : null,
        ]);
        $alasan = 'Anda menerima email ini karena alamat Anda terdaftar sebagai kontak bisnis kami.';

        $teks = $isi."\n\n--\n".implode("\n", $identitas)."\n\n".$alasan;
        if ($urlBerhenti) {
            $teks .= "\nBerhenti menerima email seperti ini: {$urlBerhenti}";
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $paragraf = collect(preg_split("/\n{2,}/", trim($isi)))
            ->map(fn ($p) => '<p style="margin:0 0 14px">'.nl2br($e($p)).'</p>')->implode('');
        $kaki = '<p style="margin:0">'.implode('<br>', array_map($e, $identitas)).'</p>'
            .'<p style="margin:10px 0 0">'.$e($alasan)
            .($urlBerhenti ? ' <a href="'.$e($urlBerhenti).'" style="color:#666">Berhenti berlangganan</a>.' : '')
            .'</p>';
        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#ffffff">'
            .'<div style="max-width:600px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#222">'
            .$paragraf
            .'<div style="margin-top:28px;padding-top:12px;border-top:1px solid #ddd;font-size:12px;line-height:1.5;color:#666">'
            .$kaki.'</div></div></body></html>';

        return ['subject' => $subjek, 'text' => $teks, 'html' => $html];
    }

    public function namaPengirim(Campaign $kampanye): string
    {
        return $kampanye->from_name ?: (Tenant::find($kampanye->company_id)?->name ?: 'IBE Reality');
    }

    // ── Kirim ─────────────────────────────────────────────────────────────

    /** Token tautan berhenti berlangganan di email uji & pratinjau - tidak menyimpan apa pun. */
    public const TOKEN_CONTOH = 'contoh';

    /**
     * Kirim uji ke satu alamat, tanpa mencatat penerima dan tanpa memakai kuota
     * kampanye. Tautan berhenti berlangganan tetap tampil (versi contoh) supaya
     * email uji sama persis dengan yang diterima pelanggan; header
     * List-Unsubscribe sengaja tidak dipasang karena tidak ada penerima nyata.
     */
    public function kirimUji(Campaign $kampanye, string $ke, ?string $nama): void
    {
        $isi = $this->render($kampanye, $nama, null, $this->urlBerhenti(self::TOKEN_CONTOH));
        $this->pengirim->kirim([
            'to' => $ke, 'toName' => $nama, 'fromName' => $this->namaPengirim($kampanye),
            'subject' => '[UJI] '.$isi['subject'], 'text' => $isi['text'], 'html' => $isi['html'],
            'unsubscribeUrl' => null,
        ]);
    }

    /**
     * Satu putaran penjadwal: kirim paling banyak `$maks` email (dan tidak
     * melewati sisa kuota hari ini). Mengembalikan [terkirim, gagal].
     */
    public function putaran(int $maks): array
    {
        $this->bereskanYangTerputus();

        $jatah = min($maks, $this->sisaKuotaHariIni());
        $terkirim = 0;
        $gagal = 0;

        if ($jatah > 0) {
            $antrean = CampaignRecipient::query()
                ->join('crm_campaigns as k', 'k.id', '=', 'crm_campaign_recipients.campaign_id')
                ->where('k.status', 'sending')->whereNull('k.deleted_at')
                ->where('crm_campaign_recipients.status', 'pending')
                ->orderBy('k.started_at')->orderBy('crm_campaign_recipients.id')
                ->limit($jatah)
                ->get(['crm_campaign_recipients.*']);

            foreach ($antrean as $r) {
                // Kunci atomik: putaran lain yang kebetulan berjalan bersamaan tidak ikut mengirim.
                if (! CampaignRecipient::where('id', $r->id)->where('status', 'pending')->update(['status' => 'processing'])) {
                    continue;
                }
                if (EmailSuppression::where('company_id', $r->company_id)->where('email', $r->email)->exists()) {
                    $r->update(['status' => 'skipped', 'error' => 'Berhenti berlangganan']);
                    continue;
                }

                $kampanye = $r->campaign;
                try {
                    $isi = $this->render($kampanye, $r->name, $r->organization, $this->urlBerhenti($r->token));
                    $this->pengirim->kirim([
                        'to' => $r->email, 'toName' => $r->name, 'fromName' => $this->namaPengirim($kampanye),
                        'subject' => $isi['subject'], 'text' => $isi['text'], 'html' => $isi['html'],
                        'unsubscribeUrl' => $this->urlBerhenti($r->token),
                    ]);
                    $r->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                    $terkirim++;
                } catch (\Throwable $e) {
                    $r->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480)]);
                    $gagal++;
                }
            }
        }

        $this->tandaiSelesai();

        return [$terkirim, $gagal];
    }

    /**
     * Baris `processing` yang tertinggal (proses mati di tengah kirim) TIDAK
     * dikirim ulang: bisa jadi email sudah keluar. Ditandai gagal agar terlihat.
     */
    private function bereskanYangTerputus(): void
    {
        CampaignRecipient::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'error' => 'Terputus saat mengirim - periksa folder Terkirim sebelum mengirim ulang']);
    }

    private function tandaiSelesai(): void
    {
        Campaign::withoutGlobalScopes()->where('status', 'sending')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_campaign_recipients as r')
                ->whereColumn('r.campaign_id', 'crm_campaigns.id')
                ->whereIn('r.status', ['pending', 'processing']))
            ->update(['status' => 'done', 'finished_at' => now()]);
    }

    public function statistik(Campaign $kampanye): array
    {
        $hitung = CampaignRecipient::where('campaign_id', $kampanye->id)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (int) $hitung->sum(),
            'pending' => (int) (($hitung['pending'] ?? 0) + ($hitung['processing'] ?? 0)),
            'sent' => (int) ($hitung['sent'] ?? 0),
            'failed' => (int) ($hitung['failed'] ?? 0),
            'skipped' => (int) ($hitung['skipped'] ?? 0),
            'unsubscribed' => EmailSuppression::where('campaign_id', $kampanye->id)->where('reason', 'unsubscribe')->count(),
        ];
    }

    public function berhentiBerlangganan(CampaignRecipient $r): void
    {
        EmailSuppression::firstOrCreate(
            ['company_id' => $r->company_id, 'email' => strtolower($r->email)],
            ['reason' => 'unsubscribe', 'campaign_id' => $r->campaign_id],
        );
        CampaignRecipient::where('company_id', $r->company_id)->where('email', $r->email)
            ->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Berhenti berlangganan']);
    }
}

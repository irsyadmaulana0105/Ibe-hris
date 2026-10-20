<?php

namespace App\Marketing\Services;

use App\Models\SharedMailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu email kampanye. Transport dipilih lewat config
 * crm.kampanye.transport:
 *   - jmap: lewat Stalwart sebagai mailbox bersama (marketing@), salinan
 *     masuk folder Terkirim sehingga terlihat di webmail tim marketing.
 *   - log : hanya dicatat ke log (uji/lokal).
 *
 * Kredensial mailbox diambil dari shared_mailboxes (terenkripsi) saat dipakai
 * dan tidak pernah ditulis ke log atau galat.
 *
 * Sesi JMAP (akun, folder, identitas) disimpan selama objek hidup supaya satu
 * putaran penjadwal tidak membuka sesi berulang untuk tiap penerima.
 */
class PengirimKampanye
{
    private ?array $sesi = null;

    /**
     * @param array{to:string,toName:?string,fromName:string,subject:string,text:string,html:string,unsubscribeUrl:?string} $pesan
     */
    public function kirim(array $pesan): void
    {
        if (config('crm.kampanye.transport') === 'log') {
            Log::info('[kampanye] kirim (transport log)', [
                'to' => $pesan['to'], 'subject' => $pesan['subject'],
            ]);
            return;
        }

        $this->kirimJmap($pesan);
    }

    private function kirimJmap(array $pesan): void
    {
        $sesi = $this->sesi();
        $alamat = $sesi['email'];

        $email = [
            'mailboxIds' => [$sesi['drafts'] => true],
            'keywords' => ['$draft' => true, '$seen' => true],
            'from' => [['name' => $pesan['fromName'], 'email' => $alamat]],
            'replyTo' => [['name' => $pesan['fromName'], 'email' => $alamat]],
            'to' => [array_filter(['name' => $pesan['toName'] ?: null, 'email' => $pesan['to']])],
            'subject' => $pesan['subject'],
            'bodyValues' => [
                't1' => ['value' => $pesan['text']],
                'h1' => ['value' => $pesan['html']],
            ],
            'textBody' => [['partId' => 't1', 'type' => 'text/plain']],
            'htmlBody' => [['partId' => 'h1', 'type' => 'text/html']],
        ];
        if (! empty($pesan['unsubscribeUrl'])) {
            // RFC 2369 + RFC 8058: tombol "Berhenti berlangganan" bawaan Gmail/Outlook.
            // Gmail & Yahoo mewajibkannya untuk pengirim email massal.
            $email['header:List-Unsubscribe:asRaw'] = ' <'.$pesan['unsubscribeUrl'].'>';
            $email['header:List-Unsubscribe-Post:asRaw'] = ' List-Unsubscribe=One-Click';
        }

        $update = ["mailboxIds/{$sesi['drafts']}" => null, 'keywords/$draft' => null];
        if ($sesi['sent']) {
            $update["mailboxIds/{$sesi['sent']}"] = true;
        }

        $res = $this->panggil([
            'urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail', 'urn:ietf:params:jmap:submission',
        ], [
            ['Email/set', ['accountId' => $sesi['akun'], 'create' => ['d1' => $email]], 'c0'],
            ['EmailSubmission/set', [
                'accountId' => $sesi['akunKirim'],
                'create' => ['s1' => ['emailId' => '#d1', 'identityId' => $sesi['identitas']]],
                'onSuccessUpdateEmail' => ['#s1' => $update],
            ], 'c1'],
        ]);

        foreach ($res['methodResponses'] ?? [] as $r) {
            if (($r[0] ?? null) === 'error') {
                throw new \RuntimeException('JMAP error: '.json_encode($r[1] ?? null));
            }
            if (! empty($r[1]['notCreated'])) {
                throw new \RuntimeException('Ditolak server: '.json_encode($r[1]['notCreated']));
            }
        }
    }

    private function sesi(): array
    {
        if ($this->sesi) {
            return $this->sesi;
        }

        $alamat = (string) config('crm.kampanye.pengirim');
        $kotak = SharedMailbox::where('email', $alamat)->where('is_active', true)->first();
        if (! $kotak) {
            throw new \RuntimeException("Mailbox pengirim {$alamat} tidak terdaftar atau nonaktif.");
        }
        $this->sesi = ['email' => $alamat, 'sandi' => $kotak->decryptPassword()];

        $s = Http::withBasicAuth($alamat, $this->sesi['sandi'])->acceptJson()->timeout(20)
            ->get(rtrim(config('crm.kampanye.jmap_base'), '/').'/.well-known/jmap');
        if (! $s->ok()) {
            $this->sesi = null;
            throw new \RuntimeException("Sesi JMAP gagal (HTTP {$s->status()}).");
        }
        $akun = $s->json('primaryAccounts')['urn:ietf:params:jmap:mail'] ?? null;
        $akunKirim = $s->json('primaryAccounts')['urn:ietf:params:jmap:submission'] ?? null;
        if (! $akun || ! $akunKirim) {
            $this->sesi = null;
            throw new \RuntimeException('Sesi JMAP tanpa akun utama.');
        }
        $this->sesi += ['akun' => $akun, 'akunKirim' => $akunKirim];

        $res = $this->panggil(['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail', 'urn:ietf:params:jmap:submission'], [
            ['Mailbox/get', ['accountId' => $akun], 'c0'],
            ['Identity/get', ['accountId' => $akunKirim], 'c1'],
        ]);
        $folder = collect($res['methodResponses'][0][1]['list'] ?? []);
        $identitas = collect($res['methodResponses'][1][1]['list'] ?? []);
        $drafts = $folder->firstWhere('role', 'drafts');
        $id = $identitas->firstWhere('email', $alamat) ?? $identitas->first();
        if (! $drafts || ! $id) {
            $this->sesi = null;
            throw new \RuntimeException('Folder Draf atau identitas pengirim tidak ditemukan.');
        }

        return $this->sesi += [
            'drafts' => $drafts['id'],
            'sent' => $folder->firstWhere('role', 'sent')['id'] ?? null,
            'identitas' => $id['id'],
        ];
    }

    private function panggil(array $using, array $calls): array
    {
        $res = Http::withBasicAuth($this->sesi['email'], $this->sesi['sandi'])
            ->acceptJson()->timeout(30)
            ->withBody(json_encode(['using' => $using, 'methodCalls' => $calls], JSON_UNESCAPED_SLASHES), 'application/json')
            ->post(rtrim(config('crm.kampanye.jmap_base'), '/').'/jmap/');
        if (! $res->ok()) {
            throw new \RuntimeException("JMAP HTTP {$res->status()}");
        }

        return $res->json();
    }
}

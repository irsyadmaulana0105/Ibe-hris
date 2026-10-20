<?php

/**
 * Kampanye email CRM. Yang paling penting dijaga: batas harian TIDAK pernah
 * terlampaui, dan alamat yang berhenti berlangganan tidak pernah dikirimi lagi.
 */

use App\Marketing\Models\Campaign;
use App\Marketing\Models\CampaignRecipient;
use App\Marketing\Models\EmailSuppression;
use App\Marketing\Services\PengirimKampanye;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'crm');

beforeEach(function () {
    [$this->user, $token] = adminWithToken(['company_id' => 1, 'group' => 'marketing']);
    $this->h = bearerHeaders($token);

    config([
        'crm.kampanye.batas_harian' => 3,
        'crm.kampanye.per_putaran' => 10,
        'crm.kampanye.jam_mulai' => 8,
        'crm.kampanye.jam_selesai' => 17,
        'crm.kampanye.hari_kerja_saja' => true,
    ]);

    // Selasa 13.00 WIB - di dalam jendela kirim.
    Carbon::setTestNow(Carbon::parse('2026-10-06 13:00:00', 'Asia/Jakarta'));

    $this->terkirim = new ArrayObject();
    $catat = $this->terkirim;
    $this->instance(PengirimKampanye::class, new class($catat) extends PengirimKampanye {
        public function __construct(private ArrayObject $catat) {}

        public function kirim(array $pesan): void
        {
            $this->catat->append($pesan);
        }
    });
});

afterEach(fn () => Carbon::setTestNow());

function kampanyeBaru($test, string $manual, array $extra = []): int
{
    return $test->postJson('/api/v1/crm/campaigns', array_merge([
        'name' => 'Penawaran Oktober',
        'subject' => 'Solusi otomasi untuk {perusahaan}',
        'body' => "Yth. {nama},\n\nKami menawarkan <b>robot</b> industri.",
        'audience' => ['sources' => [], 'manual' => $manual],
    ], $extra), $test->h)->assertCreated()->json('data.id');
}

function lima(): string
{
    return implode("\n", ['a@x.test', 'Budi <b@x.test>', 'c@x.test, Citra, PT C', 'd@x.test', 'e@x.test']);
}

it('previews recipients without duplicates, invalid or suppressed addresses', function () {
    EmailSuppression::create(['company_id' => 1, 'email' => 'keluar@x.test', 'reason' => 'unsubscribe']);
    DB::table('crm_leads')->insert([
        'company_id' => 1, 'name' => 'Lead Satu', 'email' => 'A@X.test', 'status' => 'new',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->postJson('/api/v1/crm/campaigns/audience-preview', [
        'sources' => ['leads'],
        'manual' => "a@x.test\nbukan-email\nkeluar@x.test\nBaru <baru@x.test>",
    ], $this->h)->assertOk();

    expect($res->json('data.count'))->toBe(2)
        ->and(collect($res->json('data.sample'))->pluck('email')->all())->toBe(['a@x.test', 'baru@x.test']);
});

it('never sends more than the daily limit across rounds', function () {
    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk()
        ->assertJsonPath('data.recipient_count', 5);

    $this->artisan('crm:kampanye-kirim')->assertSuccessful();
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();

    expect($this->terkirim)->toHaveCount(3)
        ->and(CampaignRecipient::where('status', 'sent')->count())->toBe(3)
        ->and(CampaignRecipient::where('status', 'pending')->count())->toBe(2);

    // Hari berikutnya kuota pulih dan sisa antrean terkirim; kampanye selesai.
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Jakarta'));
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();

    expect($this->terkirim)->toHaveCount(5)
        ->and(Campaign::withoutGlobalScopes()->find($id)->status)->toBe('done');
});

it('sends nothing outside the sending window', function () {
    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-10-06 20:00:00', 'Asia/Jakarta'));
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Jakarta')); // Sabtu
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();

    expect($this->terkirim)->toHaveCount(0);
});

it('does not send while paused', function () {
    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk();
    $this->postJson("/api/v1/crm/campaigns/{$id}/pause", [], $this->h)->assertOk();

    $this->artisan('crm:kampanye-kirim')->assertSuccessful();

    expect($this->terkirim)->toHaveCount(0);
});

it('personalises, escapes html and carries an unsubscribe link', function () {
    $id = kampanyeBaru($this, 'Budi <b@x.test>');
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk();
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();

    $pesan = $this->terkirim[0];
    $token = CampaignRecipient::first()->token;

    expect($pesan['to'])->toBe('b@x.test')
        ->and($pesan['subject'])->toBe('Solusi otomasi untuk perusahaan Anda')
        ->and($pesan['text'])->toContain('Yth. Budi,')
        ->and($pesan['html'])->toContain('&lt;b&gt;robot&lt;/b&gt;')->not->toContain('<b>robot</b>')
        ->and($pesan['unsubscribeUrl'])->toEndWith("/api/v1/crm/public/unsubscribe/{$token}")
        ->and($pesan['html'])->toContain($pesan['unsubscribeUrl']);
});

it('only unsubscribes on POST and then skips the address', function () {
    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk();
    $r = CampaignRecipient::where('email', 'a@x.test')->first();

    // Pemindai tautan membuka GET - tidak boleh mengubah apa pun.
    $this->get("/api/v1/crm/public/unsubscribe/{$r->token}")->assertOk()->assertSee('a@x.test');
    expect(EmailSuppression::count())->toBe(0);

    $this->post("/api/v1/crm/public/unsubscribe/{$r->token}")->assertOk();
    expect(EmailSuppression::where('email', 'a@x.test')->exists())->toBeTrue()
        ->and($r->fresh()->status)->toBe('skipped');

    config(['crm.kampanye.batas_harian' => 50]);
    $this->artisan('crm:kampanye-kirim')->assertSuccessful();
    expect(collect($this->terkirim)->pluck('to'))->not->toContain('a@x.test')->toHaveCount(4);

    // Kampanye berikutnya pun tidak memasukkannya.
    $this->postJson('/api/v1/crm/campaigns/audience-preview', ['manual' => 'a@x.test'], $this->h)
        ->assertJsonPath('data.count', 0);
});

it('explains the sample unsubscribe link from test emails without recording anything', function () {
    $this->get('/api/v1/crm/public/unsubscribe/contoh')->assertOk()->assertSee('Tautan contoh');
    $this->post('/api/v1/crm/public/unsubscribe/contoh')->assertNotFound();
    expect(EmailSuppression::count())->toBe(0);
});

it('rejects an unknown unsubscribe token', function () {
    $this->post('/api/v1/crm/public/unsubscribe/'.str_repeat('z', 40))->assertNotFound();
});

it('locks a campaign once started and refuses an empty audience', function () {
    $kosong = kampanyeBaru($this, '');
    $this->postJson("/api/v1/crm/campaigns/{$kosong}/start", [], $this->h)->assertStatus(422);

    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/start", [], $this->h)->assertOk();
    $this->putJson("/api/v1/crm/campaigns/{$id}", ['subject' => 'Ganti'], $this->h)->assertStatus(422);
    $this->deleteJson("/api/v1/crm/campaigns/{$id}", [], $this->h)->assertStatus(422);
});

it('hides other companies campaigns', function () {
    $lain = Campaign::withoutGlobalScopes()->create([
        'company_id' => 2, 'name' => 'Rahasia', 'subject' => 's', 'body' => 'b',
        'status' => 'draft', 'created_by' => $this->user->id,
    ]);

    $this->getJson("/api/v1/crm/campaigns/{$lain->id}", $this->h)->assertNotFound();
    $this->postJson("/api/v1/crm/campaigns/{$lain->id}/start", [], $this->h)->assertNotFound();
    expect(collect($this->getJson('/api/v1/crm/campaigns', $this->h)->json('data'))->pluck('id'))->not->toContain($lain->id);
});

it('sends a test email to the current user without touching the quota', function () {
    $id = kampanyeBaru($this, lima());
    $this->postJson("/api/v1/crm/campaigns/{$id}/test", [], $this->h)->assertOk();

    expect($this->terkirim[0]['to'])->toBe($this->user->email)
        ->and($this->terkirim[0]['subject'])->toStartWith('[UJI] ')
        ->and($this->terkirim[0]['html'])->toContain('/api/v1/crm/public/unsubscribe/contoh')
        ->and($this->terkirim[0]['text'])->toContain('Berhenti menerima email seperti ini:')
        ->and($this->terkirim[0]['unsubscribeUrl'])->toBeNull()
        ->and(CampaignRecipient::count())->toBe(0);
    $this->getJson('/api/v1/crm/campaigns/quota', $this->h)->assertJsonPath('data.sent_today', 0);
});

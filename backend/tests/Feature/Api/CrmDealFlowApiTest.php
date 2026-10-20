<?php

/**
 * Alur deal CRM - dikunci setelah audit browser 30 Sep 2026 dengan akun
 * berperan marketing. Setiap tes di sini mewakili satu cacat yang ditemukan
 * di produksi, supaya tidak kembali diam-diam.
 */

use App\Marketing\Models\Company as CrmCompany;
use App\Marketing\Models\Contact;
use App\Marketing\Models\Pipeline;
use App\Marketing\Models\Stage;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'crm');

beforeEach(function () {
    [$this->user, $token] = adminWithToken(['company_id' => 1, 'group' => 'marketing']);
    $this->h = bearerHeaders($token);

    $this->pipeline = Pipeline::withoutGlobalScopes()->create([
        'company_id' => 1, 'name' => 'Sales', 'is_default' => true, 'is_active' => true,
    ]);
    $stage = fn ($name, $type, $order, $prob) => Stage::withoutGlobalScopes()->create([
        'company_id' => 1, 'pipeline_id' => $this->pipeline->id, 'name' => $name,
        'type' => $type, 'sort_order' => $order, 'default_probability' => $prob,
    ]);
    $this->open = $stage('Prospecting', 'open', 10, 10);
    $this->proposal = $stage('Proposal', 'open', 30, 50);
    $this->won = $stage('Closed Won', 'won', 50, 100);
    $this->lost = $stage('Closed Lost', 'lost', 60, 0);
});

function buatLead($test, array $data = []): int
{
    return $test->postJson('/api/v1/crm/leads', array_merge([
        'name' => 'Siti Rahayu',
        'email' => 'siti@maju.test',
        'phone' => '081298765432',
        'company_name' => 'CV Maju Bersama',
    ], $data), $test->h)->assertCreated()->json('data.id');
}

/**
 * Guard Sanctum menyimpan pengguna pertama yang login selama satu tes;
 * tanpa reset, permintaan dengan token lain tetap berjalan sebagai pengguna
 * itu. Panggil setiap kali berganti pengguna.
 */
function gantiPengguna($test): void
{
    app('auth')->forgetGuards();
}

function konversi($test, int $leadId): array
{
    return $test->postJson("/api/v1/crm/leads/{$leadId}/convert", [
        'pipeline_id' => $test->pipeline->id, 'title' => 'Pengadaan Mesin', 'amount' => 150000000,
    ], $test->h)->assertCreated()->json('data');
}

// ── konversi lead ────────────────────────────────────────────────────────

it('links a company and a contact when converting a lead', function () {
    $deal = konversi($this, buatLead($this));

    $company = CrmCompany::withoutGlobalScopes()->find($deal['crm_company_id']);
    $contact = Contact::withoutGlobalScopes()->find($deal['crm_contact_id']);

    expect($company?->name)->toBe('CV Maju Bersama')
        ->and($contact?->first_name)->toBe('Siti')
        ->and($contact?->last_name)->toBe('Rahayu')
        ->and($contact?->email)->toBe('siti@maju.test')
        ->and($contact?->mobile)->toBe('081298765432')
        ->and($contact?->crm_company_id)->toBe($company->id)
        ->and($contact?->is_primary)->toBeTrue();
});

it('reuses an existing company and contact instead of duplicating them', function () {
    $company = CrmCompany::withoutGlobalScopes()->create(['company_id' => 1, 'name' => 'CV MAJU BERSAMA']);
    $contact = Contact::withoutGlobalScopes()->create([
        'company_id' => 1, 'first_name' => 'Siti', 'email' => 'SITI@maju.test',
    ]);

    $deal = konversi($this, buatLead($this));

    expect($deal['crm_company_id'])->toBe($company->id)
        ->and($deal['crm_contact_id'])->toBe($contact->id)
        ->and(CrmCompany::withoutGlobalScopes()->count())->toBe(1)
        ->and(Contact::withoutGlobalScopes()->count())->toBe(1)
        ->and($contact->fresh()->crm_company_id)->toBe($company->id);
});

// ── tautan company / kontak di deal ──────────────────────────────────────

it('lets a deal be linked to a company and contact on create and update', function () {
    $company = CrmCompany::withoutGlobalScopes()->create(['company_id' => 1, 'name' => 'PT Sinergi']);
    $contact = Contact::withoutGlobalScopes()->create(['company_id' => 1, 'first_name' => 'Budi', 'crm_company_id' => $company->id]);

    $id = $this->postJson('/api/v1/crm/deals', [
        'pipeline_id' => $this->pipeline->id, 'title' => 'Maintenance', 'crm_company_id' => $company->id,
    ], $this->h)->assertCreated()->json('data.id');

    $this->putJson("/api/v1/crm/deals/{$id}", ['crm_contact_id' => $contact->id], $this->h)
        ->assertOk()
        ->assertJsonPath('data.crm_company.name', 'PT Sinergi')
        ->assertJsonPath('data.crm_contact.full_name', 'Budi');
});

it('rejects linking a company that belongs to another tenant', function () {
    $asing = CrmCompany::withoutGlobalScopes()->create(['company_id' => 2, 'name' => 'Milik Tenant Lain']);

    $this->postJson('/api/v1/crm/deals', [
        'pipeline_id' => $this->pipeline->id, 'title' => 'Coba', 'crm_company_id' => $asing->id,
    ], $this->h)->assertUnprocessable()->assertJsonValidationErrors(['crm_company_id']);
});

it('accepts a ULID owner id', function () {
    $this->postJson('/api/v1/crm/deals', [
        'pipeline_id' => $this->pipeline->id, 'title' => 'Dengan pemilik', 'owner_id' => $this->user->id,
    ], $this->h)->assertCreated()->assertJsonPath('data.owner_id', $this->user->id);
});

// ── kanban ───────────────────────────────────────────────────────────────

it('shows recently closed deals in the won and lost columns of the board', function () {
    $won = konversi($this, buatLead($this));
    $this->postJson("/api/v1/crm/deals/{$won['id']}/move", ['stage_id' => $this->won->id], $this->h)->assertOk();

    $lama = konversi($this, buatLead($this, ['email' => 'lain@x.test', 'company_name' => 'PT Lama']));
    DB::table('crm_deals')->where('id', $lama['id'])->update([
        'stage_id' => $this->won->id, 'status' => 'won', 'closed_at' => now()->subDays(40)->toDateString(),
    ]);

    $kolom = collect($this->getJson("/api/v1/crm/deals/board?pipeline_id={$this->pipeline->id}", $this->h)
        ->assertOk()->json('data.stages'))->keyBy('name');

    expect(collect($kolom['Closed Won']['deals'])->pluck('id')->all())->toBe([$won['id']]);
});

// ── jejak & invoice ──────────────────────────────────────────────────────

it('records every stage change in the activity timeline', function () {
    $deal = konversi($this, buatLead($this));

    $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $this->lost->id, 'lost_reason' => 'competitor'], $this->h)
        ->assertOk();

    $catatan = DB::table('crm_activities')->where('subjectable_type', 'deal')->where('subjectable_id', $deal['id'])->first();
    expect($catatan?->subject)->toBe('Tahap: Prospecting → Closed Lost')
        ->and($catatan?->body)->toBe('Alasan kalah: competitor')
        ->and($catatan?->user_id)->toBe($this->user->id);
});

it('does not create a second invoice when a won deal is reopened and won again', function () {
    // Superadmin berhak atas Keuangan, jadi invoice dibuat otomatis saat menang.
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $h = bearerHeaders($token);
    $deal = konversi($this, buatLead($this));
    gantiPengguna($this);
    $pindah = fn ($stage) => $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $stage->id], $h)->assertOk();

    $pertama = $pindah($this->won)->json('data.fin_invoice_id');
    $pindah($this->proposal);
    $kedua = $pindah($this->won)->json('data.fin_invoice_id');

    expect($pertama)->not->toBeNull()
        ->and($kedua)->toBe($pertama)
        ->and(DB::table('fin_invoices')->count())->toBe(1)
        ->and(DB::table('fin_customers')->count())->toBe(1);
});

// ── serah terima ke Keuangan ─────────────────────────────────────────────

it('hands a deal won by marketing over to finance instead of creating the invoice', function () {
    [$keuangan] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $deal = konversi($this, buatLead($this));

    $res = $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $this->won->id], $this->h)->assertOk();

    expect($res->json('data.status'))->toBe('won')
        ->and($res->json('data.fin_invoice_id'))->toBeNull()
        ->and(DB::table('fin_invoices')->count())->toBe(0)
        ->and(DB::table('fin_customers')->count())->toBe(0)
        ->and(DB::table('projects')->where('name', 'like', "%{$deal['code']}%")->count())->toBe(1);

    $notif = DB::table('app_notifications')->where('user_id', $keuangan->id)->first();
    expect($notif?->type)->toBe('crm_deal_invoice_needed')
        ->and($notif?->link_url)->toEndWith("/deals/{$deal['id']}")
        ->and(DB::table('app_notifications')->where('user_id', $this->user->id)->count())->toBe(0);

    $this->getJson("/api/v1/crm/deals/{$deal['id']}", $this->h)->assertOk()
        ->assertJsonPath('data.can_issue_invoice', false);
});

it('emails finance with a link to the deal when marketing wins it', function () {
    \Illuminate\Support\Facades\Mail::fake();
    [$keuangan] = adminWithToken(['company_id' => 1, 'group' => 'finance', 'email' => 'keuangan@uji.test']);
    $deal = konversi($this, buatLead($this));

    $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $this->won->id], $this->h)->assertOk();

    // Templat dibuat otomatis saat pertama dipakai, sekali saja.
    expect(DB::table('notification_templates')->where('event_key', 'crm_deal_invoice_needed')->count())->toBe(1);
    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\EventNotificationMail::class,
        fn ($m) => $m->hasTo('keuangan@uji.test')
            && str_contains($m->renderedBody, "/deals/{$deal['id']}")
            && str_contains($m->renderedBody, 'CV Maju Bersama'));
    \Illuminate\Support\Facades\Mail::assertNotQueued(\App\Mail\EventNotificationMail::class,
        fn ($m) => $m->hasTo($this->user->email));
});

it('lets finance issue the draft invoice from the deal, once', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $hk = bearerHeaders($token);
    $deal = konversi($this, buatLead($this));
    $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $this->won->id], $this->h)->assertOk();
    gantiPengguna($this);

    $this->getJson("/api/v1/crm/deals/{$deal['id']}", $hk)->assertOk()
        ->assertJsonPath('data.can_issue_invoice', true);

    $res = $this->postJson("/api/v1/crm/deals/{$deal['id']}/invoice", [], $hk)->assertOk();
    // Pelanggan dinamai sesuai company, bukan judul deal.
    expect($res->json('data.fin_invoice_number'))->toStartWith('INV-')
        ->and($res->json('data.can_issue_invoice'))->toBeFalse()
        ->and(DB::table('fin_customers')->value('name'))->toBe('CV Maju Bersama');

    $this->postJson("/api/v1/crm/deals/{$deal['id']}/invoice", [], $hk)->assertUnprocessable();
    expect(DB::table('fin_invoices')->count())->toBe(1);
});

it('forbids marketing from issuing the invoice directly', function () {
    $deal = konversi($this, buatLead($this));
    $this->postJson("/api/v1/crm/deals/{$deal['id']}/move", ['stage_id' => $this->won->id], $this->h)->assertOk();

    $this->postJson("/api/v1/crm/deals/{$deal['id']}/invoice", [], $this->h)->assertForbidden();
    expect(DB::table('fin_invoices')->count())->toBe(0);
});

it('refuses an invoice for a deal that is not won', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'finance']);
    $deal = konversi($this, buatLead($this));
    gantiPengguna($this);

    $this->postJson("/api/v1/crm/deals/{$deal['id']}/invoice", [], bearerHeaders($token))->assertUnprocessable();
});

// ── Customer 360° ────────────────────────────────────────────────────────

it('serves customer 360 without a sql error', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    DB::table('fin_customers')->insert([
        'company_id' => 1, 'code' => 'CUST-0001',
        'name' => 'PT Uji', 'npwp' => '01.234.567.8-901.000', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/v1/admin/customer-360', bearerHeaders($token))->assertOk();
});

// ── formulir web ─────────────────────────────────────────────────────────

function formWeb(array $attr): \App\Marketing\Models\LeadForm
{
    return \App\Marketing\Models\LeadForm::withoutGlobalScopes()->create(array_merge([
        'company_id' => 1, 'token' => \Illuminate\Support\Str::random(32), 'name' => 'Form Landing',
        'fields' => [
            ['key' => 'name', 'label' => 'Nama', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
        ],
        'is_active' => true,
    ], $attr));
}

it('assigns a web form lead to the form creator when no default owner is set', function () {
    $form = formWeb(['created_by' => $this->user->id]);

    $this->postJson("/api/v1/crm/public/forms/{$form->token}/submit", ['name' => 'Pengunjung Web', 'email' => 'web@x.test'])
        ->assertOk();

    expect(DB::table('crm_leads')->where('name', 'Pengunjung Web')->value('owner_id'))->toBe($this->user->id);
});

it('leaves a web form lead unassigned when the form creator has resigned', function () {
    [$keluar] = adminWithToken(['company_id' => 1, 'group' => 'marketing', 'resigned_at' => now()->subDay()]);
    $form = formWeb(['created_by' => $keluar->id]);

    $this->postJson("/api/v1/crm/public/forms/{$form->token}/submit", ['name' => 'Pengunjung Dua'])->assertOk();

    expect(DB::table('crm_leads')->where('name', 'Pengunjung Dua')->value('owner_id'))->toBeNull();
});

// ── funnel kumulatif ─────────────────────────────────────────────────────

it('counts every deal that ever reached a stage in the cumulative funnel', function () {
    $pindah = fn (int $id, $stage, ?string $alasan = null) => $this->postJson("/api/v1/crm/deals/{$id}/move",
        ['stage_id' => $stage->id, 'lost_reason' => $alasan], $this->h)->assertOk();
    $baru = fn (string $judul, int $nilai) => $this->postJson('/api/v1/crm/deals', [
        'pipeline_id' => $this->pipeline->id, 'title' => $judul, 'amount' => $nilai,
    ], $this->h)->assertCreated()->json('data.id');

    $baru('Masih prospek', 10);                                   // berhenti di Prospecting
    $proposal = $baru('Di proposal', 20); $pindah($proposal, $this->proposal);
    $menang = $baru('Menang', 30); $pindah($menang, $this->proposal); $pindah($menang, $this->won);
    $kalah = $baru('Kalah di proposal', 40); $pindah($kalah, $this->proposal); $pindah($kalah, $this->lost, 'price');

    $res = $this->getJson("/api/v1/crm/reports/funnel?pipeline_id={$this->pipeline->id}", $this->h)->assertOk();
    $kumulatif = collect($res->json('data.cumulative'))->keyBy('stage_name');

    // Semua 4 deal masuk Prospecting; 3 sampai Proposal (termasuk yang menang
    // dan yang kalah DI proposal); 1 menang.
    expect($kumulatif['Prospecting']['reached_count'])->toBe(4)
        ->and($kumulatif['Proposal']['reached_count'])->toBe(3)
        ->and($kumulatif['Proposal']['conversion_pct'])->toEqual(75)
        ->and($kumulatif['Closed Won']['reached_count'])->toBe(1)
        ->and($kumulatif->has('Closed Lost'))->toBeFalse()
        ->and($res->json('data.win_rate'))->toEqual(50)
        ->and($res->json('data.lost_without_history'))->toBe(0)
        // Funnel posisi-saat-ini tetap ada untuk pemakai lama.
        ->and(collect($res->json('data.rows'))->firstWhere('stage_name', 'Proposal')['deal_count'])->toBe(1);
});

it('lists only active CRM users as web form lead owners', function () {
    [$hr] = adminWithToken(['company_id' => 1, 'group' => 'admin', 'name' => 'Orang HR']);
    [$keluar] = adminWithToken(['company_id' => 1, 'group' => 'marketing', 'name' => 'Sudah Keluar', 'resigned_at' => now()->subDay()]);
    gantiPengguna($this);

    $nama = collect($this->getJson('/api/v1/crm/forms/owners', $this->h)->assertOk()->json('data'))->pluck('name');

    expect($nama)->toContain($this->user->name)
        ->and($nama)->not->toContain('Sudah Keluar');
});

it('rejects a web form owner from another tenant', function () {
    $tenantLain = \App\Models\Company::query()->create(['name' => 'Tenant Lain', 'slug' => 'tenant-lain']);
    [$asing] = adminWithToken(['company_id' => $tenantLain->id, 'group' => 'marketing']);
    gantiPengguna($this);

    $this->postJson('/api/v1/crm/forms', [
        'name' => 'Form', 'fields' => [['key' => 'name', 'label' => 'Nama', 'type' => 'text']],
        'default_owner_id' => $asing->id,
    ], $this->h)->assertUnprocessable()->assertJsonValidationErrors('default_owner_id');
});

it('treats an account without an employment status as active', function () {
    // Akun bersama "Marketing" di produksi tidak punya employment_status;
    // NULL NOT IN (...) membuatnya dianggap nonaktif (4 Okt 2026).
    DB::table('users')->where('id', $this->user->id)->update(['employment_status' => null]);
    $form = formWeb(['created_by' => $this->user->id]);

    $nama = collect($this->getJson('/api/v1/crm/forms/owners', $this->h)->assertOk()->json('data'))->pluck('name');
    $this->postJson("/api/v1/crm/public/forms/{$form->token}/submit", ['name' => 'Pengunjung Tiga'])->assertOk();

    expect($nama)->toContain($this->user->name)
        ->and(DB::table('crm_leads')->where('name', 'Pengunjung Tiga')->value('owner_id'))->toBe($this->user->id);
});

it('places an old lost deal at its last stage using the audit log when the activity note is missing', function () {
    $id = $this->postJson('/api/v1/crm/deals', ['pipeline_id' => $this->pipeline->id, 'title' => 'Lama', 'amount' => 5], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/crm/deals/{$id}/move", ['stage_id' => $this->proposal->id], $this->h)->assertOk();
    $this->postJson("/api/v1/crm/deals/{$id}/move", ['stage_id' => $this->lost->id, 'lost_reason' => 'price'], $this->h)->assertOk();
    // Seperti deal yang kalah sebelum catatan "Tahap: A → B" ada.
    DB::table('crm_activities')->where('subjectable_id', $id)->delete();

    $res = $this->getJson("/api/v1/crm/reports/funnel?pipeline_id={$this->pipeline->id}", $this->h)->assertOk();
    $kumulatif = collect($res->json('data.cumulative'))->keyBy('stage_name');

    expect($kumulatif['Proposal']['reached_count'])->toBe(1)
        ->and($res->json('data.lost_without_history'))->toBe(0);
});

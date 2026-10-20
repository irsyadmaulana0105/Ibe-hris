<?php

use App\Models\ContractTemplate;
use App\Models\ContractTemplateVersion;

uses()->group('api', 'contract-template-security');

it('sanitizes contract template storage previews history and restores without losing safe layout', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'admin']);
    $headers = bearerHeaders($token);
    $malicious = <<<'HTML'
<div class="contract" style="color:#123456;background-image:url(https://attacker.invalid/background.png)">
  <script>window.__contract_template_xss = true</script>
  <style>@import url('https://attacker.invalid/tracker.css');</style>
  <img src="https://attacker.invalid/pixel.png" onerror="window.__contract_template_xss=true">
  <svg onload="window.__contract_template_xss=true"><script>alert(1)</script></svg>
  <iframe src="https://attacker.invalid/frame"></iframe>
  <a href="javascript:alert(1)">tautan tidak aman</a>
  <table style="width:100%;border:1px solid #000"><tbody><tr><td colspan="2" rowspan="1">{{ nama }}</td></tr></tbody></table>
</div>
HTML;

    $assertSafe = function (string $html, bool $rendered = false): void {
        $lower = strtolower($html);
        expect($lower)
            ->not->toContain('<script')
            ->not->toContain('<style')
            ->not->toContain('<img')
            ->not->toContain('<svg')
            ->not->toContain('<iframe')
            ->not->toContain('onerror')
            ->not->toContain('onload')
            ->not->toContain('javascript:')
            ->not->toContain('attacker.invalid')
            ->not->toContain('background-image')
            ->not->toContain('__contract_template_xss')
            ->and($lower)->toContain('<table')
            ->and($lower)->toContain('colspan="2"')
            ->and($lower)->toContain('rowspan="1"');

        $rendered
            ? expect($html)->toContain('CONTOH KARYAWAN')
            : expect($html)->toContain('{{ nama }}');
    };

    $created = $this->postJson('/api/v1/admin/contract-templates', [
        'employment_type' => 'permanent',
        'title' => 'Template Aman',
        'body_html' => $malicious,
    ], $headers)->assertOk();

    $template = ContractTemplate::query()->where('employment_type', 'permanent')->firstOrFail();
    $assertSafe((string) $template->body_html);
    $assertSafe((string) $created->json('data.template.body_html'));

    $this->postJson('/api/v1/admin/contract-templates', [
        'employment_type' => 'permanent',
        'title' => 'Template Aman Diperbarui',
        'body_html' => $malicious,
    ], $headers)->assertOk();
    expect($template->fresh()->created_by)->toBe($admin->id)
        ->and($template->fresh()->updated_by)->toBe($admin->id);

    // Simulate a pre-hardening/out-of-band row. Every read and render path must
    // remain safe without silently rewriting the historical source record.
    $template->update(['body_html' => $malicious]);
    $legacyVersion = ContractTemplateVersion::create([
        'template_id' => $template->id,
        'version' => 99,
        'title' => 'Legacy Unsafe Snapshot',
        'body_html' => $malicious,
        'source_type' => 'text',
        'snapshot_by' => $admin->id,
        'snapshot_reason' => 'pre-hardening-fixture',
        'snapshot_at' => now(),
    ]);

    $showBody = (string) $this->getJson('/api/v1/admin/contract-templates/permanent', $headers)
        ->assertOk()
        ->json('data.template.body_html');
    $assertSafe($showBody);

    $previewBody = (string) $this->getJson('/api/v1/admin/contract-templates/permanent/preview', $headers)
        ->assertOk()
        ->json('data.rendered_html');
    $assertSafe($previewBody, true);

    $versionBody = (string) $this->getJson(
        "/api/v1/admin/contract-templates/permanent/versions/{$legacyVersion->id}",
        $headers,
    )->assertOk()->json('data.body_html');
    $assertSafe($versionBody);
    expect($legacyVersion->fresh()->body_html)->toBe($malicious);

    $restored = $this->postJson(
        "/api/v1/admin/contract-templates/permanent/restore/{$legacyVersion->id}",
        [],
        $headers,
    )->assertOk();
    $assertSafe((string) $restored->json('data.template.body_html'));
    $assertSafe((string) $template->fresh()->body_html);
});

it('rejects a contract template whose entire body is prohibited active markup', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'admin']);

    $this->postJson('/api/v1/admin/contract-templates', [
        'employment_type' => 'probation',
        'title' => 'Markup Aktif',
        'body_html' => '<script>window.__only_script = true</script><iframe src="https://attacker.invalid"></iframe>',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body_html');

    expect(ContractTemplate::query()->where('employment_type', 'probation')->exists())->toBeFalse();
});

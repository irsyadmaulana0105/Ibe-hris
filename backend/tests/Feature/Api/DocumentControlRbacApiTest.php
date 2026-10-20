<?php

use App\Models\Company;
use App\Models\RbacUserAssignment;
use App\Models\User;
use App\Services\EnterpriseAuthorizationService;
use App\Services\EnterpriseRbacProvisioningService;
use App\Support\EnterpriseDocumentRbacV1;
use App\Surat\Models\CompanyBank;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterAttachment;
use App\Surat\Models\LetterCategory;
use App\Surat\Models\LetterSetting;
use App\Surat\Models\LetterSignature;
use App\Surat\Models\LetterTemplate;
use App\Surat\Services\LetterPdfService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'enterprise-rbac', 'documents');

/** @param array<int, string> $capabilities */
function grantDocumentControlRole(User $user, string $roleKey, array $capabilities): void
{
    $roleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => $user->company_id,
        'key' => $roleKey,
        'label' => str($roleKey)->headline()->toString(),
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (array_unique($capabilities) as $capability) {
        $capabilityId = DB::table('rbac_capabilities')
            ->where('company_id', $user->company_id)
            ->where('capability_key', $capability)
            ->value('id');
        if (! $capabilityId) {
            $capabilityId = DB::table('rbac_capabilities')->insertGetId([
                'company_id' => $user->company_id,
                'module' => explode('.', $capability)[0],
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('rbac_role_capability')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
        ]);
    }

    RbacUserAssignment::create([
        'company_id' => $user->company_id,
        'user_id' => $user->id,
        'role_id' => $roleId,
        'status' => 'active',
        'valid_from' => now()->subMinute(),
        'valid_until' => now()->addDay(),
        'reason' => 'Document control authorization test',
        'source' => 'test',
    ]);
}

function documentControlToken(User $user): string
{
    return $user->createToken('document-control-test', ['admin', 'employee'])->plainTextToken;
}

function resetDocumentControlAuth(): void
{
    app('auth')->forgetGuards();
    app()->forgetInstance(EnterpriseAuthorizationService::class);
}

/** @return array<string, string> */
function expectedDocumentControlRoutes(): array
{
    return [
        'GET api/v1/admin/letters' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters-registry' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters-export-csv' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters-analytics/dashboard' => 'enterprise.capability:documents.analytics.view,admin.access',
        'GET api/v1/admin/letters-analytics/sequence-gaps' => 'enterprise.capability:documents.analytics.view,admin.access',
        'POST api/v1/admin/letters/{publicId}/reply' => 'enterprise.capability:documents.letter.create,admin.access',
        'GET api/v1/admin/letters/{publicId}/attachments' => 'enterprise.capability:documents.letter.view,admin.access',
        'POST api/v1/admin/letters/{publicId}/attachments' => 'enterprise.capability:documents.letter.manage,admin.access',
        'DELETE api/v1/admin/letters/{publicId}/attachments/{attId}' => 'enterprise.capability:documents.letter.manage,admin.access',
        'GET api/v1/admin/letters/{publicId}/attachments/{attId}/download' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters/{publicId}/dispositions' => 'enterprise.capability:documents.letter.view,admin.access',
        'POST api/v1/admin/letters/{publicId}/dispositions' => 'enterprise.capability:documents.letter.manage,admin.access',
        'POST api/v1/admin/letters/{publicId}/dispositions/{dispId}/complete' => 'enterprise.capability:documents.letter.manage,admin.access',
        'DELETE api/v1/admin/letters/{publicId}/dispositions/{dispId}' => 'enterprise.capability:documents.letter.manage,admin.access',
        'POST api/v1/admin/letters' => 'enterprise.capability:documents.letter.create,admin.access',
        'GET api/v1/admin/letters/preview-number' => 'enterprise.capability:documents.letter.create,admin.access',
        'GET api/v1/admin/letters/{publicId}' => 'enterprise.capability:documents.letter.view,admin.access',
        'PUT api/v1/admin/letters/{publicId}' => 'enterprise.capability:documents.letter.manage,admin.access',
        'DELETE api/v1/admin/letters/{publicId}' => 'enterprise.capability:documents.letter.manage,admin.access',
        'POST api/v1/admin/letters/{publicId}/submit' => 'enterprise.capability:documents.letter.manage,admin.access',
        'POST api/v1/admin/letters/{publicId}/revise' => 'enterprise.capability:documents.letter.approve,admin.access',
        'POST api/v1/admin/letters/{publicId}/approve' => 'enterprise.capability:documents.letter.approve,admin.access',
        'POST api/v1/admin/letters/{publicId}/sign' => 'enterprise.capability:documents.letter.sign,admin.access',
        'POST api/v1/admin/letters/{publicId}/send' => 'enterprise.capability:documents.letter.send,admin.access',
        'POST api/v1/admin/letters/{publicId}/archive' => 'enterprise.capability:documents.letter.manage,admin.access',
        'POST api/v1/admin/letters/{publicId}/cancel' => 'enterprise.capability:documents.letter.manage,admin.access',
        'GET api/v1/admin/letters/{publicId}/preview-pdf' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters/{publicId}/download-pdf' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letters/{publicId}/revisions' => 'enterprise.capability:documents.letter.view,admin.access',
        'GET api/v1/admin/letter-categories' => 'enterprise.capability:documents.category.view,admin.access',
        'PUT api/v1/admin/letter-categories/{id}' => 'enterprise.capability:documents.category.manage,admin.access',
        'GET api/v1/admin/letter-templates' => 'enterprise.capability:documents.template.view,admin.access',
        'POST api/v1/admin/letter-templates' => 'enterprise.capability:documents.template.manage,admin.access',
        'GET api/v1/admin/letter-templates/{id}' => 'enterprise.capability:documents.template.view,admin.access',
        'PUT api/v1/admin/letter-templates/{id}' => 'enterprise.capability:documents.template.manage,admin.access',
        'DELETE api/v1/admin/letter-templates/{id}' => 'enterprise.capability:documents.template.manage,admin.access',
        'GET api/v1/admin/letter-signatures' => 'enterprise.capability:documents.signature.view,admin.access',
        'GET api/v1/admin/letter-signatures/{id}/assets/{asset}' => 'enterprise.capability:documents.signature.view,admin.access',
        'POST api/v1/admin/letter-signatures' => 'enterprise.capability:documents.signature.prepare,admin.access',
        'PUT api/v1/admin/letter-signatures/{id}' => 'enterprise.capability:documents.signature.prepare,admin.access',
        'POST api/v1/admin/letter-signatures/{id}/approve' => 'enterprise.capability:documents.signature.approve,admin.access',
        'DELETE api/v1/admin/letter-signatures/{id}' => 'enterprise.capability:documents.signature.approve,admin.access',
        'GET api/v1/admin/letter-settings' => 'enterprise.capability:documents.settings.view,admin.access',
        'POST api/v1/admin/letter-settings' => 'enterprise.capability:documents.settings.manage,admin.access',
        'GET api/v1/admin/company-banks' => 'enterprise.capability:finance.company_bank.view,admin.access',
        'POST api/v1/admin/company-banks' => 'enterprise.capability:finance.company_bank.manage,admin.access',
        'PUT api/v1/admin/company-banks/{id}' => 'enterprise.capability:finance.company_bank.manage,admin.access',
        'DELETE api/v1/admin/company-banks/{id}' => 'enterprise.capability:finance.company_bank.manage,admin.access',
        'GET api/v1/admin/documents' => 'enterprise.capability:documents.repository.view,admin.access',
        'POST api/v1/admin/documents' => 'enterprise.capability:documents.repository.manage,admin.access',
        'GET api/v1/admin/documents/{id}' => 'enterprise.capability:documents.repository.view,admin.access',
        'PUT api/v1/admin/documents/{id}' => 'enterprise.capability:documents.repository.manage,admin.access',
        'DELETE api/v1/admin/documents/{id}' => 'enterprise.capability:documents.repository.manage,admin.access',
        'POST api/v1/admin/documents/{id}/versions' => 'enterprise.capability:documents.repository.manage,admin.access',
        'GET api/v1/admin/documents/{id}/versions/{versionId}/download' => 'enterprise.capability:documents.repository.view,admin.access',
    ];
}

it('seeds bounded document-control roles with separate signature duties', function () {
    app(EnterpriseRbacProvisioningService::class)->provisionCompany(1);

    $capabilitiesFor = fn (string $roleKey) => DB::table('rbac_role_capability as rc')
        ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
        ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
        ->where('r.company_id', 1)
        ->where('r.key', $roleKey)
        ->pluck('c.capability_key')
        ->all();

    $documentController = $capabilitiesFor('document_controller');
    $director = $capabilitiesFor('director');
    $financeController = $capabilitiesFor('finance_controller');
    $accountant = $capabilitiesFor('accountant');
    $treasuryManager = $capabilitiesFor('treasury_manager');
    $treasuryOfficer = $capabilitiesFor('treasury_officer');

    expect($documentController)->toContain('admin.access')
        ->toContain('documents.repository.manage')
        ->toContain('documents.letter.manage')
        ->toContain('documents.signature.prepare')
        ->toContain('finance.company_bank.view')
        ->not->toContain('documents.signature.approve')
        ->not->toContain('documents.signature.manage')
        ->not->toContain('finance.company_bank.manage')
        ->and($director)->toContain('documents.signature.approve')
        ->toContain('documents.letter.approve')
        ->toContain('documents.letter.sign')
        ->not->toContain('documents.signature.prepare')
        ->and($financeController)->toContain('admin.access')
        ->toContain('finance.company_bank.view')
        ->toContain('finance.company_bank.manage')
        ->and($accountant)->toContain('admin.access')
        ->toContain('finance.company_bank.view')
        ->not->toContain('finance.company_bank.manage')
        ->and($treasuryManager)->toContain('admin.access')
        ->toContain('finance.company_bank.view')
        ->toContain('finance.company_bank.manage')
        ->and($treasuryOfficer)->toContain('admin.access')
        ->toContain('finance.company_bank.view')
        ->not->toContain('finance.company_bank.manage');
});

it('declares explicit capability middleware on every document letter and bank route', function () {
    $expected = expectedDocumentControlRoutes();
    expect($expected)->toHaveCount(55);

    $routes = collect(Route::getRoutes()->getRoutes());
    foreach ($expected as $signature => $middleware) {
        [$method, $uri] = explode(' ', $signature, 2);
        $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true));

        expect($route)->not->toBeNull("Missing route {$signature}")
            ->and($route->gatherMiddleware())->toContain($middleware);
    }

    $actual = $routes
        ->filter(function ($route): bool {
            $uri = $route->uri();

            return str_starts_with($uri, 'api/v1/admin/letters')
                || str_starts_with($uri, 'api/v1/admin/letter-')
                || str_starts_with($uri, 'api/v1/admin/company-banks')
                || $uri === 'api/v1/admin/documents'
                || str_starts_with($uri, 'api/v1/admin/documents/');
        })
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => "{$method} {$route->uri()}"))
        ->sort()
        ->values()
        ->all();
    $declared = array_keys($expected);
    sort($declared);

    expect($actual)->toBe($declared);
});

it('denies an admin-surface-only assignment for every document capability family', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $admin = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    grantDocumentControlRole($admin, 'admin_surface_only', ['admin.access']);
    $headers = bearerHeaders(documentControlToken($admin));

    foreach ([
        ['GET', '/api/v1/admin/documents'],
        ['POST', '/api/v1/admin/documents'],
        ['GET', '/api/v1/admin/letters'],
        ['GET', '/api/v1/admin/letters/preview-number'],
        ['PUT', '/api/v1/admin/letters/missing'],
        ['POST', '/api/v1/admin/letters/missing/approve'],
        ['POST', '/api/v1/admin/letters/missing/sign'],
        ['POST', '/api/v1/admin/letters/missing/send'],
        ['GET', '/api/v1/admin/letters-analytics/sequence-gaps'],
        ['GET', '/api/v1/admin/letter-categories'],
        ['PUT', '/api/v1/admin/letter-categories/999'],
        ['GET', '/api/v1/admin/letter-templates'],
        ['POST', '/api/v1/admin/letter-templates'],
        ['GET', '/api/v1/admin/letter-signatures'],
        ['POST', '/api/v1/admin/letter-signatures'],
        ['POST', '/api/v1/admin/letter-signatures/999/approve'],
        ['GET', '/api/v1/admin/letter-settings'],
        ['POST', '/api/v1/admin/letter-settings'],
        ['GET', '/api/v1/admin/company-banks'],
        ['POST', '/api/v1/admin/company-banks'],
    ] as [$method, $uri]) {
        $this->json($method, $uri, [], $headers)->assertForbidden();
    }
});

it('allows document and finance controllers only within bounded duties', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $documentController = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($documentController, 'document_controller_allowed', [
        'admin.access',
        'documents.repository.view',
        'documents.repository.manage',
        'documents.letter.view',
        'documents.letter.create',
        'documents.letter.manage',
        'documents.letter.send',
        'documents.analytics.view',
        'documents.category.view',
        'documents.category.manage',
        'documents.template.view',
        'documents.template.manage',
        'documents.signature.view',
        'documents.signature.prepare',
        'documents.settings.view',
        'documents.settings.manage',
        'finance.company_bank.view',
    ]);
    $headers = bearerHeaders(documentControlToken($documentController));
    $category = LetterCategory::query()->create([
        'company_id' => 1,
        'code' => 'DOC',
        'name' => 'Document Control',
    ]);

    $this->putJson("/api/v1/admin/letter-categories/{$category->id}", [
        'name' => 'Controlled Document',
    ], $headers)->assertOk()->assertJsonPath('data.name', 'Controlled Document');

    $this->postJson('/api/v1/admin/letter-templates', [
        'category_id' => $category->id,
        'code' => 'CONTROLLED',
        'name' => 'Controlled Template',
        'body_html' => '<p>Approved content</p>',
    ], $headers)->assertCreated();

    $signature = $this->postJson('/api/v1/admin/letter-signatures', [
        'user_id' => $documentController->id,
        'name' => 'Prepared Signatory',
        'title' => 'Director',
        'default_for_categories' => [$category->id],
    ], $headers)->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_company_default', false);

    $this->postJson('/api/v1/admin/letter-settings', [
        'brand_name' => 'Enterprise Document Control',
    ], $headers)->assertOk()->assertJsonPath('data.brand_name', 'Enterprise Document Control');
    $this->getJson('/api/v1/admin/letters-analytics/sequence-gaps', $headers)->assertOk();
    $this->getJson('/api/v1/admin/company-banks', $headers)->assertOk();

    $this->postJson('/api/v1/admin/documents', [
        'title' => 'Information Security Policy',
        'category' => 'policy',
    ], $headers)->assertCreated();

    $letter = $this->postJson('/api/v1/admin/letters', [
        'category_id' => $category->id,
        'subject' => 'Controlled Letter',
        'body_html' => '<p>Controlled</p>',
        'letter_date' => now()->toDateString(),
    ], $headers)->assertCreated();

    $this->postJson("/api/v1/admin/letter-signatures/{$signature->json('data.id')}/approve", [], $headers)
        ->assertForbidden();
    $this->postJson("/api/v1/admin/letters/{$letter->json('data.public_id')}/approve", [], $headers)
        ->assertForbidden();
    $this->postJson('/api/v1/admin/company-banks', [], $headers)->assertForbidden();

    $financeController = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($financeController, 'finance_bank_controller_allowed', [
        'admin.access',
        'finance.company_bank.view',
        'finance.company_bank.manage',
    ]);
    resetDocumentControlAuth();
    $financeHeaders = bearerHeaders(documentControlToken($financeController));

    $this->postJson('/api/v1/admin/company-banks', [
        'bank_name' => 'Bank Test',
        'account_number' => '99887766',
        'account_name' => 'PT Test Enterprise',
        'currency' => 'IDR',
    ], $financeHeaders)->assertCreated();
    $this->getJson('/api/v1/admin/documents', $financeHeaders)->assertForbidden();
    $this->postJson('/api/v1/admin/letter-templates', [], $financeHeaders)->assertForbidden();
});

it('enforces four-eyes signature preparation approval activation and default authority', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $maker = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($maker, 'signature_maker_with_approval_for_sod_test', [
        'admin.access',
        'documents.signature.view',
        'documents.signature.prepare',
        'documents.signature.approve',
    ]);
    $makerHeaders = bearerHeaders(documentControlToken($maker));

    $this->postJson('/api/v1/admin/letter-signatures', [
        'name' => 'Forbidden Activation',
        'title' => 'Director',
        'is_active' => true,
        'is_company_default' => true,
    ], $makerHeaders)->assertForbidden();

    $response = $this->postJson('/api/v1/admin/letter-signatures', [
        'name' => 'Four Eyes Signature',
        'title' => 'Director',
    ], $makerHeaders)->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.prepared_by_id', $maker->id);
    $signatureId = (int) $response->json('data.id');

    $this->postJson("/api/v1/admin/letter-signatures/{$signatureId}/approve", [
        'is_company_default' => true,
    ], $makerHeaders)->assertForbidden();

    $approver = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($approver, 'signature_approver_only', [
        'admin.access',
        'documents.signature.view',
        'documents.signature.approve',
    ]);
    resetDocumentControlAuth();
    $approverHeaders = bearerHeaders(documentControlToken($approver));

    $this->postJson('/api/v1/admin/letter-signatures', [
        'name' => 'Approver Cannot Upload',
        'title' => 'Director',
    ], $approverHeaders)->assertForbidden();

    $this->postJson("/api/v1/admin/letter-signatures/{$signatureId}/approve", [
        'is_company_default' => true,
    ], $approverHeaders)->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_company_default', true)
        ->assertJsonPath('data.approved_by_id', $approver->id);

    resetDocumentControlAuth();
    $this->putJson("/api/v1/admin/letter-signatures/{$signatureId}", [
        'name' => 'Forbidden Replacement',
    ], $makerHeaders)->assertUnprocessable();

    resetDocumentControlAuth();
    $this->deleteJson("/api/v1/admin/letter-signatures/{$signatureId}", [], $approverHeaders)
        ->assertOk();
    $this->assertDatabaseHas('letter_signatures', [
        'id' => $signatureId,
        'is_active' => false,
        'is_company_default' => false,
    ]);
});

it('stores letter attachments privately and streams them only through tenant authorization', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    Storage::fake('local');
    Storage::fake('public');

    $company = Company::query()->create(['name' => 'Private Documents', 'slug' => 'private-documents']);
    $manager = User::factory()->create(['company_id' => $company->id, 'group' => 'user']);
    grantDocumentControlRole($manager, 'private_attachment_manager', [
        'admin.access',
        'documents.letter.view',
        'documents.letter.manage',
    ]);
    $headers = bearerHeaders(documentControlToken($manager));
    $category = LetterCategory::query()->create([
        'company_id' => $company->id,
        'code' => 'PVT',
        'name' => 'Private Attachments',
    ]);
    $letter = Letter::query()->create([
        'company_id' => $company->id,
        'category_id' => $category->id,
        'subject' => 'Private Attachment Letter',
        'body_html' => '<p>Private</p>',
        'attachments_meta' => [[
            'name' => 'legacy-safe-name.pdf',
            'path' => 'public/should-never-leak.pdf',
            'nested' => ['signed_url' => 'https://secret.invalid/file', 'size' => 20],
        ]],
        'pdf_path' => 'letters/private.pdf',
        'letter_date' => now()->toDateString(),
        'author_id' => $manager->id,
        'status' => Letter::STATUS_DRAFT,
    ]);

    $upload = $this->postJson("/api/v1/admin/letters/{$letter->public_id}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('evidence.pdf', 'classified-content'),
    ], $headers)->assertCreated()
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.disk')
        ->assertJsonMissingPath('data.url');
    $attachmentId = (int) $upload->json('data.id');
    $attachment = LetterAttachment::query()->findOrFail($attachmentId);
    $expectedPrefix = "private/letters/attachments/{$company->id}/{$letter->id}/";

    expect($attachment->path)->toStartWith($expectedPrefix)
        ->and($attachment->toArray())->not->toHaveKey('path');
    $upload->assertJsonMissingPath('data.download_url');
    Storage::disk('local')->assertExists($attachment->path);
    Storage::disk('public')->assertMissing($attachment->path);

    $this->getJson("/api/v1/admin/letters/{$letter->public_id}/attachments", $headers)
        ->assertOk()
        ->assertJsonMissingPath('data.0.path')
        ->assertJsonMissingPath('data.0.disk');
    resetDocumentControlAuth();
    $this->getJson("/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}/download")
        ->assertUnauthorized();

    $unauthorized = User::factory()->create(['company_id' => $company->id, 'group' => 'user']);
    grantDocumentControlRole($unauthorized, 'attachment_surface_only', ['admin.access']);
    resetDocumentControlAuth();
    $this->getJson(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}/download",
        bearerHeaders(documentControlToken($unauthorized)),
    )->assertForbidden();

    resetDocumentControlAuth();
    $download = $this->get(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}/download",
        $headers,
    )->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
    expect((string) $download->headers->get('cache-control'))->toContain('private')->toContain('no-store')
        ->and($download->streamedContent())->toBe('classified-content');

    Storage::disk('local')->put($attachment->path, 'tampered-content');
    resetDocumentControlAuth();
    $this->get(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}/download",
        $headers,
    )->assertConflict();
    Storage::disk('local')->put($attachment->path, 'classified-content');

    $legacyAttachment = LetterAttachment::query()->create([
        'letter_id' => $letter->id,
        'original_name' => 'legacy-public.pdf',
        'path' => 'letters/attachments/legacy-public.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 13,
        'checksum' => hash('sha256', 'legacy-public'),
        'uploaded_by_id' => $manager->id,
    ]);
    Storage::disk('public')->put($legacyAttachment->path, 'legacy-public');
    resetDocumentControlAuth();
    $this->get(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$legacyAttachment->id}/download",
        $headers,
    )->assertConflict();
    resetDocumentControlAuth();
    $this->deleteJson(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$legacyAttachment->id}",
        [],
        $headers,
    )->assertConflict();
    Storage::disk('public')->assertExists($legacyAttachment->path);
    $this->assertDatabaseHas('letter_attachments', ['id' => $legacyAttachment->id]);

    $otherCompany = Company::query()->create(['name' => 'Other Private Tenant', 'slug' => 'other-private-tenant']);
    $otherViewer = User::factory()->create(['company_id' => $otherCompany->id, 'group' => 'user']);
    grantDocumentControlRole($otherViewer, 'other_attachment_viewer', [
        'admin.access',
        'documents.letter.view',
        'documents.letter.manage',
    ]);
    resetDocumentControlAuth();
    $this->getJson(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}/download",
        bearerHeaders(documentControlToken($otherViewer)),
    )->assertNotFound();
    resetDocumentControlAuth();
    $this->deleteJson(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}",
        [],
        bearerHeaders(documentControlToken($otherViewer)),
    )->assertNotFound();
    Storage::disk('local')->assertExists($attachment->path);
    $this->assertDatabaseHas('letter_attachments', ['id' => $attachmentId]);

    resetDocumentControlAuth();
    $this->getJson("/api/v1/admin/letters/{$letter->public_id}", $headers)
        ->assertOk()
        ->assertJsonMissingPath('data.pdf_path')
        ->assertJsonPath('data.pdf_available', true)
        ->assertJsonMissingPath('data.attachments_meta.0.path')
        ->assertJsonMissingPath('data.attachments_meta.0.nested.signed_url')
        ->assertJsonPath('data.attachments_meta.0.nested.size', 20);
    $this->get('/storage/'.$attachment->path)->assertNotFound();

    resetDocumentControlAuth();
    $this->deleteJson(
        "/api/v1/admin/letters/{$letter->public_id}/attachments/{$attachmentId}",
        [],
        $headers,
    )->assertOk();
    Storage::disk('local')->assertMissing($attachment->path);
    $this->assertDatabaseMissing('letter_attachments', ['id' => $attachmentId]);
});

it('keeps signature and stamp images private through four-eyes approval and PDF rendering', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    Storage::fake('local');
    Storage::fake('public');

    $company = Company::query()->create(['name' => 'Private Signatures', 'slug' => 'private-signatures']);
    $maker = User::factory()->create(['company_id' => $company->id, 'group' => 'user']);
    grantDocumentControlRole($maker, 'private_signature_maker', [
        'admin.access',
        'documents.signature.view',
        'documents.signature.prepare',
        'documents.letter.view',
    ]);
    $makerHeaders = bearerHeaders(documentControlToken($maker));

    $response = $this->postJson('/api/v1/admin/letter-signatures', [
        'user_id' => $maker->id,
        'name' => 'Private Signer',
        'title' => 'Director',
        'signature_image' => UploadedFile::fake()->image('signature.png', 120, 40),
        'stamp_image' => UploadedFile::fake()->image('stamp.png', 80, 80),
    ], $makerHeaders)->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.signature_available', true)
        ->assertJsonPath('data.stamp_available', true)
        ->assertJsonMissingPath('data.signature_path')
        ->assertJsonMissingPath('data.stamp_path');
    $signatureId = (int) $response->json('data.id');
    $signature = LetterSignature::query()->withoutGlobalScopes()->findOrFail($signatureId);

    expect($signature->signature_path)->toStartWith("private/letters/signatures/{$company->id}/")
        ->and($signature->stamp_path)->toStartWith("private/letters/signatures/{$company->id}/")
        ->and($signature->toArray())->not->toHaveKeys(['signature_path', 'stamp_path']);
    Storage::disk('local')->assertExists($signature->signature_path);
    Storage::disk('local')->assertExists($signature->stamp_path);
    Storage::disk('public')->assertMissing($signature->signature_path);
    Storage::disk('public')->assertMissing($signature->stamp_path);

    resetDocumentControlAuth();
    $this->getJson("/api/v1/admin/letter-signatures/{$signatureId}/assets/signature")
        ->assertUnauthorized();
    resetDocumentControlAuth();
    $asset = $this->get("/api/v1/admin/letter-signatures/{$signatureId}/assets/signature", $makerHeaders)
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('x-content-type-options', 'nosniff');
    expect((string) $asset->headers->get('cache-control'))->toContain('private')->toContain('no-store')
        ->and($asset->streamedContent())->not->toBe('');

    $surfaceOnly = User::factory()->create(['company_id' => $company->id, 'group' => 'user']);
    grantDocumentControlRole($surfaceOnly, 'signature_surface_only', ['admin.access']);
    resetDocumentControlAuth();
    $this->getJson(
        "/api/v1/admin/letter-signatures/{$signatureId}/assets/stamp",
        bearerHeaders(documentControlToken($surfaceOnly)),
    )->assertForbidden();

    resetDocumentControlAuth();
    $this->postJson("/api/v1/admin/letter-signatures/{$signatureId}/approve", [], $makerHeaders)
        ->assertForbidden();
    $approver = User::factory()->create(['company_id' => $company->id, 'group' => 'user']);
    grantDocumentControlRole($approver, 'private_signature_approver', [
        'admin.access',
        'documents.signature.view',
        'documents.signature.approve',
    ]);
    resetDocumentControlAuth();
    $this->postJson(
        "/api/v1/admin/letter-signatures/{$signatureId}/approve",
        ['is_company_default' => true],
        bearerHeaders(documentControlToken($approver)),
    )->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.signature_path')
        ->assertJsonMissingPath('data.stamp_path');

    $otherCompany = Company::query()->create(['name' => 'Other Signature Tenant', 'slug' => 'other-signature-tenant']);
    $otherViewer = User::factory()->create(['company_id' => $otherCompany->id, 'group' => 'user']);
    grantDocumentControlRole($otherViewer, 'other_signature_viewer', [
        'admin.access',
        'documents.signature.view',
    ]);
    resetDocumentControlAuth();
    $this->getJson(
        "/api/v1/admin/letter-signatures/{$signatureId}/assets/signature",
        bearerHeaders(documentControlToken($otherViewer)),
    )->assertNotFound();

    $category = LetterCategory::query()->create([
        'company_id' => $company->id,
        'code' => 'SIG',
        'name' => 'Signed Document',
    ]);
    $letter = Letter::query()->create([
        'company_id' => $company->id,
        'category_id' => $category->id,
        'signer_id' => $signatureId,
        'subject' => 'Private PDF Signature',
        'body_html' => '<p>Private signature rendering</p>',
        'letter_date' => now()->toDateString(),
        'author_id' => $maker->id,
        'status' => Letter::STATUS_DRAFT,
    ]);
    resetDocumentControlAuth();
    $pdf = $this->get("/api/v1/admin/letters/{$letter->public_id}/preview-pdf", $makerHeaders)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $pdfBytes = $pdf->getContent();
    expect($pdfBytes)->toStartWith('%PDF');

    $pdfService = app(LetterPdfService::class);
    $buildPdf = new ReflectionMethod($pdfService, 'buildPdf');
    $pdfWrapper = $buildPdf->invoke($pdfService, $letter->fresh(['signer']));
    $pdfWrapper->render();
    $pdfObjects = $pdfWrapper->getDomPDF()->getCanvas()->get_cpdf()->objects;
    $imageObjects = array_filter(
        $pdfObjects,
        fn (array $object): bool => ($object['t'] ?? null) === 'image',
    );
    expect(count($imageObjects))->toBeGreaterThanOrEqual(2)
        ->and(count(array_filter($imageObjects, fn (array $object): bool => isset($object['data']))))
        ->toBeGreaterThanOrEqual(2);

    Storage::disk('local')->delete($signature->stamp_path);
    expect(fn () => $pdfService->renderToString($letter->fresh(['signer'])))
        ->toThrow(RuntimeException::class, 'Aset tanda tangan privat tidak ditemukan.');
    Storage::disk('local')->put(
        $signature->stamp_path,
        UploadedFile::fake()->image('restored-stamp.png', 80, 80)->getContent(),
    );

    $originalSignaturePath = $signature->signature_path;
    $crossTenantPath = 'private/letters/signatures/'.$otherCompany->id.'/foreign.png';
    Storage::disk('local')->put(
        $crossTenantPath,
        UploadedFile::fake()->image('foreign.png', 80, 40)->getContent(),
    );
    DB::table('letter_signatures')->where('id', $signatureId)->update(['signature_path' => $crossTenantPath]);
    expect(fn () => $pdfService->renderToString($letter->fresh(['signer'])))
        ->toThrow(RuntimeException::class, 'Lokasi aset tanda tangan bukan penyimpanan privat tenant.');
    DB::table('letter_signatures')->where('id', $signatureId)->update(['signature_path' => $originalSignaturePath]);

    Storage::disk('local')->put($originalSignaturePath, 'not-an-image');
    expect(fn () => $pdfService->renderToString($letter->fresh(['signer'])))
        ->toThrow(RuntimeException::class, 'Format aset tanda tangan privat tidak valid.');
    $this->get('/storage/'.$signature->signature_path)->assertNotFound();
    $this->get('/storage/'.$signature->stamp_path)->assertNotFound();
});

it('rejects cross-tenant references throughout document letter and signature controls', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $otherCompany = Company::query()->create(['name' => 'Other Tenant', 'slug' => 'other-tenant-controls']);
    $controller = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($controller, 'cross_tenant_control_test', [
        'admin.access',
        'documents.repository.manage',
        'documents.letter.view',
        'documents.letter.create',
        'documents.letter.manage',
        'documents.category.manage',
        'documents.template.view',
        'documents.template.manage',
        'documents.signature.prepare',
        'finance.company_bank.manage',
    ]);
    $headers = bearerHeaders(documentControlToken($controller));

    $ownCategory = LetterCategory::query()->create([
        'company_id' => 1,
        'code' => 'OWN',
        'name' => 'Own Tenant Category',
    ]);
    $otherCategory = LetterCategory::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'code' => 'OTHER',
        'name' => 'Other Tenant Category',
    ]);
    $otherTemplate = LetterTemplate::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'category_id' => $otherCategory->id,
        'code' => 'OTHER',
        'name' => 'Other Tenant Template',
        'body_html' => '<p>Other</p>',
    ]);
    $otherBank = CompanyBank::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'bank_name' => 'Other Bank',
        'account_number' => '11112222',
        'account_name' => 'Other Tenant',
    ]);
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id, 'group' => 'user']);
    $otherSignature = LetterSignature::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'user_id' => $otherUser->id,
        'name' => 'Other Signer',
        'title' => 'Director',
        'is_active' => true,
    ]);

    $this->putJson("/api/v1/admin/company-banks/{$otherBank->id}", [
        'bank_name' => 'Forbidden Update',
    ], $headers)->assertNotFound();
    $this->putJson("/api/v1/admin/letter-categories/{$otherCategory->id}", [
        'name' => 'Forbidden Update',
    ], $headers)->assertNotFound();
    $this->getJson("/api/v1/admin/letter-templates/{$otherTemplate->id}", $headers)->assertNotFound();

    $this->postJson('/api/v1/admin/letters', [
        'category_id' => $otherCategory->id,
        'template_id' => $otherTemplate->id,
        'signer_id' => $otherSignature->id,
        'cosigner_id' => $otherSignature->id,
        'bank_id' => $otherBank->id,
        'subject' => 'Cross Tenant Letter',
        'body_html' => '<p>Forbidden</p>',
        'letter_date' => now()->toDateString(),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors([
        'category_id', 'template_id', 'signer_id', 'cosigner_id', 'bank_id',
    ]);

    $this->postJson('/api/v1/admin/documents', [
        'title' => 'Cross Tenant Owner',
        'category' => 'policy',
        'owner_user_id' => $otherUser->id,
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');

    $this->postJson('/api/v1/admin/letter-signatures', [
        'user_id' => $otherUser->id,
        'name' => 'Cross Tenant User',
        'title' => 'Director',
        'default_for_categories' => [$otherCategory->id],
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors([
        'user_id', 'default_for_categories.0',
    ]);

    $letter = Letter::query()->create([
        'company_id' => 1,
        'category_id' => $ownCategory->id,
        'subject' => 'Own Letter',
        'body_html' => '<p>Own</p>',
        'letter_date' => now()->toDateString(),
        'author_id' => $controller->id,
        'status' => Letter::STATUS_DRAFT,
    ]);
    $this->postJson("/api/v1/admin/letters/{$letter->public_id}/dispositions", [
        'to_user_id' => $otherUser->id,
        'action_type' => 'untuk_diketahui',
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('to_user_id');
});

it('excludes cross-tenant category joins from analytics', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $otherCompany = Company::query()->create(['name' => 'Analytics Other', 'slug' => 'analytics-other']);
    $viewer = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($viewer, 'analytics_tenant_viewer', [
        'admin.access',
        'documents.analytics.view',
    ]);
    $ownCategory = LetterCategory::query()->create([
        'company_id' => 1,
        'code' => 'ANOWN',
        'name' => 'Own Analytics',
    ]);
    $otherCategory = LetterCategory::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'code' => 'ANOTHER',
        'name' => 'Other Analytics',
    ]);

    foreach ([[$ownCategory, 'OWN/1'], [$otherCategory, 'CROSS/1']] as [$category, $number]) {
        Letter::query()->withoutGlobalScopes()->create([
            'company_id' => 1,
            'category_id' => $category->id,
            'letter_number' => $number,
            'subject' => 'Analytics Letter',
            'body_html' => '<p>Analytics</p>',
            'letter_date' => now()->toDateString(),
            'author_id' => $viewer->id,
            'status' => Letter::STATUS_DRAFT,
        ]);
    }

    $response = $this->getJson(
        '/api/v1/admin/letters-analytics/dashboard',
        bearerHeaders(documentControlToken($viewer)),
    )->assertOk();
    $codes = collect($response->json('data.by_category'))->pluck('code')->all();

    expect($codes)->toContain('ANOWN')->not->toContain('ANOTHER');
});

it('does not mutate letter settings on GET but persists an explicit update', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $controller = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($controller, 'letter_setting_controller', [
        'admin.access',
        'documents.settings.view',
        'documents.settings.manage',
    ]);
    $headers = bearerHeaders(documentControlToken($controller));
    LetterSetting::query()->withoutGlobalScopes()->where('company_id', 1)->delete();

    $this->getJson('/api/v1/admin/letter-settings', $headers)
        ->assertOk()
        ->assertJsonPath('data.brand_name', 'IBE REALITY');
    expect(LetterSetting::query()->withoutGlobalScopes()->where('company_id', 1)->count())->toBe(0);

    $this->postJson('/api/v1/admin/letter-settings', [
        'brand_name' => 'Persisted Brand',
    ], $headers)->assertOk()->assertJsonPath('data.brand_name', 'Persisted Brand');
    expect(LetterSetting::query()->withoutGlobalScopes()->where('company_id', 1)->count())->toBe(1);
});

it('automatically provisions idempotent enterprise roles for a new tenant', function () {
    $company = Company::query()->create([
        'name' => 'Provisioned Tenant',
        'slug' => 'provisioned-tenant',
    ]);

    $roleId = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'document_controller')
        ->value('id');
    $capabilities = DB::table('rbac_role_capability as rc')
        ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
        ->where('rc.role_id', $roleId)
        ->pluck('c.capability_key')
        ->all();

    expect($roleId)->not->toBeNull()
        ->and($capabilities)->toContain('documents.repository.manage')
        ->toContain('documents.signature.prepare')
        ->not->toContain('documents.signature.approve');

    $before = [
        DB::table('rbac_roles')->where('company_id', $company->id)->count(),
        DB::table('rbac_capabilities')->where('company_id', $company->id)->count(),
        DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.company_id', $company->id)
            ->count(),
    ];
    app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id);
    app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id);
    $after = [
        DB::table('rbac_roles')->where('company_id', $company->id)->count(),
        DB::table('rbac_capabilities')->where('company_id', $company->id)->count(),
        DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.company_id', $company->id)
            ->count(),
    ];

    expect($after)->toBe($before);
});

it('seals one document-control RBAC catalog across config and provisioning', function () {
    EnterpriseDocumentRbacV1::assertValid();

    $catalog = config('enterprise_roles.roles');
    $distinctCapabilities = collect($catalog)
        ->flatMap(fn (array $role): array => $role[1])
        ->unique()
        ->values();

    expect(EnterpriseDocumentRbacV1::VERSION)->toBe('enterprise-document-rbac-v1')
        ->and(EnterpriseDocumentRbacV1::canonicalSha256())
        ->toBe('32de258c8cb007072889e7b5442186591ddbb746ad27b2972d4b27a8fb3b6429')
        ->and(EnterpriseDocumentRbacV1::CAPABILITIES)->toHaveCount(20)
        ->and(collect(EnterpriseDocumentRbacV1::CAPABILITIES)->sum(
            fn (array $metadata): int => count($metadata['roles']),
        ))->toBe(87)
        ->and(EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)->toHaveCount(11)
        ->and($catalog)->toHaveCount(33)
        ->and($distinctCapabilities)->toHaveCount(107)
        ->and(collect($catalog)->sum(fn (array $role): int => count($role[1])))->toBe(245);

    $labels = [];
    foreach ($catalog as $roleKey => [$label, $capabilities]) {
        expect($roleKey)->toMatch('/^[a-z][a-z0-9_]*$/D')
            ->and($label)->not->toBeEmpty()
            ->and($capabilities)->toHaveCount(count(array_unique($capabilities)));
        $labels[] = $label;
        foreach ($capabilities as $capability) {
            expect($capability)->toMatch('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D');
        }
    }
    expect($labels)->toHaveCount(count(array_unique($labels)));

    foreach (EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES as $roleKey) {
        $capabilities = $catalog[$roleKey][1];
        $documentCapabilities = array_values(array_filter(
            $capabilities,
            fn (string $capability): bool => isset(EnterpriseDocumentRbacV1::CAPABILITIES[$capability]),
        ));

        expect($catalog[$roleKey][0])->toBe(EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS[$roleKey])
            ->and(array_keys($capabilities, 'admin.access', true))->toHaveCount(1)
            ->and($documentCapabilities)->toBe(EnterpriseDocumentRbacV1::capabilitiesForRole($roleKey));
    }

    expect($catalog['document_controller'][0])
        ->toBe(EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['label']);

    foreach ($catalog as $roleKey => [, $capabilities]) {
        $ownedCapabilities = array_values(array_filter(
            $capabilities,
            fn (string $capability): bool => EnterpriseDocumentRbacV1::ownsCapabilityNamespace($capability),
        ));
        $expected = in_array($roleKey, EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES, true)
            ? EnterpriseDocumentRbacV1::capabilitiesForRole($roleKey)
            : [];

        expect($ownedCapabilities)->toBe($expected);
    }
});

it('rejects unknown owned-namespace capabilities before provisioning writes', function () {
    $originalCatalog = config('enterprise_roles.roles');
    $catalog = $originalCatalog;
    $catalog['security_admin'][1][] = 'documents.unsealed.manage';
    config()->set('enterprise_roles.roles', $catalog);
    $before = [
        DB::table('rbac_roles')->where('company_id', 1)->count(),
        DB::table('rbac_capabilities')->where('company_id', 1)->count(),
        DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.company_id', 1)
            ->count(),
    ];

    try {
        expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany(1))
            ->toThrow(LogicException::class, 'The enterprise document-control namespace is invalid.');
    } finally {
        config()->set('enterprise_roles.roles', $originalCatalog);
    }
    expect([
        DB::table('rbac_roles')->where('company_id', 1)->count(),
        DB::table('rbac_capabilities')->where('company_id', 1)->count(),
        DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.company_id', 1)
            ->count(),
    ])->toBe($before);
});

it('normalizes migrated and newly provisioned document-control RBAC metadata and grants', function () {
    $company = Company::query()->create([
        'name' => 'RBAC Parity Tenant',
        'slug' => 'rbac-parity-tenant',
    ]);

    $snapshot = function () use ($company): array {
        $metadata = DB::table('rbac_capabilities')
            ->where('company_id', $company->id)
            ->whereIn('capability_key', [
                EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key'],
                ...array_keys(EnterpriseDocumentRbacV1::CAPABILITIES),
            ])
            ->orderBy('capability_key')
            ->get(['capability_key', 'module', 'description'])
            ->map(fn (object $row): array => [
                'capability_key' => $row->capability_key,
                'module' => $row->module,
                'description' => $row->description,
            ])
            ->all();

        $domainGrants = DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('r.company_id', $company->id)
            ->whereIn('r.key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->whereIn('c.capability_key', array_keys(EnterpriseDocumentRbacV1::CAPABILITIES))
            ->orderBy('r.key')
            ->orderBy('c.capability_key')
            ->get(['r.key as role_key', 'c.capability_key'])
            ->map(fn (object $row): string => $row->role_key.'|'.$row->capability_key)
            ->all();

        $adminRoles = DB::table('rbac_role_capability as rc')
            ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('r.company_id', $company->id)
            ->where('c.capability_key', EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key'])
            ->whereIn('r.key', EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES)
            ->orderBy('r.key')
            ->pluck('r.key')
            ->all();

        $documentController = (array) DB::table('rbac_roles')
            ->where('company_id', $company->id)
            ->where('key', EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE['key'])
            ->first(['key', 'label', 'description', 'is_builtin']);
        $documentController['is_builtin'] = (bool) $documentController['is_builtin'];

        return compact('metadata', 'domainGrants', 'adminRoles', 'documentController');
    };

    $expectedMetadata = collect([
        EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key'] => EnterpriseDocumentRbacV1::ADMIN_ACCESS,
        ...EnterpriseDocumentRbacV1::CAPABILITIES,
    ])->map(function (array $metadata, string $capability): array {
        return [
            'capability_key' => $capability,
            'module' => $metadata['module'],
            'description' => $metadata['description'],
        ];
    })->sortBy('capability_key')->values()->all();

    $expectedDomainGrants = collect(EnterpriseDocumentRbacV1::CAPABILITIES)
        ->flatMap(fn (array $metadata, string $capability) => collect($metadata['roles'])
            ->map(fn (string $role): string => $role.'|'.$capability))
        ->sort()
        ->values()
        ->all();
    $expectedAdminRoles = EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLES;
    sort($expectedAdminRoles, SORT_STRING);
    $expected = [
        'metadata' => $expectedMetadata,
        'domainGrants' => $expectedDomainGrants,
        'adminRoles' => $expectedAdminRoles,
        'documentController' => EnterpriseDocumentRbacV1::DOCUMENT_CONTROLLER_ROLE,
    ];

    expect($snapshot())->toBe($expected);

    $customRoleId = DB::table('rbac_roles')->insertGetId([
        'company_id' => $company->id,
        'key' => 'tenant_custom_document_reviewer',
        'label' => 'Tenant Custom Document Reviewer',
        'description' => 'Tenant-managed custom role.',
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customCapabilityId = DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'documents.letter.view')
        ->value('id');
    DB::table('rbac_role_capability')->insert([
        'role_id' => $customRoleId,
        'capability_id' => $customCapabilityId,
    ]);

    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->update(['module' => 'security', 'description' => null]);
    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'documents.letter.view')
        ->update(['module' => 'stale', 'description' => null]);
    DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'document_controller')
        ->update(['description' => 'stale']);
    $financeRoleIds = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->whereIn('key', ['finance_controller', 'accountant', 'treasury_manager', 'treasury_officer'])
        ->pluck('id');
    $adminCapabilityId = DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->value('id');
    DB::table('rbac_role_capability')
        ->whereIn('role_id', $financeRoleIds)
        ->where('capability_id', $adminCapabilityId)
        ->delete();

    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    $migration->up();
    $migration->up();

    expect($snapshot())->toBe($expected);
    $migration->down();
    expect($snapshot())->toBe($expected)
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $customRoleId)
            ->where('capability_id', $customCapabilityId)
            ->count())->toBe(1);
});

it('bootstraps the sealed canonical roles for a company with no RBAC rows', function () {
    $company = Company::withoutEvents(fn () => Company::query()->create([
        'name' => 'Fresh RBAC Tenant',
        'slug' => 'fresh-rbac-tenant',
    ]));
    expect(DB::table('rbac_roles')->where('company_id', $company->id)->count())->toBe(0);

    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    $migration->up();
    $migration->up();

    $roles = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->orderBy('key')
        ->get(['key', 'label', 'is_builtin']);
    $expectedRoles = collect(EnterpriseDocumentRbacV1::ADMIN_SURFACE_ROLE_LABELS)
        ->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'is_builtin' => true,
        ])
        ->sortBy('key')
        ->values()
        ->all();
    $actualRoles = $roles->map(fn (object $role): array => [
        'key' => $role->key,
        'label' => $role->label,
        'is_builtin' => (bool) $role->is_builtin,
    ])->all();

    $domainGrantCount = DB::table('rbac_role_capability as rc')
        ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
        ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
        ->where('r.company_id', $company->id)
        ->whereIn('c.capability_key', array_keys(EnterpriseDocumentRbacV1::CAPABILITIES))
        ->count();
    $adminGrantCount = DB::table('rbac_role_capability as rc')
        ->join('rbac_roles as r', 'r.id', '=', 'rc.role_id')
        ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
        ->where('r.company_id', $company->id)
        ->where('c.capability_key', 'admin.access')
        ->count();

    expect($actualRoles)->toBe($expectedRoles)
        ->and($domainGrantCount)->toBe(87)
        ->and($adminGrantCount)->toBe(11);
});

it('rolls back document-control normalization when a canonical role is missing', function () {
    $company = Company::query()->create([
        'name' => 'Incomplete RBAC Tenant',
        'slug' => 'incomplete-rbac-tenant',
    ]);
    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->update(['module' => 'stale', 'description' => null]);
    DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'document_controller')
        ->update(['description' => 'stale']);
    DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'finance_controller')
        ->delete();

    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Canonical document-control role identity is invalid.');

    expect(DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->value('module'))->toBe('stale')
        ->and(DB::table('rbac_roles')
            ->where('company_id', $company->id)
            ->where('key', 'document_controller')
            ->value('description'))->toBe('stale');
});

it('rejects an unexpected document grant on a reserved built-in role', function () {
    $company = Company::query()->create([
        'name' => 'Rogue Grant Tenant',
        'slug' => 'rogue-grant-tenant',
    ]);
    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->update(['module' => 'stale', 'description' => null]);
    $roleId = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'document_controller')
        ->value('id');
    $capabilityId = DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'documents.letter.approve')
        ->value('id');
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $capabilityId,
    ]);

    expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id))
        ->toThrow(LogicException::class, 'Unexpected canonical document-control grant exists.');
    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Unexpected canonical document-control grant exists.');

    expect(DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->value('module'))->toBe('stale')
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $roleId)
            ->where('capability_id', $capabilityId)
            ->count())->toBe(1);
});

it('rejects an owned capability grant on any other built-in role', function () {
    $company = Company::query()->create([
        'name' => 'Wrong Built-in Grant Tenant',
        'slug' => 'wrong-builtin-grant-tenant',
    ]);
    $roleId = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'hr_manager')
        ->value('id');
    $capabilityId = DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'documents.repository.view')
        ->value('id');
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $capabilityId,
    ]);

    expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id))
        ->toThrow(LogicException::class, 'Unexpected canonical document-control grant exists.');
    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Unexpected canonical document-control grant exists.');
});

it('rejects a global owned capability grant on a tenant built-in role', function () {
    $company = Company::query()->create([
        'name' => 'Global Capability Collision Tenant',
        'slug' => 'global-capability-collision-tenant',
    ]);
    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->update(['module' => 'stale', 'description' => null]);
    $roleId = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'document_controller')
        ->value('id');
    $globalCapabilityId = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => null,
        'module' => 'documents',
        'capability_key' => 'documents.unsealed.view',
        'description' => 'Global collision fixture.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $globalCapabilityId,
    ]);

    expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id))
        ->toThrow(LogicException::class, 'Cross-company document-control grant exists.');
    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Cross-company document-control grant exists.');

    expect(DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->value('module'))->toBe('stale')
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $roleId)
            ->where('capability_id', $globalCapabilityId)
            ->count())->toBe(1);
});

it('rejects a global admin-surface grant on a tenant built-in role', function () {
    $company = Company::query()->create([
        'name' => 'Global Admin Collision Tenant',
        'slug' => 'global-admin-collision-tenant',
    ]);
    $roleId = DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'finance_controller')
        ->value('id');
    $globalCapabilityId = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => null,
        'module' => 'admin',
        'capability_key' => 'admin.access',
        'description' => 'Global admin collision fixture.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('rbac_role_capability')->insert([
        'role_id' => $roleId,
        'capability_id' => $globalCapabilityId,
    ]);

    expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id))
        ->toThrow(LogicException::class, 'Cross-company document-control grant exists.');
    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Cross-company document-control grant exists.')
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $roleId)
            ->where('capability_id', $globalCapabilityId)
            ->count())->toBe(1);
});

it('rejects a tenant-defined collision with a reserved document-control role', function () {
    $company = Company::query()->create([
        'name' => 'Colliding RBAC Tenant',
        'slug' => 'colliding-rbac-tenant',
    ]);
    DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->update(['module' => 'stale', 'description' => null]);
    DB::table('rbac_roles')
        ->where('company_id', $company->id)
        ->where('key', 'accountant')
        ->update(['label' => 'Tenant Custom Accountant', 'is_builtin' => false]);

    expect(fn () => app(EnterpriseRbacProvisioningService::class)->provisionCompany($company->id))
        ->toThrow(LogicException::class, 'A reserved enterprise role identity is invalid.');

    $migration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Canonical document-control role identity is invalid.');

    expect(DB::table('rbac_capabilities')
        ->where('company_id', $company->id)
        ->where('capability_key', 'admin.access')
        ->value('module'))->toBe('stale')
        ->and((bool) DB::table('rbac_roles')
            ->where('company_id', $company->id)
            ->where('key', 'accountant')
            ->value('is_builtin'))->toBeFalse();
});

it('keeps additive document capability and approval metadata migrations non-destructive', function () {
    $capabilityMigration = require database_path('migrations/2026_08_08_000001_seed_document_control_capabilities.php');
    $signatureMigration = require database_path('migrations/2026_08_08_000002_add_approval_metadata_to_letter_signatures.php');

    $capabilityMigration->up();
    $capabilityMigration->up();
    $signatureMigration->up();
    $signatureMigration->up();

    $before = [
        'roles' => DB::table('rbac_roles')->count(),
        'capabilities' => DB::table('rbac_capabilities')->count(),
        'grants' => DB::table('rbac_role_capability')->count(),
    ];
    $capabilityMigration->down();
    $signatureMigration->down();

    expect([
        'roles' => DB::table('rbac_roles')->count(),
        'capabilities' => DB::table('rbac_capabilities')->count(),
        'grants' => DB::table('rbac_role_capability')->count(),
    ])->toBe($before)
        ->and(DB::getSchemaBuilder()->hasColumn('letter_signatures', 'prepared_by_id'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('letter_signatures', 'approved_by_id'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('letter_signatures', 'approved_at'))->toBeTrue();
});

it('keeps legacy admin compatibility outside enforce mode', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'admin']);
    $headers = bearerHeaders($token);

    $this->postJson('/api/v1/admin/company-banks', [], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_name', 'account_number', 'account_name']);
    $this->postJson('/api/v1/admin/letter-signatures', [
        'user_id' => $admin->id,
        'name' => 'Legacy Active Signer',
        'title' => 'Director',
        'is_company_default' => true,
    ], $headers)->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_company_default', true);
});

it('sanitizes authorized template HTML with the directly required purifier engine', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $controller = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    grantDocumentControlRole($controller, 'template_sanitizer_controller', [
        'admin.access',
        'documents.template.manage',
    ]);
    $category = LetterCategory::query()->create([
        'company_id' => 1,
        'code' => 'SAFE',
        'name' => 'Safe Template',
    ]);

    $response = $this->postJson('/api/v1/admin/letter-templates', [
        'category_id' => $category->id,
        'code' => 'SANITIZED',
        'name' => 'Sanitized Template',
        'body_html' => '<p>Safe</p><script>alert(1)</script><img src="javascript:alert(2)" onerror="alert(3)">',
    ], bearerHeaders(documentControlToken($controller)))->assertCreated();

    $body = (string) $response->json('data.body_html');
    expect($body)->toContain('<p>Safe</p>')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onerror')
        ->and((string) json_decode(file_get_contents(base_path('composer.json')), true)['require']['ezyang/htmlpurifier'])
        ->toBe('^4.19');
});

it('scopes custom RBAC reads and mutations to the actor company', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $now = now();
    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Legacy RBAC Tenant',
        'slug' => 'foreign-legacy-rbac-'.str()->ulid(),
    ]);
    expect((int) $foreignCompany->id)->not->toBe(1);

    $localRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'local_custom_role',
        'label' => 'Local Custom Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $localCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => 1,
        'module' => 'custom',
        'capability_key' => 'custom.local.manage',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => $foreignCompany->id,
        'key' => 'foreign_custom_role',
        'label' => 'Foreign Custom Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => $foreignCompany->id,
        'module' => 'custom',
        'capability_key' => 'custom.foreign.manage',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $globalRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => null,
        'key' => 'global_custom_role',
        'label' => 'Global Custom Role',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $globalCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => null,
        'module' => 'custom',
        'capability_key' => 'custom.global.manage',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $initialPivotCount = DB::table('rbac_role_capability')->count();

    $index = $this->getJson('/api/v1/admin/rbac', $headers)->assertOk();
    expect(collect($index->json('data.roles'))->pluck('id')->all())
        ->toContain($localRole)
        ->not->toContain($foreignRole, $globalRole)
        ->and(collect($index->json('data.capabilities'))->pluck('id')->all())
        ->toContain($localCapability)
        ->not->toContain($foreignCapability, $globalCapability);

    foreach ([
        [$foreignRole, $localCapability],
        [$globalRole, $localCapability],
        [$localRole, $foreignCapability],
        [$localRole, $globalCapability],
    ] as [$roleId, $capabilityId]) {
        foreach ([true, false] as $enabled) {
            $this->postJson('/api/v1/admin/rbac/toggle', [
                'role_id' => $roleId,
                'capability_id' => $capabilityId,
                'enabled' => $enabled,
            ], $headers)->assertUnprocessable();
        }
    }

    expect(DB::table('rbac_role_capability')->count())->toBe($initialPivotCount);

    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $localRole,
        'capability_id' => $localCapability,
        'enabled' => true,
    ], $headers)->assertOk();
    expect(DB::table('rbac_role_capability')
        ->where('role_id', $localRole)
        ->where('capability_id', $localCapability)
        ->exists())->toBeTrue();

    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $localRole,
        'capability_id' => $localCapability,
        'enabled' => false,
    ], $headers)->assertOk();
    expect(DB::table('rbac_role_capability')->count())->toBe($initialPivotCount);

    foreach ([$foreignRole, $globalRole] as $roleId) {
        $this->deleteJson("/api/v1/admin/rbac/roles/{$roleId}", [], $headers)->assertNotFound();
    }
    foreach ([$foreignCapability, $globalCapability] as $capabilityId) {
        $this->deleteJson("/api/v1/admin/rbac/capabilities/{$capabilityId}", [], $headers)->assertNotFound();
    }
    expect(DB::table('rbac_roles')->whereIn('id', [$foreignRole, $globalRole])->count())->toBe(2)
        ->and(DB::table('rbac_capabilities')->whereIn('id', [$foreignCapability, $globalCapability])->count())->toBe(2);

    $this->deleteJson("/api/v1/admin/rbac/capabilities/{$localCapability}", [], $headers)->assertOk();
    $this->deleteJson("/api/v1/admin/rbac/roles/{$localRole}", [], $headers)->assertOk();
    expect(DB::table('rbac_capabilities')->where('id', $localCapability)->exists())->toBeFalse()
        ->and(DB::table('rbac_roles')->where('id', $localRole)->exists())->toBeFalse();
});

it('keeps the sealed document RBAC catalog immutable through custom endpoints', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    app(EnterpriseRbacProvisioningService::class)->provisionCompany(1);

    $documentController = DB::table('rbac_roles')
        ->where('company_id', 1)
        ->where('key', 'document_controller')
        ->value('id');
    $repositoryManage = DB::table('rbac_capabilities')
        ->where('company_id', 1)
        ->where('capability_key', 'documents.repository.manage')
        ->value('id');
    $letterApprove = DB::table('rbac_capabilities')
        ->where('company_id', 1)
        ->where('capability_key', 'documents.letter.approve')
        ->value('id');
    $adminAccess = DB::table('rbac_capabilities')
        ->where('company_id', 1)
        ->where('capability_key', EnterpriseDocumentRbacV1::ADMIN_ACCESS['capability_key'])
        ->value('id');
    $nonCatalogBuiltIn = DB::table('rbac_roles')
        ->where('company_id', 1)
        ->where('key', 'hr_manager')
        ->value('id');

    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $documentController,
        'capability_id' => $repositoryManage,
        'enabled' => true,
    ], $headers)->assertOk();

    foreach ([
        [$repositoryManage, false],
        [$letterApprove, true],
        [$adminAccess, false],
    ] as [$capabilityId, $enabled]) {
        $this->postJson('/api/v1/admin/rbac/toggle', [
            'role_id' => $documentController,
            'capability_id' => $capabilityId,
            'enabled' => $enabled,
        ], $headers)->assertUnprocessable();
    }

    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $nonCatalogBuiltIn,
        'capability_id' => $repositoryManage,
        'enabled' => true,
    ], $headers)->assertUnprocessable();

    expect(DB::table('rbac_role_capability')
        ->where('role_id', $documentController)
        ->where('capability_id', $repositoryManage)
        ->exists())->toBeTrue()
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $documentController)
            ->where('capability_id', $letterApprove)
            ->exists())->toBeFalse()
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $documentController)
            ->where('capability_id', $adminAccess)
            ->exists())->toBeTrue();

    $this->deleteJson("/api/v1/admin/rbac/capabilities/{$repositoryManage}", [], $headers)
        ->assertUnprocessable();
    expect(DB::table('rbac_capabilities')->where('id', $repositoryManage)->exists())->toBeTrue();

    DB::table('rbac_roles')->where('id', $documentController)->update(['is_builtin' => false]);
    $this->deleteJson("/api/v1/admin/rbac/roles/{$documentController}", [], $headers)
        ->assertUnprocessable();
    expect(DB::table('rbac_roles')->where('id', $documentController)->exists())->toBeTrue();

    $mixedReservedRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'Tenant_Owner',
        'label' => 'Mixed Reserved Role',
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $mixedReservedCapability = DB::table('rbac_capabilities')->insertGetId([
        'company_id' => 1,
        'module' => 'documents',
        'capability_key' => 'Documents.Unsealed.Manage',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->deleteJson("/api/v1/admin/rbac/roles/{$mixedReservedRole}", [], $headers)
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/rbac/capabilities/{$mixedReservedCapability}", [], $headers)
        ->assertUnprocessable();
    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $mixedReservedRole,
        'capability_id' => $mixedReservedCapability,
        'enabled' => true,
    ], $headers)->assertUnprocessable();

    $this->postJson('/api/v1/admin/rbac/capabilities', [
        'module' => 'documents',
        'capability_key' => 'documents.unsealed.manage',
    ], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/admin/rbac/roles', [
        'key' => 'tenant_owner',
        'label' => 'Tenant Owner Replacement',
    ], $headers)->assertUnprocessable();
    foreach (['Tenant_Owner', ' tenant_owner', 'tenant-owner'] as $invalidRoleKey) {
        $this->postJson('/api/v1/admin/rbac/roles', [
            'key' => $invalidRoleKey,
            'label' => 'Invalid Role Key',
        ], $headers)->assertUnprocessable();
    }
    foreach (['Documents.repository.view', 'documents..view', ' documents.repository.view'] as $invalidCapabilityKey) {
        $this->postJson('/api/v1/admin/rbac/capabilities', [
            'module' => 'documents',
            'capability_key' => $invalidCapabilityKey,
        ], $headers)->assertUnprocessable();
    }

    $customRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'custom_document_operator',
        'label' => 'Custom Document Operator',
        'is_builtin' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $customRole,
        'capability_id' => $repositoryManage,
        'enabled' => true,
    ], $headers)->assertOk();
    $this->postJson('/api/v1/admin/rbac/toggle', [
        'role_id' => $customRole,
        'capability_id' => $repositoryManage,
        'enabled' => false,
    ], $headers)->assertOk();

    expect(DB::table('rbac_capabilities')
        ->where('company_id', 1)
        ->where('capability_key', 'documents.unsealed.manage')
        ->exists())->toBeFalse()
        ->and(DB::table('rbac_roles')
            ->where('company_id', 1)
            ->where('key', 'tenant_owner')
            ->count())->toBe(1)
        ->and(DB::table('rbac_role_capability')
            ->where('role_id', $customRole)
            ->where('capability_id', $repositoryManage)
            ->exists())->toBeFalse();
});

it('fails closed when an RBAC actor has no tenant company', function () {
    config()->set('enterprise_rbac.mode', 'shadow');
    [$actor, $token] = adminWithToken(['company_id' => null, 'group' => 'superadmin']);

    $this->getJson('/api/v1/admin/rbac', bearerHeaders($token))->assertForbidden();
    expect(app(EnterpriseAuthorizationService::class)
        ->targetDecision($actor, 'rbac.manage')['reason'])->toBe('tenant_missing');
});

it('rejects cross-company and ambiguous role delegation pivots', function () {
    config()->set('enterprise_rbac.mode', 'enforce');
    $delegator = User::factory()->create(['company_id' => 1, 'group' => 'admin']);
    $delegate = User::factory()->create(['company_id' => 1, 'group' => 'user']);
    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Document Delegation Tenant',
        'slug' => 'foreign-document-delegation-'.str()->ulid(),
    ]);
    expect((int) $foreignCompany->id)->not->toBe(1);
    $now = now();
    $capabilityKey = 'documents.repository.view';
    app(EnterpriseRbacProvisioningService::class)->provisionCompany(1);

    $foreignRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => $foreignCompany->id,
        'key' => 'foreign_document_reader',
        'label' => 'Foreign Document Reader',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $foreignCapability = DB::table('rbac_capabilities')
        ->where('company_id', $foreignCompany->id)
        ->where('capability_key', $capabilityKey)
        ->value('id');
    DB::table('rbac_role_capability')->insert([
        'role_id' => $foreignRole,
        'capability_id' => $foreignCapability,
    ]);
    $delegationId = (string) str()->ulid();
    DB::table('rbac_delegations')->insert([
        'id' => $delegationId,
        'company_id' => 1,
        'delegator_id' => $delegator->id,
        'delegate_id' => $delegate->id,
        'role_id' => $foreignRole,
        'capability_key' => null,
        'valid_from' => $now->copy()->subMinute(),
        'valid_until' => $now->copy()->addHour(),
        'status' => 'active',
        'reason' => 'Cross-company delegation must not authorize.',
        'approved_by' => $delegator->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(app(EnterpriseAuthorizationService::class)->targetDecision($delegate, $capabilityKey)['allowed'])
        ->toBeFalse();

    DB::table('rbac_delegations')->where('id', $delegationId)->delete();
    $localRole = DB::table('rbac_roles')->insertGetId([
        'company_id' => 1,
        'key' => 'local_document_reader',
        'label' => 'Local Document Reader',
        'is_builtin' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $localCapability = DB::table('rbac_capabilities')
        ->where('company_id', 1)
        ->where('capability_key', $capabilityKey)
        ->value('id');
    expect($foreignCapability)->not->toBeNull()
        ->and($localCapability)->not->toBeNull();
    DB::table('rbac_role_capability')->insert([
        'role_id' => $localRole,
        'capability_id' => $localCapability,
    ]);
    $localDelegationId = (string) str()->ulid();
    DB::table('rbac_delegations')->insert([
        'id' => $localDelegationId,
        'company_id' => 1,
        'delegator_id' => $delegator->id,
        'delegate_id' => $delegate->id,
        'role_id' => $localRole,
        'capability_key' => null,
        'valid_from' => $now->copy()->subMinute(),
        'valid_until' => $now->copy()->addHour(),
        'status' => 'active',
        'reason' => 'Same-company role delegation.',
        'approved_by' => $delegator->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    expect(app(EnterpriseAuthorizationService::class)->targetDecision($delegate, $capabilityKey)['allowed'])
        ->toBeTrue();

    DB::table('rbac_delegations')->where('id', $localDelegationId)->delete();
    DB::table('rbac_delegations')->insert([
        'id' => (string) str()->ulid(),
        'company_id' => 1,
        'delegator_id' => $delegator->id,
        'delegate_id' => $delegate->id,
        'role_id' => $localRole,
        'capability_key' => $capabilityKey,
        'valid_from' => $now->copy()->subMinute(),
        'valid_until' => $now->copy()->addHour(),
        'status' => 'active',
        'reason' => 'Ambiguous delegation must not authorize.',
        'approved_by' => $delegator->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    app()->forgetInstance(EnterpriseAuthorizationService::class);
    expect(app(EnterpriseAuthorizationService::class)->targetDecision($delegate, $capabilityKey)['allowed'])
        ->toBeFalse();
});

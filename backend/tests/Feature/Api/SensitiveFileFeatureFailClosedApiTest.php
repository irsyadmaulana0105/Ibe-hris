<?php

use App\Models\BusinessTrip;
use App\Models\Candidate;
use App\Models\ClientVisit;
use App\Models\ContractTemplate;
use App\Models\JobPosting;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetItem;
use App\Models\ProjectProgressEntry;
use App\Models\Shipment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('api', 'sensitive-file-fail-closed');

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('keeps leave and overtime CRUD while rejecting public attachment uploads', function () {
    [$employee, $token] = employeeWithToken(['company_id' => 1]);
    $headers = bearerHeaders($token);

    $this->postJson('/api/v1/leaves', [
        'leave_type' => 'annual',
        'start_date' => now()->addDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'reason' => 'Pengajuan tanpa lampiran publik',
        'attachment' => UploadedFile::fake()->image('leave.jpg'),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('attachment');

    $this->postJson('/api/v1/overtimes', [
        'date' => today()->toDateString(),
        'start_time' => '17:00',
        'end_time' => '19:00',
        'reason' => 'Lembur tanpa lampiran publik',
        'attachment' => UploadedFile::fake()->create('overtime.pdf', 10, 'application/pdf'),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('attachment');

    expect(DB::table('leaves')->where('user_id', $employee->id)->count())->toBe(0)
        ->and(DB::table('overtimes')->where('user_id', $employee->id)->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(config('sensitive_file_features.leave_attachment'))->toBeFalse()
        ->and(config('sensitive_file_features.overtime_attachment'))->toBeFalse()
        ->and((new Leave(['attachment' => 'leaves/legacy.pdf']))->toArray())->not->toHaveKey('attachment')
        ->and((new Overtime([
            'attachment' => 'overtimes/legacy.pdf',
            'check_in_photo' => 'overtimes/in.jpg',
            'check_out_photo' => 'overtimes/out.jpg',
        ]))->toArray())->not->toHaveKeys(['attachment', 'check_in_photo', 'check_out_photo']);
});

it('rejects client visit photos and redacts legacy business trip photo paths', function () {
    [$employee, $employeeToken] = employeeWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/client-visits/in', [
        'date' => today()->toDateString(),
        'notes_in' => 'Kunjungan pelanggan',
        'photo_in' => UploadedFile::fake()->image('visit.jpg'),
    ], bearerHeaders($employeeToken))->assertUnprocessable()->assertJsonValidationErrors('photo_in');

    $trip = BusinessTrip::create([
        'company_id' => 1,
        'user_id' => $employee->id,
        'date' => today()->toDateString(),
        'destination' => 'Jakarta',
        'purpose' => 'Kunjungan pelanggan',
        'check_in_photo' => 'business-trips/private-in-name.jpg',
        'check_out_photo' => 'business-trips/private-out-name.jpg',
    ]);

    [, $adminToken] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    app('auth')->forgetGuards();
    $response = $this->getJson('/api/v1/admin/business-trips', bearerHeaders($adminToken));
    $row = $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $trip->id)
        ->assertJsonPath('data.0.check_in_photo', null)
        ->assertJsonPath('data.0.check_out_photo', null)
        ->assertJsonPath('data.0.check_in_photo_available', false)
        ->assertJsonPath('data.0.check_out_photo_available', false)
        ->assertJsonPath('data.0.photo_upload_enabled', false)
        ->json('data.0');

    expect($row)->not->toContain('business-trips/private-in-name.jpg')
        ->and($row)->not->toContain('business-trips/private-out-name.jpg')
        ->and($trip->toArray())->not->toHaveKeys(['check_in_photo', 'check_out_photo'])
        ->and((new ClientVisit(['photo_in' => 'visits/in.jpg', 'photo_out' => 'visits/out.jpg']))->toArray())
        ->not->toHaveKeys(['photo_in', 'photo_out'])
        ->and(DB::table('client_visits')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects shipment POD and redacts delivery return evidence in every response', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $shipment = $this->postJson('/api/v1/admin/shipments', [
        'customer_name' => 'PT Pengiriman Aman',
        'shipping_address' => 'Jakarta',
        'ship_date' => today()->toDateString(),
        'items' => [['product_name' => 'Barang', 'qty' => 1]],
    ], $headers)->assertCreated();
    $shipmentId = $shipment->json('data.id');

    $this->postJson("/api/v1/admin/shipments/{$shipmentId}/transition", [
        'to' => 'packed',
        'pod_file' => UploadedFile::fake()->image('pod.jpg'),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('pod_file');

    $this->postJson('/api/v1/admin/delivery-returns', [
        'shipment_id' => $shipmentId,
        'return_date' => today()->toDateString(),
        'reason' => 'Barang perlu diperiksa',
        'evidence' => UploadedFile::fake()->image('return.jpg'),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('evidence');

    $created = $this->postJson('/api/v1/admin/delivery-returns', [
        'shipment_id' => $shipmentId,
        'return_date' => today()->toDateString(),
        'reason' => 'Barang perlu diperiksa',
    ], $headers)->assertCreated()
        ->assertJsonMissingPath('data.evidence_path')
        ->assertJsonPath('data.evidence_available', false)
        ->assertJsonPath('data.evidence_upload_enabled', false);
    $returnId = $created->json('data.id');
    DB::table('delivery_returns')->where('id', $returnId)->update(['evidence_path' => 'returns/legacy-secret.jpg']);

    $this->getJson('/api/v1/admin/delivery-returns', $headers)
        ->assertOk()
        ->assertJsonMissingPath('data.data.0.evidence_path')
        ->assertJsonPath('data.data.0.evidence_available', false);
    $this->postJson("/api/v1/admin/delivery-returns/{$returnId}/review", [], $headers)
        ->assertOk()
        ->assertJsonMissingPath('data.evidence_path')
        ->assertJsonPath('data.evidence_available', false);

    expect(DB::table('shipments')->find($shipmentId)->status)->toBe('draft')
        ->and(Shipment::findOrFail($shipmentId)->toArray())->not->toHaveKeys(['pod_file_path', 'pod_signature_path'])
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('prohibits recruitment CV URLs and never serializes a legacy value', function () {
    [, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    $job = JobPosting::create(['company_id' => 1, 'title' => 'Engineer']);

    $this->postJson('/api/v1/admin/recruitment/candidates', [
        'job_posting_id' => $job->id,
        'name' => 'Pelamar Baru',
        'email' => 'candidate@example.test',
        'cv_url' => 'https://attacker.example/cv.pdf',
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('cv_url');

    $candidate = Candidate::create([
        'company_id' => 1,
        'job_posting_id' => $job->id,
        'name' => 'Pelamar Lama',
        'email' => 'legacy@example.test',
        'cv_url' => 'https://legacy.example/private-cv.pdf',
    ]);
    $row = $this->getJson('/api/v1/admin/recruitment/candidates', $headers)
        ->assertOk()->json('data.0');
    expect($row)->not->toHaveKey('cv_url');

    $this->putJson("/api/v1/admin/recruitment/candidates/{$candidate->id}", [
        'cv_url' => 'https://attacker.example/replacement.pdf',
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('cv_url');
    $this->postJson('/api/v1/admin/recruitment/candidates/parse-cv', [], $headers)
        ->assertStatus(503)->assertJsonPath('feature_enabled', false);
    $this->postJson("/api/v1/admin/recruitment/candidates/{$candidate->id}/issue-offer", [], $headers)
        ->assertStatus(503)->assertJsonPath('feature_enabled', false);

    expect(Candidate::count())->toBe(1)
        ->and($candidate->fresh()->cv_url)->toBe('https://legacy.example/private-cv.pdf')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps text contract templates available while fail-closing legacy DOCX paths', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $headers = bearerHeaders($token);
    Storage::disk('public')->put('contract-templates/legacy.docx', 'legacy-docx');
    $template = ContractTemplate::create([
        'company_id' => 1,
        'employment_type' => 'permanent',
        'title' => 'Kontrak Lama',
        'body_html' => '<p>Isi kontrak</p>',
        'source_type' => 'docx',
        'source_docx_path' => 'contract-templates/legacy.docx',
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);

    $this->getJson('/api/v1/admin/contract-templates/permanent', $headers)
        ->assertOk()
        ->assertJsonMissingPath('data.template.source_docx_path')
        ->assertJsonPath('data.template.source_docx_url', null)
        ->assertJsonPath('data.template.source_docx_upload_enabled', false);
    $this->postJson('/api/v1/admin/contract-templates/upload-docx', [], $headers)->assertStatus(503);
    $this->postJson('/api/v1/admin/contract-templates', [
        'employment_type' => 'permanent',
        'title' => 'Kontrak Baru',
        'body_html' => '<p>Isi baru</p>',
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('source_docx');
    $this->deleteJson('/api/v1/admin/contract-templates/permanent', [], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('source_docx');

    expect(ContractTemplate::find($template->id))->not->toBeNull()
        ->and(ContractTemplate::find($template->id)?->toArray())->not->toHaveKey('source_docx_path')
        ->and(Storage::disk('public')->exists('contract-templates/legacy.docx'))->toBeTrue();
});

it('prohibits project progress evidence URLs and hides legacy model values', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1, 'group' => 'superadmin']);
    $project = Project::create(['company_id' => 1, 'name' => 'RAB Aman', 'status' => 'active', 'created_by' => $admin->id]);
    $project->members()->create(['user_id' => $admin->id, 'role' => 'owner']);
    $budget = ProjectBudget::create(['company_id' => 1, 'project_id' => $project->id]);
    $item = ProjectBudgetItem::create([
        'company_id' => 1,
        'project_budget_id' => $budget->id,
        'code' => 'SEC-01',
        'category' => 'other',
        'description' => 'Uji evidence',
        'unit' => 'paket',
        'volume' => 1,
        'unit_price' => 1,
    ]);

    $this->postJson("/api/v1/projects/{$project->id}/budget/items/{$item->id}/progress", [
        'entry_date' => today()->toDateString(),
        'planned_percent' => 10,
        'actual_percent' => 10,
        'actual_cost' => 1,
        'evidence_url' => 'https://attacker.example/evidence.jpg',
    ], bearerHeaders($token))->assertUnprocessable()->assertJsonValidationErrors('evidence_url');

    $entry = ProjectProgressEntry::create([
        'company_id' => 1,
        'project_id' => $project->id,
        'budget_item_id' => $item->id,
        'entry_date' => today(),
        'evidence_url' => 'https://legacy.example/evidence.jpg',
        'created_by' => $admin->id,
    ]);

    expect(ProjectProgressEntry::count())->toBe(1)
        ->and($entry->toArray())->not->toHaveKey('evidence_url');
});

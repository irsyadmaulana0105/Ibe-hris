<?php

use App\Models\Project;
use App\Models\TaskAttachment;
use App\Services\ProjectAttachmentPrivateCutover;
use App\Services\ProjectAttachmentPrivateStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses()->group('api');

function privateAttachmentProjectFor($user): array
{
    $project = Project::create([
        'company_id' => $user->company_id,
        'name' => 'Private Attachment Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);
    $project->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    $task = $project->tasks()->create([
        'title' => 'Upload evidence',
        'status' => 'todo',
        'priority' => 'medium',
        'created_by' => $user->id,
    ]);

    return [$project, $task];
}

function boundedPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n";
}

function projectAttachmentEvidenceFile(array $contentById = [], array $missingIds = []): string
{
    $entries = app(ProjectAttachmentPrivateCutover::class)->run('evidence')['entries'];
    $expectations = [];
    foreach ($entries as $entry) {
        $id = (int) $entry['attachment_id'];
        if ($entry['state'] !== 'legacy') {
            continue;
        }
        $missing = in_array($id, $missingIds, true);
        $expectations[(string) $id] = [
            'descriptor_sha256' => $entry['descriptor_sha256'],
            'disposition' => $missing ? 'missing' : 'copy',
            ...($missing ? [] : ['content_sha256' => $contentById[$id] ?? hash('sha256', boundedPdf())]),
        ];
    }
    $path = tempnam(sys_get_temp_dir(), 'ibe-project-evidence-');
    if ($path === false || file_put_contents($path, json_encode($expectations, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Unable to create test evidence file.');
    }
    @chmod($path, 0600);

    return $path;
}

function projectAttachmentManifestPath(): string
{
    return 'private/cutovers/'.ProjectAttachmentPrivateCutover::RELEASE.'/manifest.json';
}

function projectAttachmentRuntimeManifestPath(): string
{
    return 'private/cutovers/'.ProjectAttachmentPrivateCutover::RELEASE.'/runtime/runtime-manifest.json';
}

function projectAttachmentRuntimeManifestShaPath(): string
{
    return 'private/cutovers/'.ProjectAttachmentPrivateCutover::RELEASE.'/runtime/runtime-manifest.sha256';
}

function createLegacyPrivateCopy($test, $admin, $project, $task): TaskAttachment
{
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/source.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf',
    ]);
    Http::fake([
        'https://attachments.ibereality.com/projects/source.pdf' => Http::response(
            boundedPdf(),
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $evidence = projectAttachmentEvidenceFile([$attachment->id => hash('sha256', boundedPdf())]);
    try {
        $expectations = json_decode(file_get_contents($evidence), true, flags: JSON_THROW_ON_ERROR);
        ksort($expectations, SORT_STRING);
        $test->artisan('projects:cutover-private-attachments', [
            'phase' => 'copy-private', '--expectations-json' => $evidence, '--apply' => true,
        ])->assertSuccessful();
    } finally {
        @unlink($evidence);
    }
    $manifest = json_decode(
        Storage::disk('local')->get(projectAttachmentManifestPath()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $entry = $manifest['entries'][(string) $attachment->id];
    $runtimeManifest = [
        'schema' => ProjectAttachmentPrivateCutover::RELEASE.'-runtime-v1',
        'expectations_sha256' => hash(
            'sha256',
            json_encode($expectations, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ),
        'entries' => [
            (string) $attachment->id => [
                'attachment_id' => (int) $entry['attachment_id'],
                'tenant_id' => (int) $entry['tenant_id'],
                'project_id' => (int) $entry['project_id'],
                'task_id' => (int) $entry['task_id'],
                'descriptor_sha256' => (string) $entry['descriptor_sha256'],
                'new_path' => (string) $entry['new_path'],
                'new_sha256' => (string) $entry['new_sha256'],
                'size' => (int) $entry['size'],
                'mime' => (string) $entry['mime'],
            ],
        ],
    ];
    $runtimeJson = json_encode(
        $runtimeManifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n";
    Storage::disk('local')->put(projectAttachmentRuntimeManifestPath(), $runtimeJson);
    Storage::disk('local')->put(
        projectAttachmentRuntimeManifestShaPath(),
        hash('sha256', $runtimeJson)."  runtime-manifest.json\n",
    );
    config()->set('project_attachments.legacy_runtime_expected_entries', 1);

    return $attachment->fresh();
}

/** @return array{TaskAttachment,string} */
function createSwitchedLegacyAttachment($test, $admin, $project, $task): array
{
    Storage::fake('local');
    Http::fake([
        'https://attachments.ibereality.com/projects/source.pdf' => Http::response(
            boundedPdf(),
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/source.pdf",
        'storage_provider' => 'r2',
        'drive_file_id' => 'legacy-file-id',
        'drive_web_view_link' => 'https://attachments.ibereality.com/projects/source-view',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf',
    ]);
    $evidence = projectAttachmentEvidenceFile([$attachment->id => hash('sha256', boundedPdf())]);
    $test->artisan('projects:cutover-private-attachments', [
        'phase' => 'copy-private', '--expectations-json' => $evidence, '--apply' => true,
    ])->assertSuccessful();
    $test->artisan('projects:cutover-private-attachments', [
        'phase' => 'switch-private', '--expectations-json' => $evidence, '--apply' => true,
    ])->assertSuccessful();

    return [$attachment->fresh(), $evidence];
}

it('accepts only the two exact HTTPS legacy source hosts without a nonstandard port', function (string $url, bool $accepted) {
    config()->set('project_attachments.legacy_public_hosts', [
        'attachments.ibereality.com',
        'pub-8511300fbe1e44d6a2df86169c6ea895.r2.dev',
    ]);
    $cutover = app(ProjectAttachmentPrivateCutover::class);
    $method = (new ReflectionClass($cutover))->getMethod('assertSupportedSource');
    $row = (object) ['storage_provider' => 'r2', 'drive_download_link' => $url];
    $invoke = static fn () => $method->invoke($cutover, $row);

    $accepted
        ? expect($invoke)->not->toThrow(Throwable::class)
        : expect($invoke)->toThrow(RuntimeException::class);
})->with([
    'custom host' => ['https://attachments.ibereality.com/projects/source.pdf?token=opaque', true],
    'r2 dev host' => ['https://pub-8511300fbe1e44d6a2df86169c6ea895.r2.dev/source.pdf', true],
    'explicit 443' => ['https://attachments.ibereality.com:443/projects/source.pdf', true],
    'http' => ['http://attachments.ibereality.com/projects/source.pdf', false],
    'foreign host' => ['https://foreign.invalid/projects/source.pdf', false],
    'subdomain wildcard' => ['https://evil.attachments.ibereality.com/projects/source.pdf', false],
    'userinfo' => ['https://user@attachments.ibereality.com/projects/source.pdf', false],
    'fragment' => ['https://attachments.ibereality.com/projects/source.pdf#fragment', false],
    'nonstandard port' => ['https://attachments.ibereality.com:8443/projects/source.pdf', false],
]);

it('refuses a legacy source redirect without following it', function () {
    config()->set('project_attachments.legacy_public_hosts', ['attachments.ibereality.com']);
    Http::fake([
        'https://attachments.ibereality.com/projects/source.pdf' => Http::response('', 302, [
            'Location' => 'https://attachments.ibereality.com/projects/other.pdf',
        ]),
    ]);
    $cutover = app(ProjectAttachmentPrivateCutover::class);
    $method = (new ReflectionClass($cutover))->getMethod('downloadSource');
    $row = (object) ['drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf'];

    expect(fn () => $method->invoke($cutover, $row))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('stores project attachments only on tenant scoped private storage', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake('r2');

    $response = $this->withHeaders(bearerHeaders($token))->post(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments",
        ['file' => UploadedFile::fake()->createWithContent('rab-project.pdf', boundedPdf())],
    );

    $response->assertCreated()
        ->assertJsonPath('data.storage_provider', ProjectAttachmentPrivateStorage::PROVIDER)
        ->assertJsonPath('data.original_name', 'rab-project.pdf')
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.content_sha256')
        ->assertJsonMissingPath('data.drive_download_link')
        ->assertJsonMissingPath('data.signed_url');

    $attachment = TaskAttachment::query()->findOrFail($response->json('data.id'));
    expect($attachment->path)
        ->toStartWith("private/tenants/1/project-attachments/projects/{$project->id}/tasks/{$task->id}/")
        ->and($attachment->content_sha256)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($attachment->size_bytes)->toBe(strlen(boundedPdf()));
    Storage::disk('local')->assertExists($attachment->path);
    Storage::disk('public')->assertDirectoryEmpty('task-attachments');
    Storage::disk('r2')->assertDirectoryEmpty('projects');

    $this->getJson(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments",
        bearerHeaders($token),
    )->assertOk()
        ->assertJsonMissingPath('data.0.path')
        ->assertJsonMissingPath('data.0.drive_web_view_link');

    $stream = $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($token),
    );
    $stream->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('cache-control', 'max-age=0, no-store, private')
        ->assertHeader('pragma', 'no-cache')
        ->assertHeader('referrer-policy', 'no-referrer')
        ->assertHeader('x-content-type-options', 'nosniff');
    expect($stream->streamedContent())->toBe(boundedPdf());
});

it('serves an evidence-bound legacy R2 private copy through the authenticated proxy without a source fetch', function () {
    [$owner, $ownerToken] = adminWithToken(['company_id' => 1]);
    [$outsider, $outsiderToken] = employeeWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($owner);
    Storage::fake('local');
    $attachment = createLegacyPrivateCopy($this, $owner, $project, $task);
    $requestCount = count(Http::recorded());

    $this->getJson(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments",
        bearerHeaders($ownerToken),
    )->assertOk()
        ->assertJsonPath('data.0.storage_provider', 'r2')
        ->assertJsonPath('data.0.url', url("/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream"))
        ->assertJsonMissingPath('data.0.path')
        ->assertJsonMissingPath('data.0.drive_download_link')
        ->assertJsonMissingPath('data.0.content_sha256');

    $stream = $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($ownerToken),
    );
    $stream->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($stream->streamedContent())->toBe(boundedPdf())
        ->and(count(Http::recorded()))->toBe($requestCount);

    $this->deleteJson(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}",
        [],
        bearerHeaders($ownerToken),
    )->assertStatus(409);
    $this->assertDatabaseHas('task_attachments', ['id' => $attachment->id, 'storage_provider' => 'r2']);
    Auth::forgetGuards();
    $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($outsiderToken),
    )->assertNotFound();
});

it('fails the legacy private-copy bridge closed on any manifest row or object drift', function (string $mode) {
    [$owner, $token] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($owner);
    Storage::fake('local');
    $attachment = createLegacyPrivateCopy($this, $owner, $project, $task);
    $manifestPath = projectAttachmentRuntimeManifestPath();
    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $entry = &$manifest['entries'][(string) $attachment->id];

    if ($mode === 'missing-manifest') {
        Storage::disk('local')->delete($manifestPath);
    } elseif ($mode === 'missing-sidecar') {
        Storage::disk('local')->delete(projectAttachmentRuntimeManifestShaPath());
    } elseif ($mode === 'missing-entry') {
        unset($manifest['entries'][(string) $attachment->id]);
    } elseif ($mode === 'descriptor') {
        $entry['descriptor_sha256'] = str_repeat('a', 64);
    } elseif ($mode === 'row-descriptor') {
        $attachment->forceFill(['original_name' => 'drifted.pdf'])->save();
    } elseif ($mode === 'content-binding') {
        $entry['new_sha256'] = str_repeat('b', 64);
    } elseif ($mode === 'numeric-string') {
        $entry['attachment_id'] = (string) $entry['attachment_id'];
    } elseif ($mode === 'path-traversal') {
        $entry['new_path'] = dirname($entry['new_path']).'/../escape.pdf';
    } elseif ($mode === 'extra-key') {
        $entry['untrusted'] = true;
    } elseif ($mode === 'oversize-manifest') {
        $manifest['untrusted_padding'] = str_repeat('x', 65536);
    } elseif ($mode === 'manifest-checksum') {
        $entry['copied_at'] = 'tampered';
    } elseif ($mode === 'object') {
        Storage::disk('local')->put($entry['new_path'], boundedPdf().'tampered');
    } elseif ($mode === 'provider') {
        $attachment->forceFill(['storage_provider' => 'foreign'])->save();
    }
    if (! in_array($mode, ['missing-manifest', 'missing-sidecar', 'object', 'provider', 'row-descriptor'], true)) {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        Storage::disk('local')->put($manifestPath, $json);
        if ($mode !== 'manifest-checksum') {
            Storage::disk('local')->put(
                projectAttachmentRuntimeManifestShaPath(),
                hash('sha256', $json)."  runtime-manifest.json\n",
            );
        }
    }
    $requestCount = count(Http::recorded());

    $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($token),
    )->assertStatus(409);
    expect(count(Http::recorded()))->toBe($requestCount);
    $this->assertDatabaseHas('task_attachments', ['id' => $attachment->id]);
})->with([
    'missing-manifest', 'missing-sidecar', 'missing-entry', 'descriptor', 'row-descriptor',
    'content-binding', 'numeric-string', 'path-traversal', 'extra-key', 'oversize-manifest',
    'manifest-checksum', 'object', 'provider',
]);

it('fails closed on tampered or missing private objects and preserves the row', function (string $mode) {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    $meta = app(ProjectAttachmentPrivateStorage::class)->storeBytes(
        boundedPdf(),
        'proof.pdf',
        'application/pdf',
        1,
        $project->id,
        $task->id,
    );
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => basename($meta['path']),
        'original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $meta['size'],
        'path' => $meta['path'],
        'storage_provider' => ProjectAttachmentPrivateStorage::PROVIDER,
        'content_sha256' => $meta['sha256'],
    ]);
    $mode === 'tampered'
        ? Storage::disk('local')->put($meta['path'], boundedPdf().'changed')
        : Storage::disk('local')->delete($meta['path']);

    $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($token),
    )->assertStatus(409);
    $this->deleteJson(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}",
        [],
        bearerHeaders($token),
    )->assertStatus(409);
    $this->assertDatabaseHas('task_attachments', ['id' => $attachment->id]);
})->with(['tampered', 'missing']);

it('deletes a verified private object before removing its row', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    $meta = app(ProjectAttachmentPrivateStorage::class)->storeBytes(
        boundedPdf(), 'proof.pdf', 'application/pdf', 1, $project->id, $task->id,
    );
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => basename($meta['path']),
        'original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $meta['size'],
        'path' => $meta['path'],
        'storage_provider' => ProjectAttachmentPrivateStorage::PROVIDER,
        'content_sha256' => $meta['sha256'],
    ]);

    $this->deleteJson(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}",
        [],
        bearerHeaders($token),
    )->assertOk();
    Storage::disk('local')->assertMissing($meta['path']);
    $this->assertDatabaseMissing('task_attachments', ['id' => $attachment->id]);
});

it('denies non-members without exposing private attachment metadata', function () {
    [$owner, $ownerToken] = adminWithToken(['company_id' => 1]);
    [$outsider, $outsiderToken] = employeeWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($owner);
    Storage::fake('local');
    $meta = app(ProjectAttachmentPrivateStorage::class)->storeBytes(
        boundedPdf(), 'proof.pdf', 'application/pdf', 1, $project->id, $task->id,
    );
    $attachment = $task->attachments()->create([
        'user_id' => $owner->id,
        'filename' => basename($meta['path']),
        'original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $meta['size'],
        'path' => $meta['path'],
        'storage_provider' => ProjectAttachmentPrivateStorage::PROVIDER,
        'content_sha256' => $meta['sha256'],
    ]);

    $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($ownerToken),
    )->assertOk();
    Auth::forgetGuards();
    $this->get(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}/stream",
        bearerHeaders($outsiderToken),
    )->assertNotFound();
});

it('rejects active content and extension spoofing before any private write', function (string $name, string $content) {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');

    $this->withHeaders(bearerHeaders($token))->post(
        "/api/v1/projects/{$project->id}/tasks/{$task->id}/attachments",
        ['file' => UploadedFile::fake()->createWithContent($name, $content)],
    )->assertUnprocessable()->assertJsonValidationErrors('file');

    expect($task->attachments()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('private');
})->with([
    'html' => ['payload.html', '<script>alert(document.domain)</script>'],
    'svg disguised as jpg' => ['invoice.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
]);

it('copies evidence-bound R2 data, tombstones a proven missing source, and restores exact metadata', function () {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    Http::fake([
        'https://attachments.ibereality.com/projects/missing.pdf' => Http::response('', 404),
        'https://attachments.ibereality.com/*' => Http::response(boundedPdf(), 200, ['Content-Type' => 'application/pdf']),
    ]);
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/source.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf',
    ]);
    $missing = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'missing.pdf',
        'original_name' => 'missing.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 321,
        'path' => "projects/{$project->id}/tasks/{$task->id}/missing.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/missing.pdf',
    ]);
    $evidence = projectAttachmentEvidenceFile([$attachment->id => hash('sha256', boundedPdf())], [$missing->id]);

    try {
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'plan', '--expectations-json' => $evidence,
        ])->assertSuccessful();
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'copy-private', '--expectations-json' => $evidence, '--apply' => true,
        ])->assertSuccessful();
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'remediate-missing', '--expectations-json' => $evidence, '--apply' => true,
        ])->assertSuccessful();
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'switch-private', '--expectations-json' => $evidence, '--apply' => true,
        ])->assertSuccessful();
        $this->artisan('projects:cutover-private-attachments', ['phase' => 'verify-private'])->assertSuccessful();

        $attachment->refresh();
        expect($attachment->storage_provider)->toBe(ProjectAttachmentPrivateStorage::PROVIDER)
            ->and($attachment->content_sha256)->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($attachment->drive_download_link)->toBeNull()
            ->and($attachment->drive_web_view_link)->toBeNull();
        Storage::disk('local')->assertExists($attachment->path);
        $missing->refresh();
        expect($missing->storage_provider)->toBe('unavailable')
            ->and($missing->path)->toBe('')
            ->and($missing->source_tombstone_reason)->toBe('verified_source_missing')
            ->and($missing->drive_download_link)->toBeNull();

        $manifestSha256 = hash('sha256', Storage::disk('local')->get(projectAttachmentManifestPath()));
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'restore-source',
            '--expectations-json' => $evidence,
            '--manifest-sha256' => $manifestSha256,
            '--apply' => true,
        ])->assertSuccessful();
        $attachment->refresh();
        expect($attachment->storage_provider)->toBe('r2')
            ->and($attachment->path)->toContain('/source.pdf')
            ->and($attachment->drive_download_link)->toBe('https://attachments.ibereality.com/projects/source.pdf');
        $missing->refresh();
        expect($missing->storage_provider)->toBe('r2')
            ->and($missing->path)->toContain('/missing.pdf')
            ->and($missing->drive_download_link)->toBe('https://attachments.ibereality.com/projects/missing.pdf');
    } finally {
        @unlink($evidence);
    }
});

it('refuses restore-source when any sealed manifest, expectation, legacy field, or current row drifts', function (string $mode) {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    [$attachment, $evidence] = createSwitchedLegacyAttachment($this, $admin, $project, $task);
    $manifestPath = projectAttachmentManifestPath();
    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $entry = &$manifest['entries'][(string) $attachment->id];
    $expectations = json_decode(file_get_contents($evidence), true, flags: JSON_THROW_ON_ERROR);
    $args = [
        'phase' => 'restore-source',
        '--expectations-json' => $evidence,
        '--apply' => true,
    ];

    try {
        if ($mode === 'no-expectations') {
            unset($args['--expectations-json']);
        } elseif ($mode === 'no-manifest-sha') {
            // The checksum option is deliberately left absent.
        } elseif ($mode === 'wrong-manifest-sha') {
            $args['--manifest-sha256'] = str_repeat('a', 64);
        } elseif ($mode === 'missing-manifest-entry') {
            unset($manifest['entries'][(string) $attachment->id]);
        } elseif ($mode === 'extra-manifest-entry') {
            $manifest['entries']['999'] = $entry;
            $manifest['entries']['999']['attachment_id'] = 999;
        } elseif ($mode === 'old-provider') {
            $entry['old_provider'] = 'foreign';
        } elseif ($mode === 'old-path') {
            $entry['old_path'] = 'projects/drifted/source.pdf';
        } elseif ($mode === 'old-file-id') {
            $entry['old_drive_file_id'] = 'drifted-file-id';
        } elseif ($mode === 'old-web-view') {
            $entry['old_drive_web_view_link'] = 'https://attachments.ibereality.com/projects/drifted-view';
        } elseif ($mode === 'old-download') {
            $entry['old_drive_download_link'] = 'https://foreign.invalid/source.pdf';
        } elseif ($mode === 'entry-descriptor') {
            $entry['descriptor_sha256'] = str_repeat('b', 64);
        } elseif ($mode === 'missing-expectation') {
            unset($expectations[(string) $attachment->id]);
            file_put_contents($evidence, json_encode($expectations, JSON_THROW_ON_ERROR));
        } elseif ($mode === 'extra-expectation') {
            $expectations['999'] = $expectations[(string) $attachment->id];
            file_put_contents($evidence, json_encode($expectations, JSON_THROW_ON_ERROR));
        } elseif ($mode === 'wrong-expectation') {
            $expectations[(string) $attachment->id]['descriptor_sha256'] = str_repeat('c', 64);
            file_put_contents($evidence, json_encode($expectations, JSON_THROW_ON_ERROR));
        } elseif ($mode === 'current-row') {
            $attachment->forceFill(['original_name' => 'drifted.pdf'])->save();
        }

        if (! in_array($mode, [
            'no-expectations', 'no-manifest-sha', 'wrong-manifest-sha',
            'missing-expectation', 'extra-expectation', 'wrong-expectation', 'current-row',
        ], true)) {
            $manifestJson = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
            Storage::disk('local')->put($manifestPath, $manifestJson);
        }
        if (! isset($args['--manifest-sha256']) && $mode !== 'no-manifest-sha') {
            $args['--manifest-sha256'] = hash('sha256', Storage::disk('local')->get($manifestPath));
        }

        $this->artisan('projects:cutover-private-attachments', $args)->assertFailed();
        $attachment->refresh();
        expect($attachment->storage_provider)->toBe(ProjectAttachmentPrivateStorage::PROVIDER)
            ->and($attachment->drive_download_link)->toBeNull();
    } finally {
        @unlink($evidence);
    }
})->with([
    'no-expectations', 'no-manifest-sha', 'wrong-manifest-sha',
    'missing-manifest-entry', 'extra-manifest-entry', 'old-provider', 'old-path',
    'old-file-id', 'old-web-view', 'old-download', 'entry-descriptor',
    'missing-expectation', 'extra-expectation', 'wrong-expectation', 'current-row',
]);

it('refuses mutating project cutover phases without apply or complete evidence', function () {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/source.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf',
    ]);

    $this->artisan('projects:cutover-private-attachments', ['phase' => 'copy-private'])
        ->assertFailed();
    $this->artisan('projects:cutover-private-attachments', ['phase' => 'plan'])
        ->assertFailed();
    expect(TaskAttachment::query()->where('storage_provider', 'r2')->count())->toBe(1);
});

it('never emits a legacy signed source URL or storage path on command failure', function () {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    $sentinel = 'sentinel-private-source-token';
    $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/{$sentinel}.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => "https://attachments.ibereality.com/projects/source.pdf?token={$sentinel}",
    ]);

    $status = Artisan::call('projects:cutover-private-attachments', [
        'phase' => 'restore-source', '--apply' => true,
    ]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('Project attachment cutover failed closed.')
        ->not->toContain($sentinel)
        ->not->toContain('projects/source.pdf');
});

it('redacts a database restore failure that contains legacy URL and path bindings', function () {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    [$attachment, $evidence] = createSwitchedLegacyAttachment($this, $admin, $project, $task);
    $sentinelToken = 'sentinel-query-token';
    $sentinelPath = 'private/sentinel-query-path';
    DB::statement(
        'CREATE TRIGGER fail_project_attachment_restore BEFORE UPDATE ON task_attachments '
        ."BEGIN SELECT RAISE(ABORT, 'https://attachments.ibereality.com/source?token={$sentinelToken} {$sentinelPath}'); END",
    );

    try {
        $status = Artisan::call('projects:cutover-private-attachments', [
            'phase' => 'restore-source',
            '--expectations-json' => $evidence,
            '--manifest-sha256' => hash('sha256', Storage::disk('local')->get(projectAttachmentManifestPath())),
            '--apply' => true,
        ]);
        $output = Artisan::output();

        expect($status)->toBe(1)
            ->and($output)->toContain('Project attachment cutover failed closed.')
            ->not->toContain($sentinelToken)
            ->not->toContain($sentinelPath)
            ->not->toContain('attachments.ibereality.com');
        expect($attachment->fresh()->storage_provider)->toBe(ProjectAttachmentPrivateStorage::PROVIDER);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS fail_project_attachment_restore');
        @unlink($evidence);
    }
});

it('fails closed when another project attachment cutover holds the release lock', function () {
    [$admin] = adminWithToken(['company_id' => 1]);
    [$project, $task] = privateAttachmentProjectFor($admin);
    Storage::fake('local');
    $attachment = $task->attachments()->create([
        'user_id' => $admin->id,
        'filename' => 'source.pdf',
        'original_name' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(boundedPdf()),
        'path' => "projects/{$project->id}/tasks/{$task->id}/source.pdf",
        'storage_provider' => 'r2',
        'drive_download_link' => 'https://attachments.ibereality.com/projects/source.pdf',
    ]);
    $evidence = projectAttachmentEvidenceFile([$attachment->id => hash('sha256', boundedPdf())]);
    $lock = Cache::lock('cutover-lock:'.ProjectAttachmentPrivateCutover::RELEASE.':global', 900);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('projects:cutover-private-attachments', [
            'phase' => 'copy-private', '--expectations-json' => $evidence, '--apply' => true,
        ])->assertFailed();
    } finally {
        $lock->release();
        @unlink($evidence);
    }

    $attachment->refresh();
    expect($attachment->storage_provider)->toBe('r2')
        ->and(Storage::disk('local')->allFiles('private/tenants'))->toBe([]);
});

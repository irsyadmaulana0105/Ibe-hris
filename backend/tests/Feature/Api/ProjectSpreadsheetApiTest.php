<?php

use App\Exports\SpreadsheetRowsExport;
use App\Models\Project;
use App\Models\ProjectBudgetItem;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

uses()->group('api');

function spreadsheetUpload(string $name, array $headings, array $rows): UploadedFile
{
    $binary = Excel::raw(new SpreadsheetRowsExport($headings, $rows), ExcelFormat::XLSX);
    return UploadedFile::fake()->createWithContent($name, $binary);
}

it('exports and imports project tasks as excel', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $project = Project::create(['company_id' => 1, 'name' => 'Excel Project', 'status' => 'active', 'created_by' => $admin->id]);
    $project->members()->create(['user_id' => $admin->id, 'role' => 'owner']);
    $task = $project->tasks()->create(['title' => 'Existing task', 'status' => 'todo', 'priority' => 'medium', 'created_by' => $admin->id]);

    $this->get("/api/v1/projects/{$project->id}/export.xlsx", bearerHeaders($token))
        ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $file = spreadsheetUpload('tasks.xlsx', [
        'ID', 'Judul', 'Deskripsi', 'Tipe', 'Status', 'Prioritas', 'Story Points',
        'Estimasi Menit', 'Aktual Menit', 'Email Assignee', 'Tanggal Mulai', 'Tenggat',
    ], [
        [$task->id, 'Existing task updated', null, 'task', 'in_progress', 'high', 5, 120, 30, null, '2026-07-01', '2026-07-10'],
        [null, 'New imported task', 'Created from Excel', 'story', 'todo', 'urgent', 8, 240, null, $admin->email, '2026-07-11', '2026-07-20'],
    ]);

    $this->post("/api/v1/projects/{$project->id}/import.xlsx", ['file' => $file], bearerHeaders($token))
        ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

    expect(Task::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and($task->fresh()->status)->toBe('in_progress');
});

it('rejects an invalid task spreadsheet atomically', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $project = Project::create(['company_id' => 1, 'name' => 'Atomic Import', 'status' => 'active', 'created_by' => $admin->id]);
    $project->members()->create(['user_id' => $admin->id, 'role' => 'owner']);
    $file = spreadsheetUpload('tasks.xlsx', ['ID', 'Judul', 'Tipe', 'Status', 'Prioritas', 'Tanggal Mulai', 'Tenggat'], [
        [null, 'Valid task', 'task', 'todo', 'medium', '2026-07-01', '2026-07-02'],
        [null, 'Invalid task', 'task', 'unknown', 'medium', '2026-07-03', '2026-07-02'],
    ]);

    $this->post("/api/v1/projects/{$project->id}/import.xlsx", ['file' => $file], bearerHeaders($token))
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);
    expect(Task::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('exports and imports rab items and progress as excel', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $project = Project::create(['company_id' => 1, 'name' => 'RAB Excel', 'status' => 'active', 'created_by' => $admin->id]);
    $project->members()->create(['user_id' => $admin->id, 'role' => 'owner']);
    $file = spreadsheetUpload('rab.xlsx', [
        'ID', 'Kode', 'Kategori', 'Uraian', 'Satuan', 'Volume', 'Harga Satuan',
        'Mulai Rencana', 'Selesai Rencana', 'Catatan Item', 'Tanggal Progres',
        'Rencana Persen', 'Aktual Persen', 'Biaya Aktual', 'Catatan Progres',
    ], [[null, 'RAB-001', 'labor', 'Tenaga implementasi', 'paket', 2, 5000000, '2026-07-01', '2026-07-31', null, '2026-07-15', 50, 40, 4000000, 'UAT']]);

    $this->post("/api/v1/projects/{$project->id}/budget/import.xlsx", ['file' => $file], bearerHeaders($token))
        ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.progress', 1);

    $item = ProjectBudgetItem::withoutGlobalScopes()->first();
    expect($item)->not->toBeNull()->and($item->progressEntries()->count())->toBe(1);
    $this->get("/api/v1/projects/{$project->id}/budget/export.xlsx", bearerHeaders($token))->assertOk();
});

<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ProfessionalSpreadsheetExport;
use App\Imports\HeadingRowImport;
use App\Models\Project;
use App\Models\ProjectBudgetItem;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectBudgetService;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectSpreadsheetController extends ApiController
{
    private const TASK_HEADINGS = [
        'ID', 'Judul', 'Deskripsi', 'Tipe', 'Status', 'Prioritas', 'Story Points',
        'Estimasi Menit', 'Aktual Menit', 'Email Assignee', 'Tanggal Mulai', 'Tenggat',
    ];

    private const BUDGET_HEADINGS = [
        'ID', 'Kode', 'Kategori', 'Uraian', 'Satuan', 'Volume', 'Harga Satuan',
        'Mulai Rencana', 'Selesai Rencana', 'Catatan Item', 'Tanggal Progres',
        'Rencana Persen', 'Aktual Persen', 'Biaya Aktual', 'Catatan Progres',
    ];

    // Header keys yang WAJIB ada di file import (dalam bentuk snake_case hasil HeadingRowImport)
    private const TASK_REQUIRED_KEYS = ['judul', 'tipe', 'status', 'prioritas'];

    private const BUDGET_REQUIRED_KEYS = ['kode', 'kategori', 'uraian', 'satuan', 'volume', 'harga_satuan'];

    public function __construct(
        private readonly ProjectAuthorizer $authorizer,
        private readonly ProjectService $projects,
        private readonly ProjectBudgetService $budgets,
    ) {}

    public function templateTasks(Request $request, Project $project): BinaryFileResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');
        $sample = [
            ['', 'Contoh: Setup development environment', 'Deskripsi opsional, sampai 1000 karakter', 'task', 'todo', 'medium', 3, 240, 0, '', now()->toDateString(), now()->addDays(7)->toDateString()],
            ['', 'Contoh: Review desain wireframe', '', 'story', 'in_progress', 'high', 5, 480, 60, 'user@ibereality.com', '', now()->addDays(14)->toDateString()],
        ];

        return Excel::download(
            new ProfessionalSpreadsheetExport(
                self::TASK_HEADINGS,
                $sample,
                $this->meta($project, $request, 'tasks', 'Template Import Tasks', 'Isi baris data mulai baris 9, hapus contoh, lalu upload kembali'),
                $this->taskLegends(),
            ),
            'template-import-tasks.xlsx'
        );
    }

    public function templateBudget(Request $request, Project $project): BinaryFileResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');
        $sample = [
            ['', 'MAT-001', 'material', 'Semen Portland 40kg', 'sak', 100, 75000, now()->toDateString(), now()->addDays(30)->toDateString(), 'Catatan opsional', '', '', '', '', ''],
            ['', 'LBR-001', 'labor', 'Upah tukang batu (harian)', 'HOK', 45, 150000, now()->toDateString(), now()->addDays(45)->toDateString(), '', now()->toDateString(), 20, 15, 500000, 'Progres awal minggu 1'],
        ];

        return Excel::download(
            new ProfessionalSpreadsheetExport(
                self::BUDGET_HEADINGS,
                $sample,
                $this->meta($project, $request, 'budget', 'Template Import RAB', 'Isi baris data mulai baris 9, hapus contoh, lalu upload kembali'),
                $this->budgetLegends(),
            ),
            'template-import-rab.xlsx'
        );
    }

    public function exportTasks(Request $request, Project $project): BinaryFileResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');
        $rows = Task::query()->where('project_id', $project->id)->with('assignee:id,email')
            ->orderBy('id')->get()->map(fn (Task $task) => [
                $task->id, $task->title, $task->description, $task->type, $task->status, $task->priority,
                $task->story_points, $task->time_estimate, $task->time_spent, $task->assignee?->email,
                $task->start_date?->toDateString(), $task->due_date?->toDateString(),
            ])->all();

        return Excel::download(
            new ProfessionalSpreadsheetExport(
                self::TASK_HEADINGS,
                $rows,
                $this->meta($project, $request, 'tasks', 'Export Tasks Projek', 'Snapshot data task pada saat file di-generate'),
                $this->taskLegends(),
            ),
            $this->filename($project, 'tasks')
        );
    }

    public function importTasks(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'create_task');
        $file = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,ods,txt', 'max:5120']])['file'];
        $rows = $this->rows($file, self::TASK_REQUIRED_KEYS, self::TASK_HEADINGS);
        $created = $updated = 0;

        DB::transaction(function () use ($rows, $project, $request, &$created, &$updated) {
            foreach ($rows as $index => $row) {
                if ($this->blank($row, 'judul')) {
                    continue;
                }
                $data = [
                    'title' => $row['judul'] ?? null,
                    'description' => $row['deskripsi'] ?? null,
                    'type' => ($row['tipe'] ?? null) ?: 'task',
                    'status' => ($row['status'] ?? null) ?: 'todo',
                    'priority' => ($row['prioritas'] ?? null) ?: 'medium',
                    'story_points' => $this->nullableInt($row['story_points'] ?? null),
                    'time_estimate' => $this->nullableInt($row['estimasi_menit'] ?? null),
                    'time_spent' => $this->nullableInt($row['aktual_menit'] ?? null),
                    'assigned_to' => $this->assigneeId($project, $row['email_assignee'] ?? null, $index),
                    'start_date' => $this->date($row['tanggal_mulai'] ?? null, $index, 'tanggal_mulai'),
                    'due_date' => $this->date($row['tenggat'] ?? null, $index, 'tenggat'),
                ];
                $this->validateTask($data, $index);
                $taskId = $this->nullableInt($row['id'] ?? null);
                $task = $taskId ? Task::query()->where('project_id', $project->id)->find($taskId) : null;
                if ($task) {
                    $this->authorizer->ensure($request->user(), $project, 'edit_task', $task);
                    $this->projects->updateTask($task, $data, $request->user());
                    $updated++;
                } else {
                    $this->projects->createTask($project, $data, $request->user());
                    $created++;
                }
            }
        });

        return $this->success(compact('created', 'updated'), "Import task selesai: $created dibuat, $updated diperbarui.");
    }

    public function exportBudget(Request $request, Project $project): BinaryFileResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');
        $detail = $this->budgets->detail($project);
        $rows = collect($detail['items'])->map(function (ProjectBudgetItem $item) {
            $latest = $item->progressEntries->sortByDesc('entry_date')->first();

            return [
                $item->id, $item->code, $item->category, $item->description, $item->unit,
                $item->volume, $item->unit_price, $item->planned_start?->toDateString(),
                $item->planned_end?->toDateString(), $item->notes,
                $latest?->entry_date?->toDateString(), $latest?->planned_percent,
                $latest?->actual_percent, $latest?->actual_cost, $latest?->notes,
            ];
        })->all();

        return Excel::download(
            new ProfessionalSpreadsheetExport(
                self::BUDGET_HEADINGS,
                $rows,
                $this->meta($project, $request, 'budget', 'Export RAB Projek', 'Snapshot RAB + progres terakhir per item'),
                $this->budgetLegends(),
            ),
            $this->filename($project, 'rab')
        );
    }

    public function importBudget(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $file = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,ods,txt', 'max:5120']])['file'];
        $rows = $this->rows($file, self::BUDGET_REQUIRED_KEYS, self::BUDGET_HEADINGS);
        $created = $updated = $progress = 0;

        DB::transaction(function () use ($rows, $project, $request, &$created, &$updated, &$progress) {
            foreach ($rows as $index => $row) {
                if ($this->blank($row, 'kode') && $this->blank($row, 'uraian')) {
                    continue;
                }
                $data = [
                    'code' => $row['kode'] ?? null,
                    'category' => ($row['kategori'] ?? null) ?: 'other',
                    'description' => $row['uraian'] ?? null,
                    'unit' => $row['satuan'] ?? null,
                    'volume' => $row['volume'] ?? null,
                    'unit_price' => $row['harga_satuan'] ?? null,
                    'planned_start' => $this->date($row['mulai_rencana'] ?? null, $index, 'mulai_rencana'),
                    'planned_end' => $this->date($row['selesai_rencana'] ?? null, $index, 'selesai_rencana'),
                    'notes' => $row['catatan_item'] ?? null,
                ];
                $this->validateBudget($data, $index);
                $itemId = $this->nullableInt($row['id'] ?? null);
                $item = $itemId ? ProjectBudgetItem::withoutGlobalScopes()->find($itemId) : null;
                if (! $item) {
                    $item = ProjectBudgetItem::withoutGlobalScopes()->whereHas('budget', fn ($query) => $query
                        ->where('project_id', $project->id)->where('company_id', $project->company_id))
                        ->where('code', $data['code'])->first();
                }
                if ($item) {
                    $item = $this->budgets->updateItem($project, $item, $data, $request->user());
                    $updated++;
                } else {
                    $item = $this->budgets->addItem($project, $data, $request->user());
                    $created++;
                }
                if (! $this->blank($row, 'tanggal_progres')) {
                    $progressData = [
                        'entry_date' => $this->date($row['tanggal_progres'], $index, 'tanggal_progres'),
                        'planned_percent' => $row['rencana_persen'] ?? 0,
                        'actual_percent' => $row['aktual_persen'] ?? 0,
                        'actual_cost' => $row['biaya_aktual'] ?? 0,
                        'notes' => $row['catatan_progres'] ?? null,
                    ];
                    $validator = Validator::make($progressData, [
                        'entry_date' => ['required', 'date'], 'planned_percent' => ['numeric', 'between:0,100'],
                        'actual_percent' => ['numeric', 'between:0,100'], 'actual_cost' => ['numeric', 'min:0'],
                    ]);
                    if ($validator->fails()) {
                        $this->rowError($index, $validator->errors()->first());
                    }
                    $this->budgets->saveProgress($project, $item, $progressData, $request->user());
                    $progress++;
                }
            }
        });

        return $this->success(compact('created', 'updated', 'progress'), "Import RAB selesai: $created dibuat, $updated diperbarui, $progress progres dicatat.");
    }

    /**
     * @param  array<int, string>  $requiredKeys  snake_case header keys yg wajib ada
     * @param  array<int, string>  $expectedHeadings  human-readable heading list untuk pesan error
     */
    private function rows($file, array $requiredKeys, array $expectedHeadings): array
    {
        $import = new HeadingRowImport;
        Excel::import($import, $file);
        $rows = ($import->rows ?? collect())->take(1001);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'File yang di-upload kosong atau tidak dapat dibaca. Silakan download template dahulu via tombol "Template", isi datanya, lalu upload ulang.',
            ]);
        }

        // Validasi header — harus match template. Missing key = format salah, kemungkinan bukan template kita.
        $firstRow = $rows->first();
        $foundKeys = array_keys(is_array($firstRow) ? $firstRow : $firstRow->toArray());
        $missing = array_diff($requiredKeys, $foundKeys);

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'file' => 'Format file tidak sesuai template resmi. Kolom wajib yang hilang: '
                    .implode(', ', $missing)
                    .'. Silakan download template terbaru dengan menekan tombol "Template" di sebelah tombol "Import", isi datanya di sana, lalu upload ulang. '
                    .'Kolom yang seharusnya ada: '.implode(', ', $expectedHeadings).'.',
            ]);
        }

        if ($rows->count() > 1000) {
            throw ValidationException::withMessages(['file' => 'Maksimal 1.000 baris per import. Silakan pecah menjadi beberapa file.']);
        }

        return $rows->map(fn ($row) => is_array($row) ? $row : $row->toArray())->all();
    }

    private function validateTask(array $data, int $index): void
    {
        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['task', 'story', 'bug', 'epic'])],
            'status' => ['required', Rule::in(['todo', 'in_progress', 'in_review', 'completed'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'story_points' => ['nullable', 'integer', 'min:0', 'max:255'],
            'time_estimate' => ['nullable', 'integer', 'min:0'], 'time_spent' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        if ($validator->fails()) {
            $this->rowError($index, $validator->errors()->first());
        }
    }

    private function validateBudget(array $data, int $index): void
    {
        $validator = Validator::make($data, [
            'code' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::in(['material', 'labor', 'equipment', 'subcontract', 'overhead', 'other'])],
            'description' => ['required', 'string', 'max:255'], 'unit' => ['required', 'string', 'max:30'],
            'volume' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'numeric', 'min:0'],
            'planned_start' => ['nullable', 'date'], 'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
        ]);
        if ($validator->fails()) {
            $this->rowError($index, $validator->errors()->first());
        }
    }

    private function assigneeId(Project $project, mixed $email, int $index): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }
        $id = User::withoutGlobalScopes()->active()->where('company_id', $project->company_id)
            ->where('email', trim($email))->value('id');
        if (! $id) {
            $this->rowError($index, "Email assignee '$email' tidak ditemukan atau sudah nonaktif.");
        }

        return $id;
    }

    private function date(mixed $value, int $index, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->toDateString();
            }

            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            $this->rowError($index, "Format tanggal $field tidak valid.");
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function blank(array $row, string $key): bool
    {
        return ! isset($row[$key]) || trim((string) $row[$key]) === '';
    }

    private function rowError(int $index, string $message): never
    {
        // +2 = kompensasi header row (offset 0) + 1-index user
        throw ValidationException::withMessages(['file' => 'Baris '.($index + 2).": $message"]);
    }

    private function filename(Project $project, string $suffix): string
    {
        return str($project->name)->slug()->limit(70, '')->append("-$suffix.xlsx")->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Project $project, Request $request, string $kind, string $title, string $subtitle): array
    {
        $user = $request->user();

        return [
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'company_name' => 'PT Integrasi Bisnis Eksekutif',
            'project_name' => $project->name,
            'generated_by' => $user?->name.($user?->email ? ' <'.$user->email.'>' : ''),
            'generated_at' => now()->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB',
        ];
    }

    /**
     * @return array<int, array{name: string, description: string, values: array<int, array{code: string, label: string}>}>
     */
    private function taskLegends(): array
    {
        return [
            [
                'name' => 'Tipe',
                'description' => 'Klasifikasi task. Case-sensitive, lowercase.',
                'values' => [
                    ['code' => 'task', 'label' => 'Task umum'],
                    ['code' => 'story', 'label' => 'User story / fitur'],
                    ['code' => 'bug', 'label' => 'Perbaikan bug'],
                    ['code' => 'epic', 'label' => 'Epic besar / milestone'],
                ],
            ],
            [
                'name' => 'Status',
                'description' => 'Status pengerjaan task.',
                'values' => [
                    ['code' => 'todo', 'label' => 'Belum dikerjakan'],
                    ['code' => 'in_progress', 'label' => 'Sedang dikerjakan'],
                    ['code' => 'in_review', 'label' => 'Menunggu review'],
                    ['code' => 'completed', 'label' => 'Selesai'],
                ],
            ],
            [
                'name' => 'Prioritas',
                'description' => 'Tingkat prioritas task.',
                'values' => [
                    ['code' => 'low', 'label' => 'Rendah'],
                    ['code' => 'medium', 'label' => 'Sedang (default)'],
                    ['code' => 'high', 'label' => 'Tinggi'],
                    ['code' => 'urgent', 'label' => 'Mendesak'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, description: string, values: array<int, array{code: string, label: string}>}>
     */
    private function budgetLegends(): array
    {
        return [
            [
                'name' => 'Kategori',
                'description' => 'Kategori item RAB. Case-sensitive, lowercase.',
                'values' => [
                    ['code' => 'material', 'label' => 'Material / bahan'],
                    ['code' => 'labor', 'label' => 'Tenaga kerja / upah'],
                    ['code' => 'equipment', 'label' => 'Alat / equipment'],
                    ['code' => 'subcontract', 'label' => 'Subkontraktor'],
                    ['code' => 'overhead', 'label' => 'Overhead / biaya tidak langsung'],
                    ['code' => 'other', 'label' => 'Lainnya (default)'],
                ],
            ],
        ];
    }
}

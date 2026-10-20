<?php

namespace App\Keuangan\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Keuangan\Models\Journal;
use App\Keuangan\Services\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends ApiController
{
    public function __construct(private readonly JournalService $service) {}

    /** GET /api/v1/finance/journals */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $status = $request->string('status')->toString();
        $month = $request->string('month')->toString();
        // Filter rentang tanggal untuk halaman Jurnal Umum. Lebih luwes
        // daripada filter bulanan karena periode laporan sering memotong bulan.
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();
        $q = $request->string('q')->toString();

        $paginator = Journal::query()
            ->where('company_id', $admin->company_id)
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($month, fn ($w) => $w->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2)))
            ->when($dateFrom, fn ($w) => $w->whereDate('date', '>=', $dateFrom))
            ->when($dateTo, fn ($w) => $w->whereDate('date', '<=', $dateTo))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('number', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('reference', 'like', "%{$q}%");
            }))
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($paginator, $paginator->items());
    }

    /** GET /api/v1/finance/journals/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');

        $journal = Journal::query()
            ->where('company_id', $admin->company_id)
            ->with('lines.account:id,code,name')
            ->findOrFail($id);

        return $this->success($journal);
    }

    /** POST /api/v1/finance/journals */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ]);

        $journal = $this->service->create($admin, $data, $data['lines']);

        return $this->created($journal, "Jurnal {$journal->number} dibuat (draft).");
    }

    /** POST /api/v1/finance/journals/{id}/post */
    public function post(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.post', 'finance.module.access');

        $journal = Journal::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.journal.post', 'finance.module.access', context: [
            'company_id' => $journal->company_id,
            'resource_type' => 'journal', 'resource_id' => $journal->id,
            'amount' => $journal->total_debit, 'maker_id' => $journal->created_by,
            'irreversible' => true, 'action' => 'post', 'status' => $journal->status,
        ]);
        $posted = $this->service->post($admin, $journal);

        return $this->success($posted, "Jurnal {$posted->number} di-post ke Buku Besar.");
    }

    /** POST /api/v1/finance/journals/{id}/reverse */
    public function reverse(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.reverse', 'finance.module.access');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $journal = Journal::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->requireGranularCapability($admin, 'finance.journal.reverse', 'finance.module.access', context: [
            'company_id' => $journal->company_id,
            'resource_type' => 'journal', 'resource_id' => $journal->id,
            'amount' => $journal->total_debit, 'maker_id' => $journal->posted_by,
            'irreversible' => true, 'action' => 'reverse', 'status' => $journal->status,
        ]);
        $reversed = $this->service->reverse($admin, $journal, $data['reason']);

        return $this->success($reversed, "Jurnal {$reversed->number} di-reverse.");
    }

    /** DELETE /api/v1/finance/journals/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $journal = Journal::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $this->service->delete($admin, $journal);

        return $this->success(message: 'Draft jurnal dihapus.');
    }
}

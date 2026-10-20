<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analytics endpoint untuk Dashboard:
 *   - Counts per status
 *   - Counts per kategori
 *   - Per month bar chart 12 bulan terakhir
 *   - Sequence gap untuk audit
 *   - Top recipient/sender companies
 */
class LetterAnalyticsController extends ApiController
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->requireAnalyticsAccess($request, 'dashboard');
        $companyId = $request->user()->company_id;

        $byStatus = Letter::where('company_id', $companyId)
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')->pluck('n', 'status');

        $byDirection = Letter::where('company_id', $companyId)
            ->select('direction', DB::raw('count(*) as n'))
            ->groupBy('direction')->pluck('n', 'direction');

        $byCategory = Letter::where('letters.company_id', $companyId)
            ->whereNotNull('letter_number')
            ->join('letter_categories', 'letter_categories.id', '=', 'letters.category_id')
            ->where('letter_categories.company_id', $companyId)
            ->where('letter_categories.code', '!=', 'MSK')
            ->select('letter_categories.code', 'letter_categories.name', DB::raw('count(*) as n'))
            ->groupBy('letter_categories.code', 'letter_categories.name')
            ->orderByDesc('n')->limit(10)->get();

        // Monthly counts - 12 bulan terakhir
        $from = Carbon::now()->subMonths(11)->startOfMonth();
        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', letter_date)"
            : "DATE_FORMAT(letter_date, '%Y-%m')";
        $monthly = Letter::where('company_id', $companyId)
            ->where('letter_date', '>=', $from->toDateString())
            ->select(
                DB::raw("{$monthExpression} as month"),
                'direction',
                DB::raw('count(*) as n'),
            )
            ->groupBy('month', 'direction')->orderBy('month')->get();

        // Top company partners
        $topRecipients = Letter::where('company_id', $companyId)
            ->where('direction', 'out')
            ->whereNotNull('recipient_company')
            ->select('recipient_company', DB::raw('count(*) as n'))
            ->groupBy('recipient_company')->orderByDesc('n')->limit(8)->get();
        $topSenders = Letter::where('company_id', $companyId)
            ->where('direction', 'in')
            ->whereNotNull('sender_company')
            ->select('sender_company', DB::raw('count(*) as n'))
            ->groupBy('sender_company')->orderByDesc('n')->limit(8)->get();

        // Expiring Penawaran (< 14 hari)
        $expiringSoon = Letter::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(14))
            ->whereIn('status', ['signed', 'sent'])
            ->orderBy('expiry_date')
            ->limit(10)
            ->get(['public_id', 'letter_number', 'subject', 'expiry_date', 'recipient_company']);

        return $this->success([
            'by_status' => $byStatus,
            'by_direction' => $byDirection,
            'by_category' => $byCategory,
            'monthly' => $monthly,
            'top_recipients' => $topRecipients,
            'top_senders' => $topSenders,
            'expiring_soon' => $expiringSoon,
            'total_letters' => Letter::where('company_id', $companyId)->count(),
        ]);
    }

    /**
     * Sequence gap report - list nomor surat yang skip per kategori per tahun.
     * Audit BPK essential.
     */
    public function sequenceGaps(Request $request): JsonResponse
    {
        $this->requireAnalyticsAccess($request, 'sequence_gaps');
        $companyId = $request->user()->company_id;
        $year = (int) ($request->query('year') ?: now()->year);

        $gaps = [];
        $cats = LetterCategory::where('company_id', $companyId)
            ->where('code', '!=', 'MSK')->where('active', true)->get();

        foreach ($cats as $cat) {
            $rows = Letter::where('company_id', $companyId)
                ->where('category_id', $cat->id)
                ->where('letter_number_year', $year)
                ->orderBy('letter_number_seq')
                ->get(['letter_number_seq', 'letter_number', 'status', 'public_id', 'subject', 'letter_date']);

            $taken = $rows->pluck('letter_number_seq')->filter()->values()->all();
            if (empty($taken)) {
                continue;
            }

            $max = max($taken);
            $gap = [];
            for ($i = 1; $i <= $max; $i++) {
                if (! in_array($i, $taken, true)) {
                    $gap[] = $i;
                }
            }

            if (! empty($gap)) {
                $gaps[] = [
                    'category_code' => $cat->code,
                    'category_name' => $cat->name,
                    'year' => $year,
                    'max_seq' => $max,
                    'used' => count($taken),
                    'gap_count' => count($gap),
                    'missing' => $gap,
                    'cancelled' => $rows->where('status', 'cancelled')->values()
                        ->map(fn ($r) => ['seq' => $r->letter_number_seq, 'public_id' => $r->public_id, 'subject' => $r->subject])
                        ->all(),
                ];
            }
        }

        return $this->success([
            'year' => $year,
            'gaps' => $gaps,
        ]);
    }

    private function requireAnalyticsAccess(Request $request, string $action): void
    {
        $this->requireGranularCapability(
            $request->user(),
            'documents.analytics.view',
            'admin.access',
            'Document analytics access required.',
            [
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_analytics',
                'action' => $action,
            ],
        );
    }
}

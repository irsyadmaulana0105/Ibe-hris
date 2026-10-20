<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Activity;
use App\Marketing\Models\Deal;
use App\Marketing\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends ApiController
{
    /**
     * GET /crm/reports/summary
     * KPI panel: open pipeline value, won this month, lost this month,
     * forecasted value (weighted by probability), win rate 30 days.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $openDeals = Deal::query()
            ->where('company_id', $user->company_id)
            ->where('status', 'open')
            ->get(['amount', 'probability']);

        $wonThisMonth = Deal::query()
            ->where('company_id', $user->company_id)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $lostThisMonth = Deal::query()
            ->where('company_id', $user->company_id)
            ->where('status', 'lost')
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $closedRecent = Deal::query()
            ->where('company_id', $user->company_id)
            ->whereIn('status', ['won', 'lost'])
            ->where('closed_at', '>=', $thirtyDaysAgo)
            ->get(['status']);
        $totalClosed = $closedRecent->count();
        $winCount = $closedRecent->where('status', 'won')->count();
        $winRate = $totalClosed > 0 ? round(($winCount / $totalClosed) * 100, 1) : 0;

        return $this->success([
            'open_pipeline_value' => (float) $openDeals->sum('amount'),
            'open_deals_count' => $openDeals->count(),
            'forecasted_value' => (float) $openDeals->sum(fn ($d) => $d->amount * ($d->probability / 100)),
            'won_this_month' => (float) $wonThisMonth,
            'lost_this_month' => (float) $lostThisMonth,
            'win_rate_30d' => $winRate,
            'closed_count_30d' => $totalClosed,
        ]);
    }

    /**
     * GET /crm/reports/funnel?pipeline_id=1
     * Jumlah deal per stage - buat visualisasi funnel chart.
     */
    public function funnel(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $pipelineId = (int) $request->integer('pipeline_id');

        $stages = Stage::query()
            ->where('company_id', $user->company_id)
            ->when($pipelineId, fn ($q) => $q->where('pipeline_id', $pipelineId))
            ->orderBy('sort_order')
            ->get();

        $data = $stages->map(function ($stage) use ($user) {
            $deals = Deal::query()
                ->where('company_id', $user->company_id)
                ->where('stage_id', $stage->id)
                ->get(['amount', 'status']);

            return [
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
                'stage_color' => $stage->color,
                'stage_type' => $stage->type,
                'deal_count' => $deals->count(),
                'total_amount' => (float) $deals->sum('amount'),
            ];
        });

        return $this->success([
            'rows' => $data->values(),
            ...$this->cumulativeFunnel($user->company_id, $stages),
        ]);
    }

    /**
     * Funnel kumulatif: berapa deal yang PERNAH mencapai tiap tahap.
     *
     * `rows` di atas hanya menghitung posisi saat ini, sehingga deal yang sudah
     * menang tidak lagi terhitung di "Proposal" walau pernah melewatinya, dan
     * konversi antar-tahap tidak bisa dibaca. Di sini:
     *   - deal berjalan mencapai semua tahap terbuka s.d. tahapnya sekarang;
     *   - deal menang mencapai semua tahap terbuka + tahap menang;
     *   - deal kalah mencapai tahap terakhirnya sebelum kalah, dibaca dari
     *     catatan perpindahan tahap ("Tahap: A → B", tercatat sejak
     *     30 Sep 2026). Tanpa catatan itu dihitung hanya di tahap pertama,
     *     dan jumlahnya dilaporkan sebagai `lost_without_history`.
     *
     * @param  \Illuminate\Support\Collection<int, Stage>  $stages
     * @return array<string, mixed>
     */
    private function cumulativeFunnel(int $companyId, $stages): array
    {
        $terbuka = $stages->where('type', 'open')->values();
        $menang = $stages->firstWhere('type', 'won');
        if ($terbuka->isEmpty()) {
            return ['cumulative' => [], 'win_rate' => null, 'lost_without_history' => 0];
        }

        $urutan = $terbuka->pluck('id')->flip(); // stage_id => indeks
        $indeksNama = $terbuka->mapWithKeys(fn ($s) => [mb_strtolower($s->name) => $urutan[$s->id]]);
        $jumlahTahap = $terbuka->count() + ($menang ? 1 : 0);
        $capai = array_fill(0, $jumlahTahap, ['count' => 0, 'amount' => 0.0]);
        $tambah = function (int $sampai, float $nilai) use (&$capai) {
            for ($i = 0; $i <= $sampai; $i++) {
                $capai[$i]['count']++;
                $capai[$i]['amount'] += $nilai;
            }
        };

        $deals = Deal::query()
            ->where('company_id', $companyId)
            ->whereIn('stage_id', $stages->pluck('id'))
            ->get(['id', 'stage_id', 'status', 'amount']);

        // Tahap terakhir sebelum kalah, dari catatan perpindahan tahap terbaru.
        $catatanKalah = Activity::query()
            ->where('company_id', $companyId)
            ->where('subjectable_type', 'deal')
            ->whereIn('subjectable_id', $deals->where('status', 'lost')->pluck('id'))
            ->where('subject', 'like', 'Tahap: %')
            ->orderBy('happened_at')
            ->get(['subjectable_id', 'subject'])
            ->groupBy('subjectable_id')
            ->map(fn ($rows) => $rows->last()->subject);

        // Sumber kedua: audit log perpindahan tahap (meta.stage_id). Dipakai
        // bila catatan aktivitas tidak ada - mis. deal yang kalah sebelum
        // catatan "Tahap: A → B" mulai ditulis. Entri terakhir adalah langkah
        // ke tahap kalah; entri sebelumnya = tahap terakhir sebelum kalah.
        // Kalau tidak ada entri sebelumnya, deal kalah langsung dari tahap
        // awalnya, yaitu tahap pertama pipeline.
        $jejakAudit = DB::table('audit_logs')
            ->where('action', 'crm.deal.stage_changed')
            ->where('entity_type', 'crm_deal')
            ->whereIn('entity_id', $deals->where('status', 'lost')->pluck('id')->map(fn ($id) => (string) $id))
            ->orderBy('id')
            ->get(['entity_id', 'meta'])
            ->groupBy('entity_id');
        $dariAudit = function (int $dealId) use ($jejakAudit, $urutan): ?int {
            $baris = $jejakAudit->get((string) $dealId);
            if (! $baris || $baris->isEmpty()) {
                return null;
            }
            $sebelum = $baris->slice(0, -1)->reverse();
            foreach ($sebelum as $b) {
                $stageId = json_decode((string) $b->meta, true)['stage_id'] ?? null;
                if ($stageId !== null && isset($urutan[$stageId])) {
                    return $urutan[$stageId];
                }
            }

            return 0;
        };

        $menangCount = 0;
        $kalahCount = 0;
        $kalahTanpaJejak = 0;
        foreach ($deals as $d) {
            $nilai = (float) $d->amount;
            if ($d->status === 'won') {
                $menangCount++;
                $tambah($jumlahTahap - 1, $nilai);
            } elseif ($d->status === 'lost') {
                $kalahCount++;
                $dari = null;
                if (preg_match('/^Tahap: (.+) → /u', (string) ($catatanKalah[$d->id] ?? ''), $m)) {
                    $dari = $indeksNama[mb_strtolower(trim($m[1]))] ?? null;
                }
                $dari ??= $dariAudit((int) $d->id);
                if ($dari === null) {
                    $kalahTanpaJejak++;
                }
                $tambah($dari ?? 0, $nilai);
            } elseif (isset($urutan[$d->stage_id])) {
                $tambah($urutan[$d->stage_id], $nilai);
            }
        }

        $tahap = $terbuka->concat($menang ? [$menang] : [])->values();
        $hasil = $tahap->map(fn ($s, $i) => [
            'stage_id' => $s->id,
            'stage_name' => $s->name,
            'stage_color' => $s->color,
            'stage_type' => $s->type,
            'reached_count' => $capai[$i]['count'],
            'reached_amount' => $capai[$i]['amount'],
            // Persen deal dari tahap sebelumnya yang berhasil sampai ke tahap ini.
            'conversion_pct' => $i === 0 || $capai[$i - 1]['count'] === 0
                ? null
                : round($capai[$i]['count'] / $capai[$i - 1]['count'] * 100, 1),
        ]);

        return [
            'cumulative' => $hasil->values(),
            'win_rate' => $menangCount + $kalahCount > 0
                ? round($menangCount / ($menangCount + $kalahCount) * 100, 1)
                : null,
            'lost_without_history' => $kalahTanpaJejak,
        ];
    }
}

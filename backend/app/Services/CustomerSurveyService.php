<?php

namespace App\Services;

use App\Models\CustomerSurvey;
use App\Models\User;
use Illuminate\Support\Str;

class CustomerSurveyService
{
    public function send(User $actor, array $data): CustomerSurvey
    {
        return CustomerSurvey::create([
            'company_id'        => $actor->company_id,
            'survey_token'      => Str::random(40),
            'customer_id'       => $data['customer_id'] ?? null,
            'customer_name'     => $data['customer_name'],
            'customer_email'    => $data['customer_email'] ?? null,
            'trigger_type'      => $data['trigger_type'] ?? 'manual',
            'trigger_ref_table' => $data['trigger_ref_table'] ?? null,
            'trigger_ref_id'    => $data['trigger_ref_id'] ?? null,
            'sent_date'         => now()->toDateString(),
            'status'            => 'sent',
            'sent_by'           => $actor->id,
        ]);
    }

    public function recordResponse(CustomerSurvey $survey, array $data): CustomerSurvey
    {
        $score = (int) $data['nps_score'];
        $score = max(0, min(10, $score));
        $survey->update([
            'nps_score'    => $score,
            'nps_category' => CustomerSurvey::categoryFor($score),
            'comment'      => $data['comment'] ?? null,
            'ratings'      => $data['ratings'] ?? null,
            'responded_at' => now(),
            'status'       => 'responded',
        ]);
        return $survey->fresh();
    }

    /**
     * Compute NPS metric for a period.
     *
     *   NPS = % Promoters - % Detractors
     *   Score range: -100 to +100
     */
    public function npsForPeriod(int $companyId, string $from, string $to): array
    {
        $responded = CustomerSurvey::query()
            ->where('company_id', $companyId)
            ->where('status', 'responded')
            ->whereBetween('responded_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->get(['nps_score', 'nps_category']);

        $total = $responded->count();
        if ($total === 0) {
            return [
                'period' => ['from' => $from, 'to' => $to],
                'total_responses' => 0,
                'promoters' => 0, 'passives' => 0, 'detractors' => 0,
                'nps' => 0,
                'avg_score' => 0,
            ];
        }

        $promoters = $responded->where('nps_category', 'promoter')->count();
        $passives  = $responded->where('nps_category', 'passive')->count();
        $detractors= $responded->where('nps_category', 'detractor')->count();
        $avg       = round($responded->avg('nps_score'), 2);
        $nps       = round((($promoters - $detractors) / $total) * 100, 1);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_responses' => $total,
            'promoters'  => $promoters,
            'passives'   => $passives,
            'detractors' => $detractors,
            'promoter_pct'  => round($promoters / $total * 100, 1),
            'passive_pct'   => round($passives / $total * 100, 1),
            'detractor_pct' => round($detractors / $total * 100, 1),
            'nps' => $nps,
            'avg_score' => $avg,
        ];
    }
}

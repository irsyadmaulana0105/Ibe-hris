<?php

namespace App\Http\Resources;

use App\Models\SalaryAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalaryAdvance */
class SalaryAdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'amount' => $this->amount,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'hr_approved_at' => $this->hr_approved_at?->toIso8601String(),
            'hr_notes' => $this->hr_notes,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'journal_id' => $this->journal_id,
            'created_at' => $this->created_at->toIso8601String(),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'nip' => $this->user->nip,
                'division' => $this->user->division?->name,
                'avatar' => $this->user->profile_photo_url,
            ]),

            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),
            'hr_approved_by' => $this->whenLoaded('hrApprovedBy', fn () => $this->hrApprovedBy
                ? ['id' => $this->hrApprovedBy->id, 'name' => $this->hrApprovedBy->name]
                : null
            ),
            'settled_by' => $this->whenLoaded('settledBy', fn () => $this->settledBy
                ? ['id' => $this->settledBy->id, 'name' => $this->settledBy->name]
                : null
            ),
        ];
    }
}

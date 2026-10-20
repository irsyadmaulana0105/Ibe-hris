<?php

namespace App\Marketing\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'source' => $this->source,
            'status' => $this->status,
            'notes' => $this->notes,
            'owner_id' => $this->owner_id,
            'converted_deal_id' => $this->converted_deal_id,
            'converted_at' => $this->converted_at?->toIso8601String(),
            // BANT scoring
            'budget_score' => (int) ($this->budget_score ?? 0),
            'authority_score' => (int) ($this->authority_score ?? 0),
            'need_score' => (int) ($this->need_score ?? 0),
            'timeline_score' => (int) ($this->timeline_score ?? 0),
            'score' => (int) ($this->score ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

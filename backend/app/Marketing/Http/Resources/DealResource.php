<?php

namespace App\Marketing\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'crm_company_id' => $this->crm_company_id,
            'crm_contact_id' => $this->crm_contact_id,
            'owner_id' => $this->owner_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'probability' => $this->probability,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'closed_at' => $this->closed_at?->toDateString(),
            'status' => $this->status,
            'lost_reason' => $this->lost_reason,
            'fin_invoice_id' => $this->fin_invoice_id,
            // Nomor yang bisa dibaca manusia (INV-2026/09/0001). Dulu UI hanya
            // menampilkan ULID mentah. Hanya deal won yang punya invoice, jadi
            // query tambahan ini jarang terjadi.
            'fin_invoice_number' => $this->fin_invoice_id
                ? \App\Keuangan\Models\Invoice::withoutGlobalScopes()->whereKey($this->fin_invoice_id)->value('number')
                : null,
            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'color' => $this->stage->color,
                'type' => $this->stage->type,
            ]),
            'crm_company' => $this->whenLoaded('crmCompany', fn () => [
                'id' => $this->crmCompany->id,
                'name' => $this->crmCompany->name,
            ]),
            'crm_contact' => $this->whenLoaded('crmContact', fn () => [
                'id' => $this->crmContact->id,
                'full_name' => $this->crmContact->full_name,
                'email' => $this->crmContact->email,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

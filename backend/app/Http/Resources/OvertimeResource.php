<?php

namespace App\Http\Resources;

use App\Models\Overtime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Overtime */
class OvertimeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'start_time' => $this->check_in_time,
            'end_time' => $this->check_out_time,
            'total_hours' => (float) $this->total_hours,
            'reason' => $this->reason,
            'status' => $this->status,
            'notes' => $this->notes,
            'attachment_url' => null,
            'attachment_available' => false,
            'attachment_upload_enabled' => (bool) config('sensitive_file_features.overtime_attachment', false),
            'approved_at' => $this->approved_at?->toIso8601String(),
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
        ];
    }
}

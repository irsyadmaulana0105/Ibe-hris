<?php

namespace App\Http\Resources;

use App\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Leave */
class LeaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type' => [
                'key' => $this->leave_type,
                'label' => Leave::$leaveTypes[$this->leave_type] ?? $this->leave_type,
            ],
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days_count' => $this->days_count,
            'reason' => $this->reason,
            'status' => $this->status,
            'notes' => $this->notes,
            'attachment_url' => null,
            'attachment_available' => false,
            'attachment_upload_enabled' => (bool) config('sensitive_file_features.leave_attachment', false),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'nip' => $this->user->nip,
                'division' => $this->user->division?->name,
                'job_title' => $this->user->jobTitle?->name,
                'avatar' => $this->user->profile_photo_url,
            ]),

            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),
        ];
    }
}

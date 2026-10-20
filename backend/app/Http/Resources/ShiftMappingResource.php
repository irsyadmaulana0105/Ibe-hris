<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'shift_id' => $this->shift_id,
            'date' => $this->date?->toDateString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'nip' => $this->user?->nip,
                'division_id' => $this->user?->division_id,
                'job_title_id' => $this->user?->job_title_id,
            ]),
            'shift' => $this->whenLoaded('shift', fn () => [
                'id' => $this->shift?->id,
                'name' => $this->shift?->name,
                'start_time' => $this->shift?->start_time,
                'end_time' => $this->shift?->end_time,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

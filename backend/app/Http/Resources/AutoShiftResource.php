<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutoShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_title_id' => $this->job_title_id,
            'shift_id' => $this->shift_id,
            'job_title' => $this->whenLoaded('jobTitle', fn () => [
                'id' => $this->jobTitle?->id,
                'name' => $this->jobTitle?->name,
                'division_id' => $this->jobTitle?->division_id,
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

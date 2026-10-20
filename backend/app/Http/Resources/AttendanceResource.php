<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Services\AttendanceReimbursementMediaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $request->user() ? app(AttendanceReimbursementMediaService::class) : null;
        $selfieInUrl = $media?->attendanceUrl($request->user(), $this->resource, 'selfie-in');
        $selfieOutUrl = $media?->attendanceUrl($request->user(), $this->resource, 'selfie-out');
        $attachmentUrl = $media?->attendanceUrl($request->user(), $this->resource, 'attachment');

        // Compute late/early/off-shift indicators kalau shift ter-load
        $shiftStart = null;
        $shiftEnd = null;
        $lateMinutes = null;
        $isOffShift = $this->status === 'off_shift';
        $isLate = $this->status === 'late';

        if ($this->relationLoaded('shift') && $this->shift && $this->time_in) {
            $shiftStart = $this->shift->start_time;
            $shiftEnd = $this->shift->end_time;
            try {
                $start = Carbon::createFromTimeString($shiftStart);
                $in = Carbon::createFromTimeString($this->time_in->format('H:i:s'));
                $diff = $in->diffInMinutes($start, false); // negative if BEFORE shift start
                $lateMinutes = max(0, -$diff);
            } catch (\Throwable $e) { /* ignore */
            }
        }

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'present' => 'Hadir',
                'late' => 'Terlambat',
                'off_shift' => 'Diluar Shift',
                'absent' => 'Tidak Hadir',
                default => $this->status ?: '-',
            },
            'is_late' => $isLate,
            'is_off_shift' => $isOffShift,
            'late_minutes' => $lateMinutes,
            'time_in' => $this->time_in?->format('H:i:s'),
            'time_out' => $this->time_out?->format('H:i:s'),
            'note' => $this->note,
            'shift' => $this->whenLoaded('shift', fn () => $this->shift ? [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'start_time' => $this->shift->start_time,
                'end_time' => $this->shift->end_time,
            ] : null),
            'shift_window' => $shiftStart ? ($shiftStart.' – '.($shiftEnd ?: '?')) : null,
            'location' => ($this->latitude && $this->longitude)
                ? ['lat' => $this->latitude, 'lng' => $this->longitude]
                : null,
            'selfie_in_url' => $selfieInUrl,
            'selfie_in_available' => $selfieInUrl !== null,
            'selfie_out_url' => $selfieOutUrl,
            'selfie_out_available' => $selfieOutUrl !== null,
            'selfie_in_address' => $this->selfie_in_address,
            'selfie_out_address' => $this->selfie_out_address,
            'selfie_in_at' => $this->selfie_in_taken_at?->toIso8601String(),
            'selfie_out_at' => $this->selfie_out_taken_at?->toIso8601String(),
            'attachment_url' => $attachmentUrl,
            'attachment_available' => $attachmentUrl !== null,
            'created_at' => $this->created_at->toIso8601String(),
            // Skor verifikasi wajah & tanda curiga: hanya untuk HR (rute admin).
            'face_check_in' => $this->when($request->is('api/v1/admin/*'), fn () => $this->face_check_in),
            'face_check_out' => $this->when($request->is('api/v1/admin/*'), fn () => $this->face_check_out),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'nip' => $this->user->nip,
                'division' => $this->user->division?->name,
                'avatar' => $this->user->profile_photo_url,
            ]),
        ];
    }
}

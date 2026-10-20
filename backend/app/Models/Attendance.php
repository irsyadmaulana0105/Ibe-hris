<?php

namespace App\Models;

use App\ExtendedCarbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Attendance extends Model
{
    use HasFactory;
    use HasTimestamps;

    protected $fillable = [
        'user_id',
        'barcode_id',
        'date',
        'time_in',
        'time_out',
        'shift_id',
        'latitude',
        'longitude',
        'location_policy',
        'status',
        'late_minutes',
        'note',
        'attachment',
        'selfie_in_path',
        'selfie_in_sha256',
        'selfie_in_size_bytes',
        'selfie_in_mime_type',
        'selfie_in_width',
        'selfie_in_height',
        'selfie_in_unavailable_reason',
        'selfie_in_reference_fingerprint',
        'selfie_out_path',
        'selfie_out_sha256',
        'selfie_out_size_bytes',
        'selfie_out_mime_type',
        'selfie_out_width',
        'selfie_out_height',
        'selfie_out_unavailable_reason',
        'selfie_out_reference_fingerprint',
        'attachment_sha256',
        'attachment_size_bytes',
        'attachment_mime_type',
        'attachment_width',
        'attachment_height',
        'attachment_unavailable_reason',
        'attachment_reference_fingerprint',
        'selfie_in_latitude',
        'selfie_in_longitude',
        'selfie_in_accuracy_m',
        'selfie_out_latitude',
        'selfie_out_longitude',
        'selfie_out_accuracy_m',
        'selfie_in_taken_at',
        'selfie_in_location_recorded_at',
        'selfie_out_taken_at',
        'selfie_out_location_recorded_at',
        'selfie_in_location_risk_flags',
        'selfie_out_location_risk_flags',
        'attendance_device_id',
        'selfie_in_address',
        'selfie_out_address',
    ];

    protected $hidden = [
        'selfie_in_path',
        'selfie_in_sha256',
        'selfie_in_size_bytes',
        'selfie_in_mime_type',
        'selfie_in_width',
        'selfie_in_height',
        'selfie_in_unavailable_reason',
        'selfie_in_reference_fingerprint',
        'selfie_out_path',
        'selfie_out_sha256',
        'selfie_out_size_bytes',
        'selfie_out_mime_type',
        'selfie_out_width',
        'selfie_out_height',
        'selfie_out_unavailable_reason',
        'selfie_out_reference_fingerprint',
        'attachment',
        'attachment_sha256',
        'attachment_size_bytes',
        'attachment_mime_type',
        'attachment_width',
        'attachment_height',
        'attachment_unavailable_reason',
        'attachment_reference_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime:Y-m-d',
            'time_in' => 'datetime:H:i:s',
            'time_out' => 'datetime:H:i:s',
            'selfie_in_taken_at' => 'datetime',
            'selfie_out_taken_at' => 'datetime',
            'selfie_in_location_recorded_at' => 'datetime',
            'selfie_out_location_recorded_at' => 'datetime',
            'selfie_in_location_risk_flags' => 'array',
            'selfie_out_location_risk_flags' => 'array',
            'face_check_in' => 'array',
            'face_check_out' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function barcode()
    {
        return $this->belongsTo(Barcode::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function getLatLngAttribute(): ?array
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ];
    }

    public static function filter(
        $date = null,
        $week = null,
        $month = null,
        $year = null,
        $dateStart = null,
        $dateEnd = null,
        $userId = null,
        $division = null,
        $jobTitle = null,
        $education = null
    ) {
        return self::query()
            ->when($dateStart && $dateEnd, function (Builder $query) use ($dateStart, $dateEnd) {
                $start = Carbon::parse($dateStart)->toDateString();
                $end = Carbon::parse($dateEnd)->toDateString();

                // jaga-jaga bila user kebalik input
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }

                $query->whereBetween('date', [$start, $end]);
            })

            // mode lama tetap ada, tapi hanya jalan jika date range tidak dipakai
            ->when($date && ! ($dateStart && $dateEnd), function (Builder $query) use ($date) {
                $query->where('date', Carbon::parse($date)->toDateString());
            })
            ->when($week && ! $date && ! ($dateStart && $dateEnd), function (Builder $query) use ($week) {
                $start = Carbon::parse($week)->startOfWeek();
                $end = Carbon::parse($week)->endOfWeek();

                $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
            })
            ->when($month && ! $week && ! $date && ! ($dateStart && $dateEnd), function (Builder $query) use ($month) {
                $date = Carbon::parse($month);
                $query->whereMonth('date', $date->month)
                    ->whereYear('date', $date->year);
            })
            ->when($year && ! $month && ! $week && ! $date && ! ($dateStart && $dateEnd), function (Builder $query) use ($year) {
                $date = Carbon::parse($year);
                $query->whereYear('date', $date->year);
            })

            ->when($userId, function (Builder $query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->when($division && ! $userId, function (Builder $query) use ($division) {
                $query->whereHas('user', function (Builder $query) use ($division) {
                    $query->where('division_id', $division);
                });
            })
            ->when($jobTitle && ! $userId, function (Builder $query) use ($jobTitle) {
                $query->whereHas('user', function (Builder $query) use ($jobTitle) {
                    $query->where('job_title_id', $jobTitle);
                });
            })
            ->when($education && ! $userId, function (Builder $query) use ($education) {
                $query->whereHas('user', function (Builder $query) use ($education) {
                    $query->where('education_id', $education);
                });
            });
    }

    public static function clearUserAttendanceCache(Authenticatable $user, Carbon $date)
    {
        if (is_null($user)) {
            return false;
        }
        $date = new ExtendedCarbon($date);
        $monthYear = "$date->month-$date->year";
        $week = $date->yearWeekString();
        $ymd = $date->format('Y-m-d');

        try {
            Cache::forget("attendance-$user->id-$monthYear");
            Cache::forget("attendance-$user->id-$week");
            Cache::forget("attendance-$user->id-$ymd");

            return true;
        } catch (\Throwable $_) {
            return false;
        }
    }
}

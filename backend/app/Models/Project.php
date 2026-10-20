<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, BelongsToCompany;

    protected $appends = ['computed_timeline'];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'status',
        'template',
        'start_date',
        'end_date',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date'  => 'date',
            'end_date'    => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Computed timeline: kalau project punya start_date/end_date manual → pakai itu;
     * kalau NULL → derive dari MIN(task.start_date) / MAX(task.due_date) supaya
     * project tanpa timeline manual tetap punya bar di Gantt.
     *
     * @return array{start: ?string, end: ?string, source: 'manual'|'derived'|'empty'}
     */
    public function getComputedTimelineAttribute(): array
    {
        if ($this->start_date && $this->end_date) {
            return [
                'start'  => $this->start_date->toDateString(),
                'end'    => $this->end_date->toDateString(),
                'source' => 'manual',
            ];
        }

        // Auto-derive - pakai tasks query, hindari load full collection
        $minStart = $this->tasks()->whereNotNull('start_date')->min('start_date');
        $maxDue   = $this->tasks()->whereNotNull('due_date')->max('due_date');
        $maxStart = $this->tasks()->whereNotNull('start_date')->max('start_date');

        // Fallback: kalau start_date kosong, pakai earliest due_date
        $derivedStart = $minStart ?: $this->tasks()->whereNotNull('due_date')->min('due_date');
        // Fallback: kalau due_date kosong, pakai latest start_date
        $derivedEnd   = $maxDue ?: $maxStart;

        if (!$derivedStart && !$derivedEnd) {
            return ['start' => null, 'end' => null, 'source' => 'empty'];
        }

        return [
            'start'  => $this->start_date?->toDateString() ?: $derivedStart,
            'end'    => $this->end_date?->toDateString() ?: $derivedEnd,
            'source' => 'derived',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    public function budget(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProjectBudget::class);
    }
}

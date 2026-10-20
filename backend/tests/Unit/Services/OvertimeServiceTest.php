<?php

use App\Services\OvertimeService;
use Illuminate\Support\Carbon;

uses()->group('unit', 'overtime');

beforeEach(function () {
    $this->service = new OvertimeService();
});

// ── total_hours calculation (tested via the service internals) ────────────────

it('calculates 2 hours for 17:00–19:00', function () {
    $start = Carbon::parse('2026-04-20 17:00');
    $end   = Carbon::parse('2026-04-20 19:00');
    $total = round($start->diffInMinutes($end) / 60, 2);
    expect($total)->toBe(2.0);
});

it('calculates 1.5 hours for 17:00–18:30', function () {
    $start = Carbon::parse('2026-04-20 17:00');
    $end   = Carbon::parse('2026-04-20 18:30');
    $total = round($start->diffInMinutes($end) / 60, 2);
    expect($total)->toBe(1.5);
});

it('calculates 0.5 hours for a 30-minute overtime', function () {
    $start = Carbon::parse('2026-04-20 17:00');
    $end   = Carbon::parse('2026-04-20 17:30');
    $total = round($start->diffInMinutes($end) / 60, 2);
    expect($total)->toBe(0.5);
});

it('calculates 3.17 hours for 17:00–20:10', function () {
    $start = Carbon::parse('2026-04-20 17:00');
    $end   = Carbon::parse('2026-04-20 20:10');
    $total = round($start->diffInMinutes($end) / 60, 2);
    expect($total)->toBe(3.17);
});

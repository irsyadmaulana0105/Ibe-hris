<?php

use App\Services\LeaveService;
use Illuminate\Support\Carbon;

uses()->group('unit', 'leave');

beforeEach(function () {
    $this->service = new LeaveService();
});

// ── countWeekdays ────────────────────────────────────────────────────────────

it('counts a single weekday as 1', function () {
    $monday = Carbon::parse('2026-04-20'); // Monday
    expect($this->service->countWeekdays($monday, $monday))->toBe(1);
});

it('counts Mon–Fri as 5 days', function () {
    $mon = Carbon::parse('2026-04-20');
    $fri = Carbon::parse('2026-04-24');
    expect($this->service->countWeekdays($mon, $fri))->toBe(5);
});

it('excludes Saturday and Sunday from count', function () {
    // Mon–Sun = 5 weekdays
    $mon = Carbon::parse('2026-04-20');
    $sun = Carbon::parse('2026-04-26');
    expect($this->service->countWeekdays($mon, $sun))->toBe(5);
});

it('returns at least 1 when range falls entirely on weekend', function () {
    $sat = Carbon::parse('2026-04-25'); // Saturday
    $sun = Carbon::parse('2026-04-26'); // Sunday
    expect($this->service->countWeekdays($sat, $sun))->toBe(1);
});

it('counts two full work weeks as 10', function () {
    $mon1 = Carbon::parse('2026-04-20');
    $fri2 = Carbon::parse('2026-05-01');
    expect($this->service->countWeekdays($mon1, $fri2))->toBe(10);
});

it('counts a single Saturday as 1 (minimum rule)', function () {
    $sat = Carbon::parse('2026-04-25');
    expect($this->service->countWeekdays($sat, $sat))->toBe(1);
});

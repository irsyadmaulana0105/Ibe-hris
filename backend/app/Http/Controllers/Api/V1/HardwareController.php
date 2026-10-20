<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hardware integration for ZKTeco / Solution / Fingerspot / Magic Passport
 * attendance devices.
 *
 * Devices POST punch events to /api/v1/hardware/push using one of two
 * authentication models:
 *
 *  1. ZKTeco PUSH (Real-Time):
 *     header X-Device-Serial: device serial number registered in
 *     attendance_devices.serial_number. Used for cloud-PUSH ZKTeco models.
 *
 *  2. Solution / Fingerspot poll-back:
 *     Same endpoint receives the batched events. We disambiguate by brand.
 *
 * Body schema (normalized regardless of brand):
 *   {
 *     "device_serial": "ZK12345",
 *     "events": [
 *       { "device_user_id": "001", "timestamp": "2026-05-15 08:01:23", "type": "in" },
 *       { "device_user_id": "001", "timestamp": "2026-05-15 17:05:11", "type": "out" }
 *     ]
 *   }
 *
 * User mapping: We expect attendance_devices to maintain `device_user_id ↔
 * users.id` via the `device_user_id` field on user (TODO: separate table).
 * For now we look up by users.nip == device_user_id when device_user_id
 * present.
 */
class HardwareController extends ApiController
{
    /**
     * Receive a batch of punch events from a hardware device.
     * Stateless - device serial in header authorizes the request.
     */
    public function push(Request $request): JsonResponse
    {
        $serial = $request->header('X-Device-Serial') ?? $request->input('device_serial');
        if (! $serial) {
            return $this->success(['ok' => false, 'reason' => 'missing X-Device-Serial header'], 'Missing serial', 400);
        }

        $device = AttendanceDevice::query()
            ->where('serial_number', $serial)
            ->first();

        if (! $device || ! $device->enabled) {
            Log::warning("Hardware push from unknown/disabled device: {$serial}");

            return $this->success(['ok' => false, 'reason' => 'unknown or disabled device'], 'Unauthorized device', 401);
        }

        if (config('security.hardware_push_require_signature', true)) {
            $this->verifyPushSignature($request, $device);
        }

        $events = (array) $request->input('events', []);
        $processed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $deviceUserId = (string) ($event['device_user_id'] ?? '');
            $timestamp = $event['timestamp'] ?? null;
            $type = $event['type'] ?? 'in'; // in|out
            if (! $deviceUserId || ! $timestamp) {
                $skipped++;

                continue;
            }

            // Look up employee by nip (matches the ID enrolled into the device)
            $user = User::query()
                ->withoutGlobalScopes()
                ->where('company_id', $device->company_id)
                ->where('nip', $deviceUserId)
                ->first();
            if (! $user) {
                $skipped++;

                continue;
            }

            $ts = Carbon::parse($timestamp);
            $date = $ts->toDateString();

            $att = Attendance::query()
                ->withoutGlobalScopes()
                ->firstOrCreate(
                    ['user_id' => $user->id, 'date' => $date],
                    [
                        'status' => 'present',
                    ],
                );

            if ($type === 'in' && ! $att->time_in) {
                $att->time_in = $ts->toTimeString();
                $att->save();
            } elseif ($type === 'out') {
                $att->time_out = $ts->toTimeString();
                $att->save();
            }
            $processed++;
        }

        // Update device sync stats
        $device->update([
            'last_sync_at' => now(),
            'status' => 'online',
            'total_employees_synced' => Attendance::query()
                ->whereHas('user', fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->where('company_id', $device->company_id))
                ->distinct('user_id')
                ->count('user_id'),
        ]);

        return $this->success([
            'device_id' => $device->id,
            'processed' => $processed,
            'skipped' => $skipped,
            'total' => count($events),
        ], "Processed {$processed} events");
    }

    private function verifyPushSignature(Request $request, AttendanceDevice $device): void
    {
        $timestamp = (string) $request->header('X-Device-Timestamp', '');
        $nonce = (string) $request->header('X-Device-Nonce', '');
        $provided = (string) $request->header('X-Device-Signature', '');

        if (! $device->push_secret || ! ctype_digit($timestamp) || strlen($nonce) < 16 || strlen($nonce) > 128) {
            abort(401, 'Signed device request required.');
        }
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            abort(401, 'Device timestamp expired.');
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$request->getContent(), $device->push_secret);
        $signature = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid device signature.');
        }

        $replayKey = 'hardware-push:'.hash('sha256', $device->id.'|'.$timestamp.'|'.$nonce);
        if (! Cache::add($replayKey, true, now()->addMinutes(10))) {
            abort(409, 'Device request replay detected.');
        }
    }

    /**
     * Admin triggers manual sync - for Solution/Fingerspot poll-back model where
     * the device exposes an HTTP API the server pulls from. The actual TCP/REST
     * fetch is delegated to brand-specific driver classes (stubbed for now).
     */
    public function manualPull(Request $request, int $deviceId): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $device = AttendanceDevice::query()->findOrFail($deviceId);

        // Brand-specific driver dispatch (real impl when driver class exists)
        $result = match ($device->brand) {
            'solution', 'fingerspot' => $this->pollDevice($device),
            default => ['pulled' => 0, 'note' => 'PUSH-only brand; no pull required'],
        };

        $device->update(['last_sync_at' => now(), 'status' => $result['pulled'] >= 0 ? 'online' : 'offline']);

        return $this->success($result, 'Sync executed');
    }

    /**
     * Stub for TCP/REST poll. Real driver opens connection to $device->ip_address:$device->port,
     * fetches events since last_sync_at, then loops into the same processing as push().
     */
    private function pollDevice(AttendanceDevice $device): array
    {
        if (! $device->ip_address) {
            return ['pulled' => 0, 'note' => 'No IP configured for device'];
        }

        // TODO: implement real driver (e.g. zk-jubaer/php-zkteco-library, or raw socket)
        // For now: ping + report reachability
        $reachable = @fsockopen($device->ip_address, $device->port, $errno, $errstr, 2);
        if (! $reachable) {
            return ['pulled' => 0, 'note' => "Connection failed: {$errstr}"];
        }
        fclose($reachable);

        return ['pulled' => 0, 'note' => 'Device reachable; full TCP driver pending'];
    }
}

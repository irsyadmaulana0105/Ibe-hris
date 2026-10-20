<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AttendanceDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceDeviceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        return $this->success(
            AttendanceDevice::query()->with('officeLocation:id,name')->orderBy('name')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'brand' => ['required', 'string', Rule::in(['zkteco', 'solution', 'fingerspot', 'magic_passport', 'other'])],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['nullable', 'string', 'max:20'],
            'office_location_id' => ['nullable', 'integer', 'exists:office_locations,id'],
            'enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $secret = 'devsec_'.Str::random(64);
        $device = AttendanceDevice::create([...$data, 'push_secret' => $secret])->load('officeLocation:id,name');

        return $this->created([
            ...$device->toArray(),
            'push_secret' => $secret,
        ], 'Device ditambahkan. Simpan push secret karena hanya ditampilkan sekali.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $device = AttendanceDevice::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'brand' => ['sometimes', 'string', Rule::in(['zkteco', 'solution', 'fingerspot', 'magic_passport', 'other'])],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['sometimes', 'string', 'max:20'],
            'office_location_id' => ['sometimes', 'nullable', 'integer', 'exists:office_locations,id'],
            'enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'rotate_push_secret' => ['sometimes', 'boolean'],
        ]);
        $rotatedSecret = null;
        if (($data['rotate_push_secret'] ?? false) || ! $device->push_secret) {
            $rotatedSecret = 'devsec_'.Str::random(64);
            $data['push_secret'] = $rotatedSecret;
        }
        unset($data['rotate_push_secret']);
        $device->update($data);

        return $this->success([
            ...$device->fresh('officeLocation:id,name')->toArray(),
            'push_secret' => $rotatedSecret,
        ], 'Device diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');
        AttendanceDevice::findOrFail($id)->delete();

        return $this->success(message: 'Device dihapus.');
    }

    /**
     * Stub: ping device + update last_sync_at. Real ZKTeco/Solution drivers
     * akan dipanggil di Sprint hardware integration. Endpoint live so UI
     * tombol "Test Connection" / "Sync Now" tidak return 404.
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $device = AttendanceDevice::findOrFail($id);
        $device->update([
            'last_sync_at' => now(),
            'status' => $device->ip_address ? 'online' : 'offline',
        ]);

        return $this->success($device->fresh(), 'Sync dijalankan.');
    }
}

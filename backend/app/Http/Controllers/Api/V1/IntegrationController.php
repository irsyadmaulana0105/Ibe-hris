<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'integration.manage', 'Integration access required.');

        // Don't expose decrypted credential - only status + masked label
        $items = Integration::query()->orderBy('id')->get()->map(function (Integration $i) {
            $masked = $i->credential ? substr($i->credential, 0, 6) . '…' : null;
            return [
                'id'           => $i->id,
                'key'          => $i->key,
                'name'         => $i->name,
                'category'     => $i->category,
                'icon'         => $i->icon,
                'field_label'  => $i->field_label,
                'status'       => $i->status,
                'description'  => $i->description,
                'masked_credential' => $masked,
            ];
        });
        return $this->success($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'integration.manage', 'Integration access required.');
        $data = $request->validate([
            'key'         => ['required', 'string', 'max:50'],
            'name'        => ['required', 'string', 'max:100'],
            'category'    => ['nullable', 'string', 'max:50'],
            'icon'        => ['nullable', 'string', 'max:10'],
            'field_label' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        $integration = Integration::create([...$data, 'status' => 'disconnected']);

        return $this->created($integration, 'Integrasi dibuat.');
    }

    public function connect(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'integration.manage', 'Integration access required.');

        $integration = Integration::findOrFail($id);
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:2000'],
        ]);
        $integration->credential = $data['credential']; // encrypted via mutator
        $integration->status = 'connected';
        $integration->save();

        return $this->success(message: 'Integrasi terhubung.');
    }

    public function disconnect(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'integration.manage', 'Integration access required.');
        $integration = Integration::findOrFail($id);
        $integration->credential = null;
        $integration->status = 'disconnected';
        $integration->save();
        return $this->success(message: 'Integrasi diputus.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'integration.manage', 'Integration access required.');
        Integration::findOrFail($id)->delete();
        return $this->success(message: 'Integrasi dihapus.');
    }
}

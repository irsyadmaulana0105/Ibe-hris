<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $rows = Activity::query()
            ->where('company_id', $user->company_id)
            ->when($request->string('subjectable_type')->toString(), fn ($w, $t) => $w->where('subjectable_type', $t))
            ->when($request->integer('subjectable_id'), fn ($w, $i) => $w->where('subjectable_id', $i))
            ->orderByDesc('happened_at')
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $request->validate([
            'type' => ['required', 'in:call,email,meeting,note,whatsapp'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string'],
            'subjectable_type' => ['required', 'string', 'max:100'],
            'subjectable_id' => ['required', 'integer'],
            'happened_at' => ['nullable', 'date'],
        ]);
        $data['company_id'] = $user->company_id;
        $data['user_id'] = $user->id;
        $data['happened_at'] = $data['happened_at'] ?? now();

        $row = Activity::create($data);

        return $this->created($row, 'Activity dicatat.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $row = Activity::query()->where('company_id', $user->company_id)->findOrFail($id);
        // Hanya owner activity atau admin yang boleh hapus
        if ($row->user_id !== $user->id) {
            $this->requireCapability($user, 'crm.admin');
        }
        $row->delete();

        return $this->success(message: 'Activity dihapus.');
    }
}

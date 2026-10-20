<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterDisposition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LetterDispositionController extends ApiController
{
    public function index(Request $request, string $publicId): JsonResponse
    {
        $this->requireDispositionCapability($request, 'documents.letter.view', 'list', $publicId);
        $letter = $this->letter($request, $publicId);

        return $this->success($letter->dispositions()->with(['fromUser:id,name', 'toUser:id,name'])->get());
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $this->requireDispositionCapability($request, 'documents.letter.manage', 'create', $publicId);
        $letter = $this->letter($request, $publicId);
        $request->user()->can('view', $letter) ?: abort(403);
        $data = $request->validate([
            'to_user_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('company_id', $request->user()->company_id)),
            ],
            'action_type' => ['required', 'string', 'in:tindak_lanjut,untuk_perhatian,untuk_diketahui,untuk_diproses,untuk_dibalas'],
            'instruction' => ['nullable', 'string', 'max:500'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $d = LetterDisposition::create(array_merge($data, [
            'letter_id' => $letter->id,
            'from_user_id' => $request->user()->id,
            'completed' => false,
        ]));

        return $this->created($d->load(['fromUser:id,name', 'toUser:id,name']));
    }

    public function complete(Request $request, string $publicId, int $dispId): JsonResponse
    {
        $this->requireDispositionCapability($request, 'documents.letter.manage', 'complete', $publicId);
        $letter = $this->letter($request, $publicId);
        $d = LetterDisposition::where('id', $dispId)->where('letter_id', $letter->id)->firstOrFail();
        if ($d->to_user_id !== $request->user()->id && ! $request->user()->isAdmin) {
            abort(403, 'Hanya disposisi receiver atau admin yang boleh mark complete.');
        }
        $d->update(['completed' => true, 'completed_at' => now()]);

        return $this->success($d);
    }

    public function destroy(Request $request, string $publicId, int $dispId): JsonResponse
    {
        $this->requireDispositionCapability($request, 'documents.letter.manage', 'delete', $publicId);
        $letter = $this->letter($request, $publicId);
        $d = LetterDisposition::where('id', $dispId)->where('letter_id', $letter->id)->firstOrFail();
        if ($d->from_user_id !== $request->user()->id && ! $request->user()->isAdmin) {
            abort(403, 'Hanya pembuat disposisi atau admin yang boleh hapus.');
        }
        $d->delete();

        return $this->success(null, 'Disposisi dihapus');
    }

    private function letter(Request $request, string $publicId): Letter
    {
        return Letter::where('public_id', $publicId)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
    }

    private function requireDispositionCapability(
        Request $request,
        string $capability,
        string $action,
        string $resourceId,
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter disposition capability required.',
            [
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_disposition',
                'resource_id' => $resourceId,
                'action' => $action,
            ],
        );
    }
}

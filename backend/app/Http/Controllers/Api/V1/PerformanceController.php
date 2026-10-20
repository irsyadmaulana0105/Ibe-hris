<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NineBoxRating;
use App\Models\Okr;
use App\Models\PerformanceReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PerformanceController extends ApiController
{
    // ── OKR ─────────────────────────────────────────────────────────────────
    public function indexOkrs(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $okrs = Okr::query()
            ->with('user:id,name,profile_photo_path,email')
            ->orderByDesc('id')
            ->get();

        return $this->success($okrs);
    }

    public function storeOkr(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $data = $request->validate([
            'user_id'   => ['required', 'string', 'exists:users,id'],
            'objective' => ['required', 'string', 'max:500'],
            'metric'    => ['required', 'string', 'max:500'],
            'target'    => ['required', 'numeric', 'min:0'],
            'progress'  => ['nullable', 'integer', 'min:0', 'max:100'],
            'due'       => ['nullable', 'date'],
            'status'    => ['nullable', 'string', Rule::in(['draft', 'on_track', 'behind', 'at_risk', 'completed'])],
        ]);

        $okr = Okr::create($data);

        return $this->created($okr->load('user:id,name'), 'OKR ditambahkan.');
    }

    public function updateOkr(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $okr = Okr::findOrFail($id);
        $data = $request->validate([
            'objective' => ['sometimes', 'string', 'max:500'],
            'metric'    => ['sometimes', 'string', 'max:500'],
            'target'    => ['sometimes', 'numeric', 'min:0'],
            'progress'  => ['sometimes', 'integer', 'min:0', 'max:100'],
            'due'       => ['sometimes', 'nullable', 'date'],
            'status'    => ['sometimes', 'string', Rule::in(['draft', 'on_track', 'behind', 'at_risk', 'completed'])],
        ]);
        $okr->update($data);

        return $this->success($okr->fresh('user:id,name'), 'OKR diperbarui.');
    }

    public function destroyOkr(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');
        Okr::findOrFail($id)->delete();
        return $this->success(message: 'OKR dihapus.');
    }

    // ── 9-Box Matrix ───────────────────────────────────────────────────────
    public function indexNineBox(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');
        return $this->success(NineBoxRating::query()->with('user:id,name,profile_photo_path')->get());
    }

    public function upsertNineBox(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $data = $request->validate([
            'user_id'     => ['required', 'string', 'exists:users,id'],
            'performance' => ['required', 'integer', 'min:1', 'max:3'],
            'potential'   => ['required', 'integer', 'min:1', 'max:3'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $companyId = $request->user()->company_id;
        $rating = NineBoxRating::query()
            ->where('company_id', $companyId)
            ->where('user_id', $data['user_id'])
            ->first();

        if ($rating) {
            $rating->update([...$data, 'rated_by' => $request->user()->id]);
        } else {
            $rating = NineBoxRating::create([
                ...$data,
                'company_id' => $companyId,
                'rated_by' => $request->user()->id,
            ]);
        }

        return $this->success($rating->load('user:id,name'), 'Rating disimpan.');
    }

    public function destroyNineBox(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');
        NineBoxRating::findOrFail($id)->delete();
        return $this->success(message: 'Rating dihapus.');
    }

    // ── Review Cycle ───────────────────────────────────────────────────────
    public function indexReviews(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');
        return $this->success(PerformanceReview::query()->with('user:id,name')->orderByDesc('id')->get());
    }

    public function storeReview(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'cycle'   => ['required', 'string', 'max:30'],
            'stage'   => ['nullable', 'string', Rule::in(['draft', 'self', 'manager', 'calibration', 'finalize'])],
        ]);

        $review = PerformanceReview::create([
            ...$data,
            'reviewer_id' => $request->user()->id,
        ]);

        return $this->created($review->load('user:id,name'), 'Review dibuat.');
    }

    public function updateReview(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'performance.manage', 'Performance access required.');

        $review = PerformanceReview::findOrFail($id);
        $data = $request->validate([
            'stage'            => ['sometimes', 'string', Rule::in(['draft', 'self', 'manager', 'calibration', 'finalize'])],
            'self_score'       => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'manager_score'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'final_score'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'self_comments'    => ['sometimes', 'nullable', 'string'],
            'manager_comments' => ['sometimes', 'nullable', 'string'],
        ]);
        $review->update($data);

        return $this->success($review->fresh('user:id,name'), 'Review diperbarui.');
    }
}

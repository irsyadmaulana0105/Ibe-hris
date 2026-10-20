<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Candidate;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecruitmentController extends ApiController
{
    // ── Job Postings ────────────────────────────────────────────────────────
    public function indexJobs(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        return $this->success(
            JobPosting::query()
                ->withCount('candidates')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function storeJob(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'division' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(['fulltime', 'contract', 'intern'])],
            'description' => ['nullable', 'string'],
            'salary_range' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['open', 'closed'])],
        ]);

        $job = JobPosting::create([
            ...$data,
            'status' => $data['status'] ?? 'open',
            'posted_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        return $this->created($job, 'Lowongan dibuka.');
    }

    public function updateJob(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        $job = JobPosting::findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'division' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(['fulltime', 'contract', 'intern'])],
            'description' => ['sometimes', 'nullable', 'string'],
            'salary_range' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'closed'])],
        ]);

        if (($data['status'] ?? null) === 'closed' && ! $job->closed_at) {
            $data['closed_at'] = now();
        }

        $job->update($data);

        return $this->success($job->fresh(), 'Lowongan diperbarui.');
    }

    public function destroyJob(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');
        JobPosting::findOrFail($id)->delete();

        return $this->success(message: 'Lowongan dihapus.');
    }

    // ── Candidates ─────────────────────────────────────────────────────────
    public function indexCandidates(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        return $this->success(
            Candidate::query()
                ->with('jobPosting:id,title')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function storeCandidate(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        $data = $request->validate([
            'job_posting_id' => ['required', 'integer', 'exists:job_postings,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'cv_url' => ['prohibited'],
            'source' => ['nullable', 'string', 'max:50'],
            'stage' => ['nullable', 'string', Rule::in(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])],
            'notes' => ['nullable', 'string'],
        ]);

        $candidate = Candidate::create([
            ...$data,
            'stage' => $data['stage'] ?? 'applied',
            'source' => $data['source'] ?? 'Website',
            'applied_at' => now(),
        ]);

        return $this->created($candidate->load('jobPosting:id,title'), 'Kandidat ditambahkan.');
    }

    public function updateCandidate(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        $candidate = Candidate::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'cv_url' => ['prohibited'],
            'source' => ['sometimes', 'string', 'max:50'],
            'stage' => ['sometimes', 'string', Rule::in(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $candidate->update($data);

        return $this->success($candidate->fresh('jobPosting:id,title'), 'Kandidat diperbarui.');
    }

    public function destroyCandidate(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');
        Candidate::findOrFail($id)->delete();

        return $this->success(message: 'Kandidat dihapus.');
    }
}

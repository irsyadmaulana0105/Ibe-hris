<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LearningController extends ApiController
{
    // ── Courses ─────────────────────────────────────────────────────────────
    public function indexCourses(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');

        return $this->success(
            Course::query()->withCount('enrollments')->orderByDesc('id')->get(),
        );
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'category'    => ['nullable', 'string', 'max:100'],
            'provider'    => ['nullable', 'string', 'max:150'],
            'hours'       => ['nullable', 'integer', 'min:0'],
            'type'        => ['required', 'string', Rule::in(['internal', 'external'])],
            'url'         => ['nullable', 'string', 'max:500'],
            'mandatory'   => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created(Course::create($data), 'Course ditambahkan.');
    }

    public function updateCourse(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        $course = Course::findOrFail($id);
        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:200'],
            'category'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'provider'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'hours'       => ['sometimes', 'integer', 'min:0'],
            'type'        => ['sometimes', 'string', Rule::in(['internal', 'external'])],
            'url'         => ['sometimes', 'nullable', 'string', 'max:500'],
            'mandatory'   => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);
        $course->update($data);
        return $this->success($course->fresh(), 'Course diperbarui.');
    }

    public function destroyCourse(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        Course::findOrFail($id)->delete();
        return $this->success(message: 'Course dihapus.');
    }

    // ── Enrollments ────────────────────────────────────────────────────────
    public function indexEnrollments(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        return $this->success(
            Enrollment::query()->with(['user:id,name,profile_photo_path', 'course:id,title'])->orderByDesc('id')->get(),
        );
    }

    public function storeEnrollment(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');

        $data = $request->validate([
            'user_id'   => ['required', 'string', 'exists:users,id'],
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'progress'  => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $progress = $data['progress'] ?? 0;
        $enrollment = Enrollment::firstOrCreate(
            ['course_id' => $data['course_id'], 'user_id' => $data['user_id']],
            [
                'progress' => $progress,
                'status' => $progress >= 100 ? 'completed' : 'in_progress',
                'enrolled_at' => now(),
                'completed_at' => $progress >= 100 ? now() : null,
            ],
        );

        return $this->created($enrollment->load(['user:id,name', 'course:id,title']), 'Karyawan terdaftar.');
    }

    public function updateEnrollment(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        $enrollment = Enrollment::findOrFail($id);
        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        $enrollment->update([
            'progress' => $data['progress'],
            'status' => $data['progress'] >= 100 ? 'completed' : 'in_progress',
            'completed_at' => $data['progress'] >= 100 ? now() : null,
        ]);
        return $this->success($enrollment->fresh(['user:id,name', 'course:id,title']), 'Progress diperbarui.');
    }

    public function destroyEnrollment(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        Enrollment::findOrFail($id)->delete();
        return $this->success(message: 'Enrollment dihapus.');
    }

    // ── Certificates ───────────────────────────────────────────────────────
    public function indexCertificates(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        return $this->success(
            Certificate::query()->with(['user:id,name,profile_photo_path', 'course:id,title'])->orderByDesc('id')->get(),
        );
    }

    public function storeCertificate(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');

        $data = $request->validate([
            'user_id'    => ['required', 'string', 'exists:users,id'],
            'course_id'  => ['nullable', 'integer', 'exists:courses,id'],
            'name'       => ['required', 'string', 'max:200'],
            'issuer'     => ['nullable', 'string', 'max:150'],
            'issued_at'  => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file_url'   => ['nullable', 'string', 'max:500'],
        ]);

        return $this->created(
            Certificate::create($data)->load(['user:id,name', 'course:id,title']),
            'Sertifikat diupload.',
        );
    }

    public function destroyCertificate(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'learning.manage', 'Learning access required.');
        Certificate::findOrFail($id)->delete();
        return $this->success(message: 'Sertifikat dihapus.');
    }
}

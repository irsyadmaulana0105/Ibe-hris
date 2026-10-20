<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AttendanceReimbursementMediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PrivateMediaController extends ApiController
{
    public function __construct(private readonly AttendanceReimbursementMediaService $media) {}

    public function attendance(Request $request, int $attendance, string $field): Response
    {
        return $this->media->authenticatedAttendance($request->user(), $attendance, $field);
    }

    public function reimbursement(Request $request, int $reimbursement, string $field): Response
    {
        return $this->media->authenticatedReimbursement($request->user(), $reimbursement, $field);
    }

    public function signedAttendance(int $attendance, string $field): Response
    {
        return $this->media->signedAttendance($attendance, $field);
    }

    public function signedReimbursement(int $reimbursement, string $field): Response
    {
        return $this->media->signedReimbursement($reimbursement, $field);
    }

    public function signedFace(string $user, string $field): Response
    {
        return \App\Support\FotoWajah::response(\App\Models\User::query()->findOrFail($user), $field);
    }
}

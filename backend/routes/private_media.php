<?php

use App\Http\Controllers\Api\V1\PrivateMediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('/private-media/attendances/{attendance}/{field}', [PrivateMediaController::class, 'signedAttendance'])
        ->whereNumber('attendance')
        ->whereIn('field', ['selfie-in', 'selfie-out', 'attachment'])
        ->middleware(['signed', 'throttle:120,1'])
        ->name('api.v1.private-media.attendance');
    Route::get('/private-media/reimbursements/{reimbursement}/{field}', [PrivateMediaController::class, 'signedReimbursement'])
        ->whereNumber('reimbursement')
        ->whereIn('field', ['attachment', 'payment-proof', 'receipt'])
        ->middleware(['signed', 'throttle:120,1'])
        ->name('api.v1.private-media.reimbursement');
    // Foto pendaftaran wajah untuk HR (URL dibuat FaceApprovalController, 5 menit).
    Route::get('/private-media/face/{user}/{field}', [PrivateMediaController::class, 'signedFace'])
        ->whereIn('field', ['pending', 'active'])
        ->middleware(['signed', 'throttle:120,1'])
        ->name('api.v1.private-media.face');

    Route::middleware(['auth:sanctum', 'block.inactive', 'tenant.required', 'module.routes'])->group(function (): void {
        Route::get('/attendances/{attendance}/media/{field}', [PrivateMediaController::class, 'attendance'])
            ->whereNumber('attendance')
            ->whereIn('field', ['selfie-in', 'selfie-out', 'attachment'])
            ->middleware('throttle:120,1');
        Route::get('/reimbursements/{reimbursement}/media/{field}', [PrivateMediaController::class, 'reimbursement'])
            ->whereNumber('reimbursement')
            ->whereIn('field', ['attachment', 'payment-proof', 'receipt'])
            ->middleware('throttle:120,1');
    });
});

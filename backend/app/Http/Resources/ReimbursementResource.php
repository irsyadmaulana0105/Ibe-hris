<?php

namespace App\Http\Resources;

use App\Models\Reimbursement;
use App\Services\AttendanceReimbursementMediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reimbursement */
class ReimbursementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $request->user() ? app(AttendanceReimbursementMediaService::class) : null;
        $attachmentUrl = $media?->reimbursementUrl($request->user(), $this->resource, 'attachment');
        $paymentProofUrl = $media?->reimbursementUrl($request->user(), $this->resource, 'payment-proof');
        $receiptUrl = $media?->reimbursementUrl($request->user(), $this->resource, 'receipt');

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'event' => $this->event,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'total_amount' => $this->total_amount,
            'approved_amount' => $this->approved_amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'attachment_url' => $attachmentUrl,
            'attachment_available' => $attachmentUrl !== null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            // Bank info (untuk Finance saat transfer)
            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_account_holder' => $this->bank_account_holder,

            // HR approval stage
            'hr_approved_at' => $this->hr_approved_at?->toIso8601String(),
            'hr_notes' => $this->hr_notes,

            // Finance settlement stage
            'settled_at' => $this->settled_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'payment_proof_url' => $paymentProofUrl,
            'payment_proof_available' => $paymentProofUrl !== null,

            // Auto-generated kwitansi
            'receipt_number' => $this->receipt_number,
            'receipt_pdf_url' => $receiptUrl,
            'receipt_pdf_available' => $receiptUrl !== null,

            'category' => $this->whenLoaded('category', fn () => $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name, 'limit_amount' => $this->category->limit_amount]
                : null
            ),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'nip' => $this->user->nip,
                'division' => $this->user->division?->name,
                'avatar' => $this->user->profile_photo_url,
            ]),

            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),

            'hr_approved_by' => $this->whenLoaded('hrApprovedBy', fn () => $this->hrApprovedBy
                ? ['id' => $this->hrApprovedBy->id, 'name' => $this->hrApprovedBy->name]
                : null
            ),

            'settled_by' => $this->whenLoaded('settledBy', fn () => $this->settledBy
                ? ['id' => $this->settledBy->id, 'name' => $this->settledBy->name]
                : null
            ),
        ];
    }
}

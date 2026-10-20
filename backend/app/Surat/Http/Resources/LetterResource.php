<?php

namespace App\Surat\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Surat\Models\Letter */
class LetterResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'public_id'          => $this->public_id,
            'direction'          => $this->direction ?? 'out',
            'category_id'        => $this->category_id,
            'category'           => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),
            'template_id'        => $this->template_id,
            'letter_number'      => $this->letter_number,
            'letter_date'        => optional($this->letter_date)->toDateString(),
            'expiry_date'        => optional($this->expiry_date)->toDateString(),
            'subject'            => $this->subject,
            'purpose'            => $this->purpose,
            'target_code'        => $this->target_code,
            'recipient'          => [
                'name'    => $this->recipient_name,
                'title'   => $this->recipient_title,
                'company' => $this->recipient_company,
                'address' => $this->recipient_address,
                'email'   => $this->recipient_email,
            ],
            'sender'             => [
                'name'           => $this->sender_name,
                'company'        => $this->sender_company,
                'original_number'=> $this->original_number,
                'received_date'  => optional($this->received_date)->toDateString(),
            ],
            'cc_list'            => $this->cc_list ?? [],
            'qr_token'           => $this->qr_token,
            'parent_letter'      => $this->whenLoaded('parentLetter', fn () => $this->parentLetter ? [
                'public_id'     => $this->parentLetter->public_id,
                'letter_number' => $this->parentLetter->letter_number,
                'subject'       => $this->parentLetter->subject,
            ] : null),
            'replies'            => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($r) => [
                'public_id'     => $r->public_id,
                'letter_number' => $r->letter_number,
                'subject'       => $r->subject,
            ])),
            'attachments'        => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id'             => $a->id,
                'original_name'  => $a->original_name,
                'mime_type'      => $a->mime_type,
                'size_bytes'     => $a->size_bytes,
                'uploaded_by'    => $a->uploadedBy?->name,
                'created_at'     => optional($a->created_at)->toIso8601String(),
            ])),
            'dispositions'       => $this->whenLoaded('dispositions', fn () => $this->dispositions->map(fn ($d) => [
                'id'           => $d->id,
                'action_type'  => $d->action_type,
                'instruction'  => $d->instruction,
                'deadline'     => optional($d->deadline)->toDateString(),
                'completed'    => $d->completed,
                'from_user'    => $d->fromUser ? ['id' => $d->fromUser->id, 'name' => $d->fromUser->name] : null,
                'to_user'      => $d->toUser ? ['id' => $d->toUser->id, 'name' => $d->toUser->name] : null,
                'created_at'   => optional($d->created_at)->toIso8601String(),
            ])),
            'body_html'          => $this->body_html,
            'body_data'          => $this->body_data,
            'attachments_meta'   => $this->sanitizeAttachmentMetadata($this->attachments_meta),
            'status'             => $this->status,
            'is_locked'          => $this->isLocked(),
            'can_transition_to'  => \App\Surat\Models\Letter::TRANSITIONS[$this->status] ?? [],
            'author'             => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id, 'name' => $this->author->name,
            ]),
            'signer'             => $this->whenLoaded('signer', fn () => $this->signer ? [
                'id'    => $this->signer->id,
                'name'  => $this->signer->name,
                'title' => $this->signer->title,
            ] : null),
            'cosigner'           => $this->whenLoaded('cosigner', fn () => $this->cosigner ? [
                'id'    => $this->cosigner->id,
                'name'  => $this->cosigner->name,
                'title' => $this->cosigner->title,
            ] : null),
            'bank_id'            => $this->bank_id,
            'bank'               => $this->whenLoaded('bank', fn () => $this->bank ? [
                'id'             => $this->bank->id,
                'bank_name'      => $this->bank->bank_name,
                'account_number' => $this->bank->account_number,
                'account_name'   => $this->bank->account_name,
                'display'        => $this->bank->asResolvedString(),
            ] : null),
            'submitted_at'       => optional($this->submitted_at)->toIso8601String(),
            'approved_at'        => optional($this->approved_at)->toIso8601String(),
            'signed_at'          => optional($this->signed_at)->toIso8601String(),
            'sent_at'            => optional($this->sent_at)->toIso8601String(),
            'pdf_available'      => filled($this->pdf_path),
            'confidential'       => (bool) $this->confidential,
            'tags'               => $this->tags ?? [],
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }

    private function sanitizeAttachmentMetadata(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sensitiveKeys = [
            'path', 'storage_path', 'disk', 'url', 'uri', 'href',
            'public_url', 'private_url', 'signed_url', 'secret_url', 'storage_key',
        ];
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                continue;
            }
            $sanitized[$key] = $this->sanitizeAttachmentMetadata($item);
        }

        return $sanitized;
    }
}

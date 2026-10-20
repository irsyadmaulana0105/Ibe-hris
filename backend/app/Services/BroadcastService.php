<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Resolve audience filter -> recipient list, lalu deliver broadcast via
 * email + persistent inbox row. Email failure di-log tapi tidak gagalkan
 * keseluruhan broadcast (best-effort delivery - karyawan masih bisa lihat
 * di portal inbox kalau email tidak sampai).
 *
 * Audience filter shape (semua OR-combined):
 *   {
 *     "all": bool,
 *     "groups": ["user","admin","superadmin","finance"],
 *     "division_ids": ["..."],
 *     "employment_statuses": ["permanent","probation","intern_campus","intern_hub","pkl_smk"],
 *     "user_ids": ["..."],
 *   }
 */
class BroadcastService
{
    /**
     * Resolve filter ke list of users (sudah scoped by company).
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function resolveAudience(int $companyId, array $filter): \Illuminate\Database\Eloquent\Collection
    {
        $q = User::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('email');

        if (!empty($filter['all'])) {
            return $q->get();
        }

        $q->where(function ($w) use ($filter) {
            $matched = false;
            if (!empty($filter['groups']) && is_array($filter['groups'])) {
                $w->orWhereIn('group', $filter['groups']);
                $matched = true;
            }
            if (!empty($filter['division_ids']) && is_array($filter['division_ids'])) {
                $w->orWhereIn('division_id', $filter['division_ids']);
                $matched = true;
            }
            if (!empty($filter['employment_statuses']) && is_array($filter['employment_statuses'])) {
                $w->orWhereIn('employment_status', $filter['employment_statuses']);
                $matched = true;
            }
            if (!empty($filter['user_ids']) && is_array($filter['user_ids'])) {
                $w->orWhereIn('id', $filter['user_ids']);
                $matched = true;
            }
            if (!$matched) {
                // No filter selected = empty result (defensive - caller should pass `all:true` instead)
                $w->whereRaw('1=0');
            }
        });

        return $q->get();
    }

    /**
     * Persist broadcast + enumerate recipients + (optionally) send email.
     * Returns [broadcast, sentCount, emailSentCount].
     */
    public function send(User $sender, array $payload): array
    {
        $audience = $this->resolveAudience((int) $sender->company_id, $payload['audience_filter']);

        return DB::transaction(function () use ($sender, $payload, $audience) {
            /** @var Broadcast $bc */
            $bc = Broadcast::create([
                'company_id'      => $sender->company_id,
                'sent_by_user_id' => $sender->id,
                'subject'         => $payload['subject'],
                'body'            => $payload['body'],
                'priority'        => $payload['priority'] ?? 'normal',
                'audience_filter' => $payload['audience_filter'],
                'send_email'      => (bool) ($payload['send_email'] ?? true),
                'recipient_count' => $audience->count(),
                'sent_at'         => now(),
            ]);

            $rows = $audience->map(fn (User $u) => [
                'id'           => (string) Str::ulid(),
                'broadcast_id' => $bc->id,
                'user_id'      => $u->id,
                'email_sent_at'=> null,
                'read_at'      => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ])->all();
            if (!empty($rows)) {
                BroadcastRecipient::insert($rows);
            }

            $emailSent = 0;
            if ($bc->send_email) {
                $priorityLabel = match ($bc->priority) {
                    'urgent'    => '🚨 URGENT',
                    'important' => 'PENTING',
                    default     => '',
                };
                $subjectPrefix = $priorityLabel ? "[{$priorityLabel}] " : '';

                foreach ($audience as $user) {
                    try {
                        $body = $this->formatEmailBody($user, $bc, $sender);
                        Mail::raw($body, function ($m) use ($user, $bc, $subjectPrefix, $sender) {
                            $m->to($user->email, $user->name)
                              ->subject("[IBE Reality] {$subjectPrefix}{$bc->subject}");
                            if ($sender->email) {
                                $m->replyTo($sender->email, $sender->name);
                            }
                        });
                        BroadcastRecipient::where('broadcast_id', $bc->id)
                            ->where('user_id', $user->id)
                            ->update(['email_sent_at' => now()]);
                        $emailSent++;
                    } catch (\Throwable $e) {
                        Log::warning("Broadcast email failed for {$user->email}: " . $e->getMessage());
                    }
                }
            }

            $bc->update(['email_sent_count' => $emailSent]);

            return [$bc->fresh(), $audience->count(), $emailSent];
        });
    }

    private function formatEmailBody(User $recipient, Broadcast $bc, User $sender): string
    {
        $signature = $sender->name . ($sender->isHr ? ' - HR' : ($sender->isSuperadmin ? ' - Manajemen' : ''));
        return "Halo {$recipient->name},\n\n{$bc->body}\n\nSalam,\n{$signature}\nIBE Reality";
    }
}

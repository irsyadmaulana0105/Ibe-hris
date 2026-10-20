<?php

namespace App\Services;

use App\Mail\ProjectTaskNotificationMail;
use App\Models\ProjectNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Creates ProjectNotification rows for the recipient(s) when something
 * happens that they should know about - @mention in comment, assignment,
 * dependency added, etc. Caller filters self (don't notify the actor).
 */
class Notifier
{
    public function notify(
        ?User $actor,
        User|string $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?Task $task = null,
    ): void {
        $recipientId = $recipient instanceof User ? $recipient->id : $recipient;

        // Don't notify yourself
        if ($actor && $actor->id === $recipientId) return;

        $notification = ProjectNotification::create([
            'user_id'    => $recipientId,
            'actor_id'   => $actor?->id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'task_id'    => $task?->id,
            'project_id' => $task?->project_id,
            'created_at' => now(),
        ]);

        // Off-band email for high-signal types only (mention/assigned).
        // Comment-on-assigned skipped to avoid spamming people who'll see it
        // in the bell anyway. User can opt-out via their preference flag.
        $this->maybeSendEmail($notification, $type, $recipientId);
    }

    /**
     * Queue an email if the notification type warrants one AND the recipient
     * hasn't opted out. Failures are logged but never bubble up - email
     * delivery must not block the originating action.
     */
    private function maybeSendEmail(ProjectNotification $notification, string $type, string $recipientId): void
    {
        if (! in_array($type, ['mention', 'assigned'], true)) return;

        try {
            $recipient = User::query()
                ->withoutGlobalScopes()
                ->where('id', $recipientId)
                ->first(['id', 'name', 'email', 'project_email_notifications_enabled']);

            if (! $recipient || ! $recipient->email) return;
            if (! ($recipient->project_email_notifications_enabled ?? true)) return;

            // Eager-load relations the email template needs so the queue job
            // doesn't refetch them at render time.
            $notification->load([
                'actor:id,name,profile_photo_path',
                'task:id,project_id,title',
                'project:id,name',
            ]);

            Mail::to($recipient->email)->queue(new ProjectTaskNotificationMail($notification));
        } catch (\Throwable $e) {
            Log::warning('Notifier maybeSendEmail failed', [
                'recipient' => $recipientId,
                'type'      => $type,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse @mentions in a body and notify each mentioned user.
     * Mention syntax: @"Full Name" or @username (alphanumeric until whitespace/punct).
     * Resolves against project members first, then full company users.
     */
    public function notifyMentions(?User $actor, Task $task, string $body): void
    {
        $names = $this->extractMentionTokens($body);
        if (empty($names)) return;

        $project = $task->project()->with('members.user:id,name,email,company_id')->first();
        if (! $project) return;

        $memberUsers = $project->members->pluck('user')->filter();
        $companyId = $project->company_id;

        foreach ($names as $name) {
            $needle = mb_strtolower($name);
            // Try member match first (cheaper + scope-correct)
            $target = $memberUsers->first(function ($u) use ($needle) {
                return mb_strtolower($u->name) === $needle
                    || mb_stripos($u->name, $needle) === 0
                    || mb_strtolower($u->email ?? '') === $needle;
            });

            if (! $target) {
                $target = User::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($name, $needle) {
                        $q->where('name', 'like', $name . '%')
                          ->orWhere('email', $needle);
                    })
                    ->first();
            }

            if ($target) {
                $this->notify(
                    $actor,
                    $target,
                    'mention',
                    ($actor?->name ?? 'Seseorang') . ' menyebut Anda di "' . $task->title . '"',
                    mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body,
                    $task,
                );
            }
        }
    }

    /**
     * Find @tokens in a string. Supports both:
     *   @SimpleName  (consecutive letters/digits/space-delimited word)
     *   @"Aji Sapta" (quoted to allow spaces)
     *
     * Returns deduplicated list of raw token strings (without the @).
     */
    private function extractMentionTokens(string $body): array
    {
        $names = [];

        // Quoted: @"Full Name"
        if (preg_match_all('/@"([^"]{1,80})"/u', $body, $m)) {
            foreach ($m[1] as $n) $names[] = trim($n);
        }

        // Simple: @word (Unicode-letter-aware)
        if (preg_match_all('/@([\p{L}][\p{L}\p{N}_.-]{1,40})/u', $body, $m)) {
            foreach ($m[1] as $n) $names[] = trim($n);
        }

        return array_values(array_unique(array_filter($names)));
    }
}

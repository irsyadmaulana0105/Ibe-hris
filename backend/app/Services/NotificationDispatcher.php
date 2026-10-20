<?php

namespace App\Services;

use App\Mail\EventNotificationMail;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central dispatcher for transactional notifications.
 *
 * Loads the per-company NotificationTemplate for an event_key, interpolates
 * `{{variable}}` placeholders, and fans out to enabled channels:
 *   - email  → real Mailable via configured SMTP (Stalwart in prod)
 *   - whatsapp → stub log (real consumer wired when Integration::whatsapp connected)
 *   - slack  → stub log (real webhook fired when Integration::slack connected)
 *
 * Service layers (LeaveService, OvertimeService, PayrollService, etc) call
 * `dispatch($event_key, $recipient, $vars)` after every approve/reject so the
 * employee gets a same-day notification.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Fire all enabled channels for an event.
     *
     * @param  string  $eventKey   Matches notification_templates.event_key
     * @param  User    $recipient  The employee to notify
     * @param  array<string,scalar|null>  $vars  Replaces {{key}} in template body
     * @return array{email:bool, whatsapp:bool, slack:bool, template_found:bool}
     */
    public function dispatch(string $eventKey, User $recipient, array $vars = []): array
    {
        $template = NotificationTemplate::query()
            ->where('company_id', $recipient->company_id)
            ->where('event_key', $eventKey)
            ->first();

        if (!$template) {
            // Template missing - skip silently so business flow isn't blocked
            return ['email' => false, 'whatsapp' => false, 'slack' => false, 'template_found' => false];
        }

        // Always-on variables - recipient identity
        $vars = array_merge([
            'user_name' => $recipient->name,
            'user_email' => $recipient->email,
        ], $vars);

        $rendered = $this->interpolate($template->body, $vars);
        $result = ['template_found' => true, 'email' => false, 'whatsapp' => false, 'slack' => false];

        if ($template->email_enabled && filled($recipient->email)) {
            try {
                Mail::to($recipient->email)
                    ->queue(new EventNotificationMail(
                        eventLabel: $template->event_label,
                        renderedBody: $rendered,
                        recipientName: $recipient->name,
                    ));
                $result['email'] = true;
            } catch (\Throwable $e) {
                Log::warning("Notification email failed [{$eventKey}]: " . $e->getMessage());
            }
        }

        if ($template->whatsapp_enabled) {
            // Stub - real consumer lit up via Integration::whatsapp credentials
            Log::info("[notif:whatsapp] {$eventKey} → {$recipient->phone}: {$rendered}");
            $result['whatsapp'] = true;
        }

        if ($template->slack_enabled) {
            // Stub - webhook URL from Integration::slack will replace this
            Log::info("[notif:slack] {$eventKey} → @{$recipient->name}: {$rendered}");
            $result['slack'] = true;
        }

        $this->audit->log(
            action: "notification.{$eventKey}",
            actor: $recipient,
            entityType: 'notification',
            entityId: $template->id,
            meta: ['channels' => array_keys(array_filter($result))],
        );

        return $result;
    }

    /**
     * Render a test notification (no audit log, no DB lookup) - used by the
     * "Test Kirim" button so admins can preview their template against their
     * own email before activating it.
     */
    public function testSend(NotificationTemplate $template, User $recipient, array $vars = []): bool
    {
        $vars = array_merge(['user_name' => $recipient->name], $vars);
        $rendered = '[TEST] ' . $this->interpolate($template->body, $vars);

        try {
            Mail::to($recipient->email)
                ->send(new EventNotificationMail(
                    eventLabel: "[TEST] {$template->event_label}",
                    renderedBody: $rendered,
                    recipientName: $recipient->name,
                ));
            return true;
        } catch (\Throwable $e) {
            Log::warning("Notification test send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Replace {{key}} occurrences with their string value. Missing keys
     * become an em-dash so the rendered body never leaks raw {{tokens}} to
     * employees.
     */
    private function interpolate(string $body, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return (string) ($vars[$m[1]] ?? '-');
        }, $body) ?? $body;
    }
}

<?php

namespace App\Mail;

use App\Models\ProjectNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Off-band notification for the project app - sent when in-app notification
 * is created and recipient has email notifications enabled.
 *
 * Queued so HTTP request that triggered the notification (comment post,
 * task assignment) doesn't block on SMTP.
 */
class ProjectTaskNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ProjectNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Projek] ' . $this->notification->title,
        );
    }

    public function content(): Content
    {
        // Frontend SPA lives on attendance.ibereality.com - link straight
        // to the project detail page so the recipient lands on the right task.
        $frontendBase = rtrim(config('app.project_frontend_url', 'https://app-project.ibereality.com'), '/');
        $projectId = $this->notification->project_id;
        $deepLink = $projectId
            ? "{$frontendBase}/projects/{$projectId}"
            : $frontendBase;

        return new Content(
            view: 'emails.project-task-notification',
            with: [
                'notif'   => $this->notification,
                'actor'   => $this->notification->actor,
                'task'    => $this->notification->task,
                'project' => $this->notification->project,
                'link'    => $deepLink,
            ],
        );
    }
}

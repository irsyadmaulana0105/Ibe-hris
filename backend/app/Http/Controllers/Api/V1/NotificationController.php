<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NotificationTemplate;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'notification.manage', 'Notification access required.');
        return $this->success(NotificationTemplate::query()->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'notification.manage', 'Notification access required.');
        $data = $request->validate([
            'event_key'        => ['required', 'string', 'max:50'],
            'event_label'      => ['required', 'string', 'max:150'],
            'email_enabled'    => ['nullable', 'boolean'],
            'whatsapp_enabled' => ['nullable', 'boolean'],
            'slack_enabled'    => ['nullable', 'boolean'],
            'body'             => ['required', 'string'],
        ]);
        return $this->created(NotificationTemplate::create($data), 'Template dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'notification.manage', 'Notification access required.');
        $template = NotificationTemplate::findOrFail($id);
        $data = $request->validate([
            'event_label'      => ['sometimes', 'string', 'max:150'],
            'email_enabled'    => ['sometimes', 'boolean'],
            'whatsapp_enabled' => ['sometimes', 'boolean'],
            'slack_enabled'    => ['sometimes', 'boolean'],
            'body'             => ['sometimes', 'string'],
        ]);
        $template->update($data);
        return $this->success($template->fresh(), 'Template disimpan.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'notification.manage', 'Notification access required.');
        NotificationTemplate::findOrFail($id)->delete();
        return $this->success(message: 'Template dihapus.');
    }

    /**
     * Send a sample notification to the currently logged-in admin so they can
     * preview how the template will render (subject, body, variables).
     * Uses synchronous send (not queue) so the admin sees errors immediately.
     */
    public function testSend(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'notification.manage', 'Notification access required.');

        $template = NotificationTemplate::findOrFail($id);
        $ok = $this->dispatcher->testSend($template, $request->user(), [
            'user_name'        => $request->user()->name,
            'start_date'       => now()->format('d M Y'),
            'end_date'         => now()->addDays(2)->format('d M Y'),
            'approver_name'    => 'Demo Approver',
            'rejection_reason' => '(contoh alasan penolakan)',
            'date'             => now()->format('d M Y'),
            'hours'            => '4',
            'amount'           => '500.000',
            'period'           => now()->format('Y-m'),
            'net_pay'          => '8.500.000',
            'days_left'        => '15',
        ]);

        return $ok
            ? $this->success(['email' => $request->user()->email], 'Test email berhasil dikirim. Cek inbox dalam 1-2 menit.')
            : $this->success(['email' => $request->user()->email], 'Email queue gagal - cek log Laravel. Pastikan SMTP config benar.', 200);
    }
}

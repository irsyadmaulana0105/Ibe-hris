<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Test kirim email untuk verify SMTP/Stalwart konfigurasi.
 * Diakses via Settings → Integrasi (superadmin only).
 */
class MailTestController extends ApiController
{
    /** POST /admin/mail-test - kirim email test ke recipient */
    public function send(Request $request): JsonResponse
    {
        $admin = $request->user();
        if ($admin->group !== 'superadmin') {
            abort(403, 'Hanya Superadmin yang dapat test email.');
        }

        $data = $request->validate([
            'to'      => ['required', 'email', 'max:150'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body'    => ['nullable', 'string', 'max:2000'],
        ]);

        $subject = $data['subject'] ?? 'Test Email dari IBE Reality System';
        $body = $data['body'] ?? "Halo,\n\nIni adalah email test dari sistem IBE Reality yang dikirim oleh {$admin->name} pada " . now()->format('d M Y H:i:s') . " WIB.\n\nKalau Anda menerima email ini, konfigurasi SMTP berjalan normal.\n\nSalam,\nIBE Reality System";

        try {
            Mail::raw($body, function ($msg) use ($data, $subject) {
                $msg->to($data['to'])
                    ->subject($subject);
            });

            return $this->success([
                'to'         => $data['to'],
                'subject'    => $subject,
                'sent_at'    => now()->toIso8601String(),
                'sender'     => config('mail.from.address'),
                'mailer'     => config('mail.default'),
            ], "Email test berhasil dikirim ke {$data['to']}");
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal kirim email: ' . $e->getMessage(),
                'errors'  => [
                    'mailer'    => config('mail.default'),
                    'from'      => config('mail.from.address'),
                    'host'      => config('mail.mailers.smtp.host'),
                    'port'      => config('mail.mailers.smtp.port'),
                    'exception' => class_basename($e),
                ],
            ], 500);
        }
    }
}

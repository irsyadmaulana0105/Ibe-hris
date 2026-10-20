<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ScheduledEmail;
use App\Models\SharedMailbox;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Penjadwalan email dari webmail.
 *
 * Dibuat server-side karena Stalwart 1.0.6 belum mendukung JMAP `sendAt`.
 * Baris pending di-flush oleh artisan command tiap menit.
 *
 * Dipisah dari AuthController (yang tadinya 1.205 baris) tanpa mengubah
 * perilaku: ketiga endpoint dan helper autentikasinya dipindah apa adanya.
 * Helper `authenticateMailboxUser` ikut pindah karena hanya ketiga endpoint
 * inilah pemakainya.
 */
class ScheduledEmailController extends ApiController
{
    /**
     * POST /api/v1/auth/schedule-email - enqueue email untuk dikirim nanti.
     * Auth: Sanctum bearer ATAU mailbox_password (sama pattern dengan
     * webmail-directory).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'email'],
            'password' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
            'payload.subject' => ['required', 'string'],
            'payload.to' => ['required', 'array', 'min:1'],
            // Safety: body wajib minimal 5 karakter setelah trim - cegah
            // kirim email kosong tanpa sengaja ke customer.
            'payload.bodyText' => ['required', 'string', 'min:5'],
        ]);

        $authed = $this->authenticateMailboxUser($data['username'], $data['password'] ?? null, $request);
        if (! $authed) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $scheduledAt = Carbon::parse($data['scheduled_at']);
        if ($scheduledAt->isPast()) {
            return response()->json(['message' => 'scheduled_at harus di masa depan'], 422);
        }

        $row = new ScheduledEmail;
        // user_id NULL kalau dari shared mailbox (gak ada user record).
        // mailbox_username adalah routing key untuk flush command.
        $row->user_id = $authed instanceof User ? $authed->id : null;
        $row->mailbox_username = $authed->email;
        $row->scheduled_at = $scheduledAt;
        $row->status = 'pending';
        $row->payload = $data['payload']; // mutator encrypts
        $row->save();

        return $this->success([
            'id' => $row->id,
            'scheduled_at' => $row->scheduled_at->toIso8601String(),
            'status' => $row->status,
        ]);
    }

    /** GET /api/v1/auth/scheduled-emails - list jadwal yang belum dikirim. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'email'],
            'password' => ['nullable', 'string'],
        ]);
        $authed = $this->authenticateMailboxUser($data['username'], $data['password'] ?? null, $request);
        if (! $authed) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Tampilkan SEMUA status untuk transparency. Pending/processing di
        // atas (active), sisanya (sent/failed/canceled) sebagai history.
        // Cap 50 terbaru untuk perf.
        $rows = ScheduledEmail::where('mailbox_username', $authed->email)
            ->orderByRaw("CASE WHEN status IN ('pending','processing') THEN 0 ELSE 1 END")
            ->orderBy('scheduled_at', 'desc')
            ->limit(50)
            ->get(['id', 'scheduled_at', 'status', 'attempts', 'last_error', 'processed_at']);

        // Bonus: decrypt subject untuk display tanpa expose body
        $list = $rows->map(function ($r) {
            $payload = $r->payload;

            return [
                'id' => $r->id,
                'scheduled_at' => $r->scheduled_at->toIso8601String(),
                'status' => $r->status,
                'attempts' => $r->attempts,
                'last_error' => $r->last_error,
                'processed_at' => $r->processed_at?->toIso8601String(),
                'subject' => $payload['subject'] ?? '',
                'to' => array_map(fn ($a) => $a['email'] ?? '', $payload['to'] ?? []),
            ];
        });

        return $this->success($list);
    }

    /**
     * DELETE /api/v1/auth/scheduled-emails/{id} - handle berdasarkan status:
     *   - pending:  cancel (mark canceled, row stays jadi user lihat history-nya)
     *   - lainnya:  hard delete (cleanup riwayat)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'email'],
            'password' => ['nullable', 'string'],
        ]);
        $authed = $this->authenticateMailboxUser($data['username'], $data['password'] ?? null, $request);
        if (! $authed) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $row = ScheduledEmail::where('id', $id)
            ->where('mailbox_username', $authed->email)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($row->status === 'pending') {
            $row->update(['status' => 'canceled']);

            return $this->success(['canceled' => true, 'action' => 'canceled']);
        }
        if ($row->status === 'processing') {
            return response()->json(['message' => 'Sedang dalam proses pengiriman - tunggu sebentar lalu coba lagi'], 422);
        }
        // sent / failed / canceled → hard delete from history
        $row->delete();

        return $this->success(['canceled' => true, 'action' => 'deleted']);
    }

    /**
     * Authenticate either via Sanctum bearer (kalau ada dan match email)
     * atau via mailbox_password check (fallback untuk webmail langsung).
     *
     * Coverage:
     *   - User personal mailbox (users.mailbox_password_encrypted)
     *   - Shared mailbox (shared_mailboxes.password_encrypted)
     *
     * Return: User|SharedMailbox|null.
     */
    private function authenticateMailboxUser(string $email, ?string $password, Request $request)
    {
        // Coba bearer dulu (user personal)
        if ($request->bearerToken()) {
            $bearerUser = auth('sanctum')->user();
            if ($bearerUser && strcasecmp($bearerUser->email, $email) === 0) {
                return $bearerUser;
            }
        }
        if (! $password) {
            return null;
        }

        // Cek users.mailbox_password_encrypted
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($user && $user->mailbox_password_encrypted) {
            try {
                $expected = Crypt::decryptString($user->mailbox_password_encrypted);
                if (hash_equals($expected, $password)) {
                    return $user;
                }
            } catch (\Throwable $e) {
            }
        }

        // Fallback: cek shared_mailboxes
        $shared = SharedMailbox::where('email', $email)->where('is_active', true)->first();
        if ($shared) {
            try {
                $expected = $shared->decryptPassword();
                if (hash_equals($expected, $password)) {
                    return $shared;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}

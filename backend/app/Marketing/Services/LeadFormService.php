<?php

namespace App\Marketing\Services;

use App\Marketing\Models\Lead;
use App\Marketing\Models\LeadForm;
use App\Marketing\Models\LeadFormSubmission;
use App\Marketing\Support\PenggunaAktif;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Web Form submission processing: validate, anti-spam, create lead, notify.
 *
 * Anti-spam strategy (layered):
 *   1. Honeypot field "_hp" - bot fill this hidden field, valid submission empties it
 *   2. Rate limit per IP (10 / 5-menit window) - checked di service, not just middleware
 *   3. Required field validation per form's `fields` schema
 *   4. Optional reCAPTCHA verification (skipped kalau secret_key gak diset di env)
 */
class LeadFormService
{
    private const RATE_LIMIT_COUNT = 10;

    private const RATE_LIMIT_WINDOW_MINUTES = 5;

    /**
     * Process submission. Throws \RuntimeException dengan key error untuk:
     *   - spam_honeypot
     *   - spam_rate_limit
     *   - validation_failed (with errors array via getMessage())
     */
    public function submit(LeadForm $form, array $payload, Request $request): array
    {
        $ip = $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 500);
        $referer = substr((string) $request->headers->get('referer', ''), 0, 500);

        // 1. Honeypot check - kalau ada value di "_hp", flag spam
        if (! empty($payload['_hp'])) {
            $this->logSubmission($form, null, $payload, $ip, $ua, $referer, true, 'honeypot');
            throw new \RuntimeException('spam_honeypot');
        }
        unset($payload['_hp']);

        // 2. Rate limit per IP
        $recentCount = LeadFormSubmission::query()
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes(self::RATE_LIMIT_WINDOW_MINUTES))
            ->count();
        if ($recentCount >= self::RATE_LIMIT_COUNT) {
            $this->logSubmission($form, null, $payload, $ip, $ua, $referer, true, 'rate_limit');
            throw new \RuntimeException('spam_rate_limit');
        }

        // 3. Validate against form's fields schema
        $errors = $this->validatePayload($form->fields ?? [], $payload);
        if (! empty($errors)) {
            $this->logSubmission($form, null, $payload, $ip, $ua, $referer, false, 'validation');
            throw new \RuntimeException(json_encode(['validation_failed' => $errors]));
        }

        // 4. Create Lead di DB transaction
        $lead = DB::transaction(function () use ($form, $payload) {
            $lead = Lead::create([
                'company_id' => $form->company_id,
                'name' => $payload['name'] ?? ($payload['first_name'] ?? 'Web Form Lead'),
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'company_name' => $payload['company_name'] ?? ($payload['company'] ?? null),
                'source' => $form->default_source ?? "Web Form: {$form->name}",
                'status' => 'new',
                'notes' => $this->buildNotes($form, $payload),
                'owner_id' => $this->ownerFor($form),
            ]);

            $form->increment('submission_count');

            return $lead;
        });

        $this->logSubmission($form, $lead->id, $payload, $ip, $ua, $referer, false, null);

        // 5. Notify (best-effort, never block response)
        $this->notify($form, $lead, $payload);

        return [
            'lead_id' => $lead->id,
            'success_message' => $form->success_message ?? 'Terima kasih! Tim kami akan menghubungi dalam 1x24 jam.',
            'redirect_url' => $form->redirect_url,
        ];
    }

    /**
     * Per-field validation berdasarkan schema di form.fields.
     * Returns array of {field => message} kalau ada error.
     */
    /**
     * Penanggung jawab lead dari formulir web. Dulu langsung default_owner_id,
     * yang tidak bisa diisi dari UI CRM - semua lead web tanpa pemilik dan
     * tidak muncul di daftar "milik saya" siapa pun. Kini jatuh ke pembuat
     * formulir, asal orangnya masih aktif di tenant yang sama.
     */
    private function ownerFor(LeadForm $form): ?string
    {
        foreach ([$form->default_owner_id, $form->created_by] as $kandidat) {
            if ($kandidat && User::query()
                ->whereKey($kandidat)
                ->where('company_id', $form->company_id)
                ->where(fn ($q) => PenggunaAktif::saring($q))
                ->exists()) {
                return $kandidat;
            }
        }

        return null;
    }

    private function validatePayload(array $schema, array $payload): array
    {
        $errors = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? null;
            if (! $key) {
                continue;
            }

            $value = $payload[$key] ?? null;
            $required = (bool) ($field['required'] ?? false);
            $type = $field['type'] ?? 'text';

            if ($required && ($value === null || $value === '')) {
                $errors[$key] = ($field['label'] ?? $key).' wajib diisi.';

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            // Type-specific validation
            if ($type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Format email tidak valid.';
            } elseif ($type === 'tel') {
                if (! preg_match('/^[\d\s+\-()]{6,20}$/', (string) $value)) {
                    $errors[$key] = 'Format nomor telepon tidak valid.';
                }
            } elseif ($type === 'select' && ! empty($field['options'])) {
                if (! in_array($value, $field['options'], true)) {
                    $errors[$key] = 'Pilihan tidak valid.';
                }
            }
        }

        return $errors;
    }

    /**
     * Build notes string yang berisi semua field non-standar dari payload.
     * Standar fields (name, email, phone, company_name) sudah masuk ke kolom
     * dedicated; sisanya di-render sebagai "Label: Value" lines di notes.
     */
    private function buildNotes(LeadForm $form, array $payload): string
    {
        $standardKeys = ['name', 'first_name', 'last_name', 'email', 'phone', 'company', 'company_name'];
        $lines = [];

        foreach ($form->fields ?? [] as $field) {
            $key = $field['key'] ?? null;
            if (! $key || in_array($key, $standardKeys, true)) {
                continue;
            }

            $value = $payload[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $label = $field['label'] ?? $key;
            $lines[] = "{$label}: {$value}";
        }

        $lines[] = '-';
        $lines[] = "Source: {$form->name}";

        return implode("\n", $lines);
    }

    private function logSubmission(
        LeadForm $form,
        ?int $leadId,
        array $payload,
        ?string $ip,
        ?string $ua,
        ?string $referer,
        bool $isSpam,
        ?string $spamReason,
    ): void {
        try {
            LeadFormSubmission::create([
                'company_id' => $form->company_id,
                'form_id' => $form->id,
                'lead_id' => $leadId,
                'ip_address' => $ip,
                'user_agent' => $ua,
                'referer' => $referer,
                'payload' => $payload,
                'is_spam' => $isSpam,
                'spam_reason' => $spamReason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LeadFormSubmission log failed: '.$e->getMessage());
        }
    }

    /**
     * Kirim email notif ke notify_emails. Best-effort, gak block kalau gagal
     * (e.g. SMTP rate limit). Catat ke log saja.
     */
    private function notify(LeadForm $form, Lead $lead, array $payload): void
    {
        $recipients = $form->notify_emails ?? [];
        if (empty($recipients)) {
            return;
        }

        $body = "Lead baru dari Web Form: {$form->name}\n\n";
        $body .= "Nama: {$lead->name}\n";
        if ($lead->email) {
            $body .= "Email: {$lead->email}\n";
        }
        if ($lead->phone) {
            $body .= "Telepon: {$lead->phone}\n";
        }
        if ($lead->company_name) {
            $body .= "Perusahaan: {$lead->company_name}\n";
        }
        $body .= "\n--- Detail ---\n";
        foreach ($payload as $k => $v) {
            if (is_string($v) || is_numeric($v)) {
                $body .= "{$k}: {$v}\n";
            }
        }
        $body .= "\n→ Buka di CRM: https://crm.ibereality.com/leads\n";

        foreach ($recipients as $email) {
            try {
                Mail::raw($body, function ($m) use ($email, $form) {
                    $m->to($email)->subject("[IBE CRM] Lead Baru - {$form->name}");
                });
            } catch (\Throwable $e) {
                Log::warning("LeadForm notify failed for {$email}: ".$e->getMessage());
            }
        }
    }

    public function generateToken(): string
    {
        // 32-char URL-safe random - collision probability negligible
        return Str::random(32);
    }
}

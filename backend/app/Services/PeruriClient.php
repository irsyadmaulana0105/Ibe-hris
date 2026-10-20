<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Wraps the Peruri e-Meterai API (Indonesian government digital stamp).
 *
 * Documents requiring legal evidence (offer letters, contracts) get a
 * Rp 10.000 digital stamp embedded as a coordinate-anchored barcode +
 * signed hash.
 *
 * The actual Peruri endpoint is a per-merchant production URL; we read
 * merchant key from `integrations.peruri` (encrypted). When credentials are
 * absent, returns a "not_configured" stub so business flow degrades
 * gracefully (offer letter is still issued, just without stamp).
 */
class PeruriClient
{
    private const BASE_URL = 'https://e-meterai.peruri.co.id/v1';

    /**
     * Apply an e-meterai stamp to an existing PDF stored on disk.
     *
     * @param int $companyId
     * @param string $pdfPath path under storage/app/public
     * @param array $context ['document_type' => 'offer_letter', 'recipient_name' => '...']
     * @return array{ok:bool, stamped_path?:string, stamp_id?:string, reason?:string}
     */
    public function stamp(int $companyId, string $pdfPath, array $context = []): array
    {
        $integration = Integration::query()
            ->where('company_id', $companyId)
            ->where('key', 'peruri')
            ->where('status', 'connected')
            ->first();

        if (!$integration) {
            return ['ok' => false, 'reason' => 'Peruri integration not configured. Add merchant key in Settings → Integrasi.'];
        }

        $merchantKey = $integration->credential;
        if (!$merchantKey) {
            return ['ok' => false, 'reason' => 'Credential decrypt failed'];
        }

        if (!Storage::disk('public')->exists($pdfPath)) {
            return ['ok' => false, 'reason' => "PDF not found: {$pdfPath}"];
        }

        try {
            $response = Http::timeout(45)
                ->withToken($merchantKey)
                ->attach(
                    'document',
                    Storage::disk('public')->get($pdfPath),
                    basename($pdfPath),
                )
                ->post(self::BASE_URL . '/stamp', [
                    'document_type'  => $context['document_type'] ?? 'general',
                    'recipient_name' => $context['recipient_name'] ?? '',
                    'reference_id'   => $context['reference_id'] ?? uniqid('IBE-'),
                ]);

            if (!$response->ok()) {
                Log::warning('Peruri stamp failed: ' . $response->body());
                return ['ok' => false, 'reason' => 'Peruri API error: ' . $response->status()];
            }

            $stampedBlob = $response->body();
            $stampedPath = preg_replace('/\.pdf$/i', '-stamped.pdf', $pdfPath);
            Storage::disk('public')->put($stampedPath, $stampedBlob);

            return [
                'ok'           => true,
                'stamped_path' => $stampedPath,
                'stamp_id'     => $response->header('X-Stamp-ID') ?? '',
            ];
        } catch (\Throwable $e) {
            Log::warning('Peruri exception: ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Verify a previously-stamped document against Peruri registry.
     */
    public function verify(int $companyId, string $stampId): array
    {
        $integration = Integration::query()
            ->where('company_id', $companyId)
            ->where('key', 'peruri')
            ->where('status', 'connected')
            ->first();

        if (!$integration) {
            return ['ok' => false, 'reason' => 'Peruri integration not configured'];
        }

        try {
            $response = Http::timeout(15)
                ->withToken($integration->credential)
                ->get(self::BASE_URL . "/verify/{$stampId}");

            return $response->ok()
                ? ['ok' => true, 'data' => $response->json()]
                : ['ok' => false, 'reason' => 'Verify failed: ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }
}

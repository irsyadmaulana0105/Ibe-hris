<?php

namespace App\Services\Face;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Self-hosted Human/face-api engine.  Stateless: the biometric template lives in
 * our own database and never leaves this server.  This is the production driver.
 */
class HumanFaceDriver implements FaceDriverContract
{
    public function extractDescriptor(string $imageBase64): string
    {
        return $this->extract($imageBase64)['descriptor'];
    }

    /**
     * Descriptor + skor kualitas foto pendaftaran (wajah hidup, antispoof, ukuran).
     *
     * @return array{descriptor: string, face_count: int, quality: array<string, mixed>}
     */
    public function extract(string $imageBase64): array
    {
        $payload = $this->request('/extract', [
            'imageBase64' => $imageBase64,
        ]);

        if (!isset($payload['descriptor']) || !is_array($payload['descriptor']) || count($payload['descriptor']) === 0) {
            throw new ServiceUnavailableHttpException(null, 'Engine descriptor wajah mengembalikan respons yang tidak valid.');
        }

        return [
            'descriptor' => json_encode(array_map(static fn ($value) => (float) $value, $payload['descriptor']), JSON_THROW_ON_ERROR),
            'face_count' => (int) ($payload['faceCount'] ?? 1),
            'quality' => is_array($payload['quality'] ?? null) ? $payload['quality'] : [],
        ];
    }

    /**
     * @return array{matched: bool, similarity: float, threshold: float, faceCount: int, quality: array<string, mixed>}
     */
    public function compareDescriptorWithImage(string $imageBase64, string $referenceDescriptor): array
    {
        $payload = $this->request('/compare', [
            'imageBase64' => $imageBase64,
            'referenceDescriptor' => $this->decodeDescriptor($referenceDescriptor),
            // Ambang kini ditentukan engine (env FACE_MATCH_SIMILARITY_THRESHOLD) dan
            // nilai dari permintaan diabaikan; tetap dikirim untuk engine versi lama.
            'threshold' => (float) config('face.match_similarity_threshold', 0.60),
        ]);

        return [
            'matched' => (bool) ($payload['matched'] ?? false),
            'similarity' => (float) ($payload['similarity'] ?? 0.0),
            'threshold' => (float) ($payload['threshold'] ?? config('face.match_similarity_threshold', 0.60)),
            'faceCount' => (int) ($payload['faceCount'] ?? 0),
            'quality' => is_array($payload['quality'] ?? null) ? $payload['quality'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('face.engine_url'), '/');
        $timeout = (float) config('face.engine_timeout', 20);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(array_filter(['x-face-engine-token' => (string) config('face.engine_token')]))
                ->timeout($timeout)
                ->post($baseUrl . $path, $payload);
        } catch (ConnectionException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                'Layanan verifikasi wajah sedang tidak tersedia. Jalankan engine Human terlebih dahulu.',
                $exception
            );
        }

        if ($response->status() === 422) {
            throw new UnprocessableEntityHttpException(
                $response->json('message') ?: 'Verifikasi wajah gagal.'
            );
        }

        if (!$response->successful()) {
            throw new ServiceUnavailableHttpException(
                null,
                'Layanan verifikasi wajah tidak merespons dengan benar.'
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<int, float>
     */
    private function decodeDescriptor(string $descriptorJson): array
    {
        $decoded = json_decode($descriptorJson, true);

        if (!is_array($decoded) || count($decoded) === 0) {
            throw new UnprocessableEntityHttpException(
                'Data wajah terdaftar tidak valid. Silakan daftar ulang wajah Anda.'
            );
        }

        foreach ($decoded as $value) {
            if (!is_numeric($value)) {
                throw new UnprocessableEntityHttpException(
                    'Data wajah terdaftar tidak valid. Silakan daftar ulang wajah Anda.'
                );
            }
        }

        return array_map(static fn ($value) => (float) $value, $decoded);
    }
}

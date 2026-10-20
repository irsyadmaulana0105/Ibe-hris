<?php

namespace App\Services\Face;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Biznet Face API (fr.neoapi.id) — DEMO ONLY.
 *
 * Unlike the Human driver this service is enrollment-based: the face itself is
 * stored in a remote FaceGallery and addressed later by user_id.  We therefore
 * keep an opaque JSON marker in users.face_descriptor instead of a numeric
 * template, and every recognition is a round trip to Biznet.
 *
 * Do not select this driver for production: enrolment happens at registration
 * time, which is before the admin approval gate in FaceApprovalService, and it
 * ships employee biometrics to a third party.
 */
class BiznetFaceDriver implements FaceDriverContract
{
    private const MARKER = 'biznet';

    public function extractDescriptor(string $imageBase64): string
    {
        $gallery = $this->gallery();
        $userId = 'demo_' . Str::lower(Str::ulid()->toBase32());

        $this->request('/facegallery/enroll-face', [
            'user_id' => $userId,
            'user_name' => $userId,
            'facegallery_id' => $gallery,
            'image' => $this->normaliseImage($imageBase64),
        ]);

        return json_encode([
            'driver' => self::MARKER,
            'facegallery_id' => $gallery,
            'user_id' => $userId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{matched: bool, similarity: float, faceCount: int}
     */
    public function compareDescriptorWithImage(string $imageBase64, string $referenceDescriptor): array
    {
        $reference = $this->decodeMarker($referenceDescriptor);

        $payload = $this->request('/facegallery/verify-face', [
            'facegallery_id' => $reference['facegallery_id'],
            'user_id' => $reference['user_id'],
            'image' => $this->normaliseImage($imageBase64),
        ], allowUnverified: true);

        $status = (int) ($payload['status'] ?? 200);
        $similarity = (float) ($payload['similarity'] ?? 0.0);

        // 411 is Biznet's "face not verified or unregistered" — a legitimate
        // negative answer, not a transport failure, so it must not throw.
        $matched = $status === 200
            && $similarity >= (float) config('face.match_similarity_threshold', 0.60);

        return [
            'matched' => $matched,
            'similarity' => $similarity,
            'faceCount' => $matched ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload, bool $allowUnverified = false): array
    {
        $baseUrl = rtrim((string) config('face.biznet.base_url'), '/');
        $token = (string) config('face.biznet.token');

        if ($token === '') {
            throw new ServiceUnavailableHttpException(null, 'Token Biznet Face belum dikonfigurasi.');
        }

        // Every Biznet endpoint rejects a request without trx_id, including the
        // ones the published documentation omits it from.
        $payload['trx_id'] ??= (string) Str::ulid();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['Accesstoken' => $token])
                ->timeout((float) config('face.biznet.timeout', 20))
                ->post($baseUrl . $path, $payload);
        } catch (ConnectionException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                'Layanan verifikasi wajah Biznet sedang tidak dapat dihubungi.',
                $exception
            );
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // Responses are wrapped in a "risetai" envelope and the outcome code is
        // a *string* inside it, not an integer at the root.  Some failures also
        // arrive as HTTP 200 with the real code only in the body, so the body
        // wins and the HTTP status is only a fallback.
        $body = is_array($json['risetai'] ?? null) ? $json['risetai'] : $json;
        $status = (int) ($body['status'] ?? $response->status());

        if ($status === 200) {
            return $body;
        }

        // 411 not verified, 416 user absent from the gallery, 418 gallery empty:
        // all three are legitimate "no match" answers during verification, not
        // transport failures, so they must return rather than throw.
        if ($allowUnverified && in_array($status, [411, 416, 418], true)) {
            return $body + ['status' => $status];
        }

        throw match ($status) {
            411 => new UnprocessableEntityHttpException('Wajah tidak terverifikasi atau belum terdaftar.'),
            412 => new UnprocessableEntityHttpException('Wajah tidak terdeteksi pada foto. Pastikan pencahayaan cukup lalu ambil ulang selfie.'),
            413 => new UnprocessableEntityHttpException('Wajah terlalu kecil pada foto. Dekatkan kamera lalu coba lagi.'),
            416 => new UnprocessableEntityHttpException('Wajah Anda belum terdaftar pada layanan wajah. Silakan daftar ulang wajah Anda.'),
            418 => new UnprocessableEntityHttpException('Galeri wajah masih kosong. Daftarkan wajah terlebih dahulu.'),
            490, 491, 492, 493, 494, 495 => new UnprocessableEntityHttpException('Format gambar tidak dapat diproses. Ambil ulang selfie.'),
            default => new ServiceUnavailableHttpException(null, 'Layanan verifikasi wajah Biznet tidak merespons dengan benar.'),
        };
    }

    /**
     * Biznet expects raw base64 without the data URI prefix the browser adds.
     */
    private function normaliseImage(string $imageBase64): string
    {
        if (str_contains($imageBase64, ',') && str_starts_with($imageBase64, 'data:')) {
            return substr($imageBase64, strpos($imageBase64, ',') + 1);
        }

        return $imageBase64;
    }

    private function gallery(): string
    {
        $gallery = (string) config('face.biznet.facegallery_id');

        if ($gallery === '') {
            throw new ServiceUnavailableHttpException(null, 'FaceGallery Biznet belum dikonfigurasi.');
        }

        return $gallery;
    }

    /**
     * @return array{facegallery_id: string, user_id: string}
     */
    private function decodeMarker(string $referenceDescriptor): array
    {
        $decoded = json_decode($referenceDescriptor, true);

        if (
            !is_array($decoded)
            || ($decoded['driver'] ?? null) !== self::MARKER
            || empty($decoded['facegallery_id'])
            || empty($decoded['user_id'])
        ) {
            // Most likely a Human-driver numeric template left over from before
            // the driver switch; it can never be verified against Biznet.
            throw new UnprocessableEntityHttpException(
                'Data wajah terdaftar tidak cocok dengan layanan wajah aktif. Silakan daftar ulang wajah Anda.'
            );
        }

        return [
            'facegallery_id' => (string) $decoded['facegallery_id'],
            'user_id' => (string) $decoded['user_id'],
        ];
    }
}

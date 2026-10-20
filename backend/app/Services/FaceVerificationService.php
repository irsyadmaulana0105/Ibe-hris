<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class FaceVerificationService
{
    public function __construct(
        private readonly FaceEngineClient $faceEngine,
    ) {}

    /**
     * Verifikasi wajah saat absen. Menolak bila wajah tidak cocok; bila cocok,
     * mengembalikan catatan pemeriksaan untuk disimpan di absen (face_check_in/out).
     *
     * Pemeriksaan wajah hidup (anti foto cetak / layar HP) mengikuti
     * config('face.liveness_mode'):
     *  - shadow  : skor dicatat & absen ditandai (flags), TIDAK ditolak.
     *  - enforce : skor di bawah ambang ditolak.
     *
     * @return array<string, mixed>
     */
    public function assertAttendanceFaceVerified(User $user, ?string $capturedSelfieBase64): array
    {
        if (empty($user->face_descriptor)) {
            if (!empty($user->face_descriptor_pending)) {
                throw new UnprocessableEntityHttpException(
                    'Pendaftaran wajah Anda masih menunggu persetujuan admin. Anda belum bisa absen.'
                );
            }

            throw new UnprocessableEntityHttpException(
                'Wajah Anda belum terdaftar. Silakan daftar wajah terlebih dahulu sebelum absen.'
            );
        }

        if (empty($capturedSelfieBase64)) {
            throw new UnprocessableEntityHttpException(
                'Selfie absensi wajib diambil sebelum mengirim absensi.'
            );
        }

        $verification = $this->faceEngine->compareDescriptorWithImage(
            $capturedSelfieBase64,
            $user->face_descriptor
        );

        $check = self::buildCheck($verification);

        if (!$verification['matched']) {
            Log::info('[face] absen ditolak: wajah tidak cocok', ['user_id' => $user->id] + self::forLog($check));
            throw new UnprocessableEntityHttpException(
                'Wajah tidak cocok dengan data terdaftar. Absen ditolak.'
            );
        }

        if ($check['mode'] === 'enforce' && array_intersect($check['flags'], ['liveness_low', 'antispoof_low'])) {
            Log::info('[face] absen ditolak: bukan wajah langsung', ['user_id' => $user->id] + self::forLog($check));
            throw new UnprocessableEntityHttpException(
                'Foto tidak terdeteksi sebagai wajah langsung (mungkin foto atau layar). Ambil selfie langsung dengan pencahayaan cukup.'
            );
        }

        if ($check['flags'] !== []) {
            Log::info('[face] absen ditandai', ['user_id' => $user->id] + self::forLog($check));
        }

        return $check;
    }

    /**
     * Ringkas hasil engine menjadi catatan yang disimpan & tanda untuk HR.
     * Tidak memuat descriptor/biometrik - hanya skor.
     *
     * @param  array<string, mixed>  $verification
     * @return array<string, mixed>
     */
    public static function buildCheck(array $verification): array
    {
        $q = is_array($verification['quality'] ?? null) ? $verification['quality'] : [];
        $similarity = round((float) ($verification['similarity'] ?? 0), 4);
        $threshold = (float) ($verification['threshold'] ?? config('face.match_similarity_threshold', 0.60));
        $liveness = isset($q['liveness']) && is_numeric($q['liveness']) ? (float) $q['liveness'] : null;
        $antispoof = isset($q['antispoof']) && is_numeric($q['antispoof']) && ($q['antispoofAvailable'] ?? true)
            ? (float) $q['antispoof'] : null;
        $faceCount = (int) ($verification['faceCount'] ?? 1);
        $faceSize = isset($q['faceSize']) && is_numeric($q['faceSize']) ? (int) $q['faceSize'] : null;

        $flags = [];
        if ($liveness !== null && $liveness < (float) config('face.liveness_min', 0.5)) {
            $flags[] = 'liveness_low';
        }
        if ($antispoof !== null && $antispoof < (float) config('face.antispoof_min', 0.5)) {
            $flags[] = 'antispoof_low';
        }
        if ($faceCount > 1) {
            $flags[] = 'multi_face';
        }
        if (($verification['matched'] ?? false) && $similarity < $threshold + (float) config('face.borderline_margin', 0.05)) {
            $flags[] = 'borderline';
        }
        if ($faceSize !== null && $faceSize < (int) config('face.small_face_px', 80)) {
            $flags[] = 'small_face';
        }

        return [
            'similarity' => $similarity,
            'threshold' => $threshold,
            'liveness' => $liveness,
            'antispoof' => $antispoof,
            'face_count' => $faceCount,
            'face_size' => $faceSize,
            'face_score' => isset($q['faceScore']) && is_numeric($q['faceScore']) ? (float) $q['faceScore'] : null,
            'flags' => $flags,
            'mode' => config('face.liveness_mode', 'shadow') === 'enforce' ? 'enforce' : 'shadow',
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function forLog(array $check): array
    {
        return array_intersect_key($check, array_flip(['similarity', 'liveness', 'antispoof', 'face_count', 'face_size', 'flags', 'mode']));
    }
}

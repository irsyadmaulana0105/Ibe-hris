<?php

namespace App\Support;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Models\User;
use App\Services\PrivateMediaStorage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Foto pendaftaran wajah karyawan (biometrik), 19 Okt 2026.
 *
 * Disimpan privat per tenant (disk `local`, tidak pernah lewat /storage) dan
 * hanya bisa dibaca lewat URL bertanda tangan berumur pendek, dipakai halaman
 * Persetujuan Wajah HR supaya HR menilai wajah yang SEBENARNYA didaftarkan.
 *
 * Catatan: image produksi memakai disk `public` + MediaTerlindungi; sinkronkan
 * bila media privat repo dirilis.
 */
final class FotoWajah
{
    public const DISK = 'local';

    public const CATEGORY = 'face-enrollments';

    private const FIELD = 'enrollment';

    private const MIMES = ['image/jpeg', 'image/png'];

    public static function simpan(User $user, string $dataUrl): string
    {
        $dataUrl = (string) preg_replace('#^data:image/jpg;#', 'data:image/jpeg;', trim($dataUrl));

        try {
            return self::storage()->storeImageDataUri(
                self::tenant($user), self::CATEGORY, $user->id, self::FIELD, $dataUrl,
            )['path'];
        } catch (PrivateAssetIntegrityException) {
            throw new UnprocessableEntityHttpException('Foto wajah tidak valid. Gunakan JPEG atau PNG.');
        }
    }

    public static function hapus(User $user, ?string $path): void
    {
        if (! self::milik($user, $path)) {
            return;
        }

        try {
            self::storage()->deletePrivate(self::tenant($user), self::CATEGORY, $user->id, self::FIELD, $path);
        } catch (PrivateAssetIntegrityException) {
            // Foto yatim tidak boleh menggagalkan persetujuan/penolakan.
        }
    }

    /** @param 'pending'|'active' $jenis */
    public static function url(User $user, string $jenis): ?string
    {
        $path = $jenis === 'active' ? $user->face_photo : $user->face_photo_pending;
        if (! self::milik($user, $path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return self::storage()->temporarySignedRoute(
            'api.v1.private-media.face', ['user' => $user->id, 'field' => $jenis], 300,
        );
    }

    /** @param 'pending'|'active' $jenis */
    public static function response(User $user, string $jenis): Response
    {
        $path = $jenis === 'active' ? $user->face_photo : $user->face_photo_pending;
        if (! self::milik($user, $path) || ! Storage::disk(self::DISK)->exists($path)) {
            throw new NotFoundHttpException('Foto wajah tidak tersedia.');
        }

        $bytes = Storage::disk(self::DISK)->get($path);
        $meta = self::storage()->inspectBytes($bytes, self::MIMES);

        return response($bytes, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'inline; filename="wajah.'.($meta['mime'] === 'image/png' ? 'png' : 'jpg').'"',
            'Content-Type' => $meta['mime'],
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function milik(User $user, ?string $path): bool
    {
        return $user->company_id !== null
            && self::storage()->isPrivatePathFor(self::tenant($user), self::CATEGORY, $user->id, self::FIELD, $path);
    }

    private static function tenant(User $user): int
    {
        return (int) $user->company_id;
    }

    private static function storage(): PrivateMediaStorage
    {
        return app(PrivateMediaStorage::class);
    }
}

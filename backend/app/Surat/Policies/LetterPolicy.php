<?php

namespace App\Surat\Policies;

use App\Models\User;
use App\Surat\Models\Letter;

/**
 * Authorization untuk lifecycle Letter.
 *
 * Roles:
 *   - user (karyawan biasa) - create draft + submit own; lihat surat own only.
 *   - admin / superadmin / finance - semua action kecuali sign (kecuali user
 *     terdaftar di signer).
 *   - signer - sign hanya kalau LetterSignature.user_id == actor.id (atau
 *     superadmin override).
 *
 * Four-eyes principle: actor untuk approve & review TIDAK boleh sama dengan
 * author. Mencegah self-approval surat sendiri.
 */
class LetterPolicy
{
    /** Any authenticated user dalam company yang sama bisa list. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Lihat 1 surat: admin atau author atau signer terdaftar. */
    public function view(User $user, Letter $letter): bool
    {
        if ($user->hasCapability('documents.letter.view')) {
            return true;
        }
        if ($user->isAdmin) {
            return true;
        }
        if ($letter->author_id === $user->id) {
            return true;
        }
        if ($letter->signer && $letter->signer->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /** Create draft - semua authenticated. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Edit body / metadata: author selama belum locked, atau admin. */
    public function update(User $user, Letter $letter): bool
    {
        if ($letter->isLocked()) {
            return false;
        }
        if ($user->hasCapability('documents.letter.manage')) {
            return true;
        }

        return $user->isAdmin || $letter->author_id === $user->id;
    }

    public function delete(User $user, Letter $letter): bool
    {
        if ($letter->isLocked()) {
            return false;
        }
        if ($user->hasCapability('documents.letter.manage')) {
            return true;
        }

        return $user->isAdmin || $letter->author_id === $user->id;
    }

    /** Submit ke review: author atau admin. */
    public function submit(User $user, Letter $letter): bool
    {
        if ($user->hasCapability('documents.letter.manage')) {
            return true;
        }

        return $user->isAdmin || $letter->author_id === $user->id;
    }

    /**
     * Request revision (review → draft): hanya reviewer/admin.
     * Tidak boleh author sendiri (mencegah loop self-review).
     */
    public function requestRevision(User $user, Letter $letter): bool
    {
        if (! $user->isAdmin && ! $user->hasCapability('documents.letter.approve')) {
            return false;
        }

        // Four-eyes: reviewer tidak boleh sama dengan author
        return $letter->author_id !== $user->id;
    }

    /**
     * Approve (auto-generate nomor): admin/superadmin/finance, NOT author.
     * Mencegah self-approval.
     */
    public function approve(User $user, Letter $letter): bool
    {
        if (! $user->isAdmin && ! $user->hasCapability('documents.letter.approve')) {
            return false;
        }
        if ($letter->author_id === $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Sign: signer terdaftar harus actor.id == LetterSignature.user_id,
     * OR superadmin sebagai override.
     */
    public function sign(User $user, Letter $letter): bool
    {
        if ($user->isSuperadmin) {
            return true;
        }
        if (! $user->hasCapability('documents.letter.sign') && ! $user->isAdmin) {
            return false;
        }
        $signer = $letter->signer;
        if (! $signer) {
            return false;
        }

        return $signer->user_id === $user->id;
    }

    /** Mark sent: admin atau author. */
    public function send(User $user, Letter $letter): bool
    {
        if ($user->hasCapability('documents.letter.send')) {
            return true;
        }

        return $user->isAdmin || $letter->author_id === $user->id;
    }

    public function archive(User $user, Letter $letter): bool
    {
        if ($user->hasCapability('documents.letter.manage')) {
            return true;
        }

        return $user->isAdmin || $letter->author_id === $user->id;
    }

    /** Cancel: admin (anytime), atau author selama belum SIGNED. */
    public function cancel(User $user, Letter $letter): bool
    {
        if ($user->hasCapability('documents.letter.manage')) {
            return true;
        }
        if ($user->isAdmin) {
            return true;
        }

        return $letter->author_id === $user->id
            && ! in_array($letter->status, [Letter::STATUS_SIGNED, Letter::STATUS_SENT, Letter::STATUS_ARCHIVED], true);
    }
}

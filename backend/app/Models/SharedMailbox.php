<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Shared service mailbox (mis. admin@ibereality.com).
 *
 * Password disimpan encrypted di password_encrypted. JANGAN expose
 * decrypted password via toArray() / JsonResource - hanya di-decrypt
 * di SharedMailboxService saat redeem ticket.
 */
class SharedMailbox extends Model
{
    protected $fillable = [
        'email', 'display_name', 'description',
        'password_encrypted', 'allowed_groups', 'allowed_user_emails', 'is_active',
        'last_switched_at', 'last_switched_by',
    ];

    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return [
            'allowed_groups'      => 'array',
            'allowed_user_emails' => 'array',
            'is_active'           => 'boolean',
            'last_switched_at'    => 'datetime',
        ];
    }

    /** Decrypt password - caller-side responsibility untuk handle exception. */
    public function decryptPassword(): string
    {
        return Crypt::decryptString($this->password_encrypted);
    }

    /**
     * Access check - user di-allow kalau:
     *   - group-nya masuk allowed_groups, OR
     *   - email canonical/legacy-nya masuk allowed_user_emails
     */
    public function isAccessibleBy(?User $user): bool
    {
        if (!$user || !$this->is_active) return false;

        $groups = $this->allowed_groups ?? [];
        if (in_array($user->group, $groups, true)) return true;

        $emails = $this->allowed_user_emails ?? [];
        if (empty($emails)) return false;

        $userEmails = array_filter([
            strtolower((string) $user->email),
            strtolower((string) $user->legacy_email),
        ]);
        $allowedLower = array_map('strtolower', $emails);
        return (bool) array_intersect($userEmails, $allowedLower);
    }
}

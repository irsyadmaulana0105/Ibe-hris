<?php

namespace App\Surat\Http\Controllers;

use App\Surat\Models\Letter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public surat verification endpoint - di-akses via QR code di footer PDF.
 * NO AUTH required (publik). Tampilkan halaman simple yang konfirmasi:
 *   - Nomor surat tersebut valid
 *   - Detail singkat (kategori, tanggal, status SIGNED/SENT)
 *   - Issued by company
 */
class LetterVerifyController
{
    public function verify(Request $request, string $token): Response
    {
        $letter = Letter::where('qr_token', $token)->first();
        $found = $letter && in_array($letter->status, ['signed', 'sent', 'archived'], true);

        $html = view('surat.verify', [
            'found'    => $found,
            'letter'   => $letter,
        ])->render();
        return response($html, $found ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

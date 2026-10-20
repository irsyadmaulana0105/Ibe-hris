<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Marketing\Models\CampaignRecipient;
use App\Marketing\Services\KampanyeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman berhenti berlangganan kampanye email (publik, tanpa login).
 *
 * GET hanya menampilkan tombol konfirmasi - pemindai tautan di server email
 * penerima sering membuka semua tautan, jadi GET tidak boleh langsung
 * mengubah apa pun. POST (dari tombol itu, atau tombol "unsubscribe" bawaan
 * Gmail/Outlook lewat header List-Unsubscribe-Post) yang benar-benar mencatat.
 */
class PublicUnsubscribeController extends Controller
{
    public function __construct(private readonly KampanyeService $kampanye) {}

    public function show(string $token): Response
    {
        if ($token === KampanyeService::TOKEN_CONTOH) {
            return $this->halaman('Tautan contoh',
                '<p>Ini tautan contoh dari email uji atau pratinjau kampanye. Di email yang diterima pelanggan, '
                .'tautan ini mengarah ke halaman konfirmasi berhenti berlangganan untuk alamat mereka.</p>');
        }

        $r = $this->cari($token);
        if (! $r) {
            return $this->halaman('Tautan tidak dikenal', '<p>Tautan ini tidak valid atau sudah kedaluwarsa.</p>', 404);
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return $this->halaman('Berhenti berlangganan',
            '<p>Berhenti menerima email penawaran ke <strong>'.$e($r->email).'</strong>?</p>'
            .'<form method="post"><button type="submit">Ya, berhenti berlangganan</button></form>');
    }

    public function store(string $token): Response
    {
        $r = $this->cari($token);
        if (! $r) {
            return $this->halaman('Tautan tidak dikenal', '<p>Tautan ini tidak valid atau sudah kedaluwarsa.</p>', 404);
        }
        $this->kampanye->berhentiBerlangganan($r);

        return $this->halaman('Berhasil', '<p>Alamat Anda sudah dihapus dari daftar kiriman kami. Mohon maaf atas ketidaknyamanannya.</p>');
    }

    private function cari(string $token): ?CampaignRecipient
    {
        return strlen($token) >= 20 ? CampaignRecipient::where('token', $token)->first() : null;
    }

    private function halaman(string $judul, string $isi, int $status = 200): Response
    {
        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.$judul.'</title><style>body{font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:64px auto;padding:0 16px;color:#222;line-height:1.5}'
            .'button{background:#1f2937;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-size:15px;cursor:pointer}</style></head>'
            .'<body><h1 style="font-size:20px">'.$judul.'</h1>'.$isi.'</body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}

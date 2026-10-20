<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('session:prune')->daily();

// Training check: tiap pagi 06:00 WIB, flip karyawan training yang batas
// hari-nya sudah lewat ke pending_review (block akses sampai HR action).
Schedule::command('training:check-expiry')
    ->dailyAt('06:00')
    ->timezone('Asia/Jakarta');

// Web Push: cek inbox tiap user yang punya push subscription, kirim notif
// kalau ada email baru. Interval 1 menit - keseimbangan antara responsiveness
// dan beban Stalwart. Per-user query ringan (Email/query limit=5).
Schedule::command('webmail:check-new-mail')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Auto-prune Sanctum tokens yang sudah expire (default 8 jam dari created_at
// per config/sanctum.php). Tanpa ini tabel personal_access_tokens terus
// membengkak. Run per jam - token expired tetap reject-able via Sanctum
// middleware sebelum pruned, delay 1 jam aman untuk DB hygiene.
Schedule::command('sanctum:prune-expired --hours=24')->hourly();

// Broadcast pengingat libur/cuti bersama setiap hari pukul 16:00 (kirim untuk besok)
Schedule::command('holidays:broadcast-h1')->dailyAt('16:00')->timezone('Asia/Jakarta');

// Reminder kontrak yang akan berakhir (30/14/7 hari) - kirim pagi jam 09:00
Schedule::command('contracts:remind-expiring')->dailyAt('09:00')->timezone('Asia/Jakarta');

// Sync IBE directory ke address book Stalwart setiap user - sumber autocomplete
// recipient di webmail compose drawer. Idempotent diff-based; sekali run ~2 detik
// untuk 6 user.
Schedule::command('mail:sync-directory')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Flush scheduled emails - workaround karena Stalwart 1.0.6 belum support
// JMAP sendAt. Idempotent dengan `processing` lock, aman kalau dipanggil
// bareng. Tiap menit untuk minimal latency.
Schedule::command('mail:flush-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Kampanye email CRM - sedikit demi sedikit tiap 10 menit, batas harian &
// jam kirim di config/crm.php (perintah sendiri yang menolak di luar jendela).
Schedule::command('crm:kampanye-kirim')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();

Artisan::command('session:prune', function () {
    $minutes = (int) config('session.lifetime', 120);
    $cutoff = now()->subMinutes($minutes)->timestamp;
    $deleted = DB::table('sessions')->where('last_activity', '<', $cutoff)->delete();
    $this->info("Pruned sessions: {$deleted}");
})->purpose('Prune expired database sessions');
Schedule::call(fn () => DB::table('api_idempotency_keys')->where('expires_at', '<', now())->delete())
    ->name('prune-api-idempotency-keys')
    ->hourly()
    ->withoutOverlapping();

// Tabel operasional (sso_tokens, refresh_tokens, rbac_access_decisions) tumbuh
// tanpa batas — per 2026-09-14 sso_tokens sudah 7.4rb baris yang semuanya sudah
// kedaluwarsa. Jalan dini hari supaya delete berbatch tidak bentrok jam sibuk.
// audit_logs SENGAJA tidak ikut: itu bukti compliance, lihat command-nya.
Schedule::command('db:prune-operational')
    ->dailyAt('03:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Bersihkan sisa berkas sementara DomPDF/Imagick (config dompdf.temp_dir).
// Proses PDF yang terhenti di tengah meninggalkan berkas magick-* ratusan MB;
// dulu di /tmp tmpfs 128 MB itu mematikan semua unggahan ERP (1 Okt 2026).
Artisan::command('pdf:prune-temp {--minutes=60}', function () {
    $dir = config('dompdf.options.temp_dir');
    if (! is_string($dir) || ! is_dir($dir)) {
        return;
    }
    $batas = time() - ((int) $this->option('minutes')) * 60;
    $n = 0;
    foreach (new \FilesystemIterator($dir) as $f) {
        if ($f->isFile() && $f->getMTime() < $batas && @unlink($f->getPathname())) {
            $n++;
        }
    }
    $this->info("pdf:prune-temp: {$n} berkas dihapus");
})->purpose('Hapus berkas sementara DomPDF yang sudah basi');
Schedule::command('pdf:prune-temp')->hourly();

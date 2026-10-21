# Setup lingkungan pengembangan HRIS / Absensi IBE di laptop (Windows).
#
# Jalankan dari folder ini:  powershell -ExecutionPolicy Bypass -File .\setup-lokal.ps1
# Aman dijalankan ulang: .env yang sudah ada dan data yang sudah di-seed tidak ditimpa.
#
# Semua data LOKAL (SQLite di backend\database\database.sqlite). Skrip ini tidak
# pernah menyentuh server/produksi IBE.

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$backend = Join-Path $root 'backend'
$attendance = Join-Path $root 'apps\attendance'

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Need($cmd, $hint) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        throw "Perintah '$cmd' tidak ditemukan. $hint"
    }
}
function RandomSecret([int]$len = 24) {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = New-Object byte[] $len
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}
# UTF-8 TANPA BOM: PowerShell 5 menambah BOM pada -Encoding utf8, dan BOM membuat
# kunci pertama .env terbaca salah.
function Write-Utf8([string]$file, [string[]]$lines) {
    [System.IO.File]::WriteAllLines($file, $lines, (New-Object System.Text.UTF8Encoding($false)))
}
function Set-EnvValue([string]$file, [string]$key, [string]$value) {
    # Baca eksplisit sebagai UTF-8: Get-Content di PowerShell 5 membaca berkas tanpa
    # BOM sebagai ANSI, sehingga komentar non-ASCII rusak berlipat tiap penulisan.
    $lines = [System.Collections.Generic.List[string]]([System.IO.File]::ReadAllLines($file, (New-Object System.Text.UTF8Encoding($false))))
    $idx = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*#?\s*$([regex]::Escape($key))=") { $idx = $i; break }
    }
    if ($idx -ge 0) { $lines[$idx] = "$key=$value" } else { $lines.Add("$key=$value") }
    Write-Utf8 $file $lines.ToArray()
}

Step 'Memeriksa PHP, Composer, Node'
Need php 'Pasang PHP 8.4 (mis. Laravel Herd, atau: winget install PHP.PHP.8.4).'
Need node 'Pasang Node.js 22 LTS (https://nodejs.org).'
$composer = $env:COMPOSER_BIN
if (-not $composer) { Need composer 'Pasang Composer (https://getcomposer.org/download/).'; $composer = 'composer' }
$phpVersion = (php -r 'echo PHP_VERSION;')
if ([version]($phpVersion -replace '[^0-9.].*$','') -lt [version]'8.3') { throw "PHP $phpVersion terlalu lama; butuh 8.3+ (disarankan 8.4)." }
$modules = @(php -m | ForEach-Object { $_.Trim() })
foreach ($e in 'pdo_sqlite','mbstring','intl','gd','zip','fileinfo','bcmath','curl','openssl') {
    if ($modules -notcontains $e) { throw "Ekstensi PHP '$e' belum aktif. Aktifkan di php.ini (extension=$e)." }
}
Write-Host "PHP $phpVersion, Node $(node -v)"

Step 'Backend: composer install'
Push-Location $backend
try {
    if ($composer -like '*.phar') { php $composer install --no-interaction --prefer-dist } else { & $composer install --no-interaction --prefer-dist }
    if ($LASTEXITCODE -ne 0) { throw 'composer install gagal.' }

    $envFile = Join-Path $backend '.env'
    $credFile = Join-Path $root 'KREDENSIAL-LOKAL.txt'
    if (-not (Test-Path $envFile)) {
        Step 'Backend: membuat .env lokal (SQLite, tanpa koneksi ke server)'
        Copy-Item (Join-Path $backend '.env.example') $envFile
        $sa = RandomSecret; $ad = RandomSecret; $em = RandomSecret
        $settings = [ordered]@{
            'APP_ENV' = 'local'; 'APP_DEBUG' = 'true'; 'APP_URL' = 'http://127.0.0.1:8000'
            'FRONTEND_URL' = 'http://localhost:5173'
            'APP_LOCALE' = 'id'
            'DB_CONNECTION' = 'sqlite'
            'QUEUE_CONNECTION' = 'sync'; 'MAIL_MAILER' = 'log'; 'CACHE_STORE' = 'database'
            'FACE_ENGINE_DRIVER' = 'human'; 'FACE_ENGINE_URL' = 'http://127.0.0.1:3899'; 'FACE_ENGINE_TOKEN' = ''
            'FACE_LIVENESS_MODE' = 'shadow'
            'HOLIDAY_API_ENABLED' = 'false'
            'IBE_ALLOW_DEMO_ADMIN_SEEDING' = 'YES-I-UNDERSTAND'
            'IBE_DEMO_SUPERADMIN_PASSWORD' = $sa; 'IBE_DEMO_ADMIN_PASSWORD' = $ad; 'IBE_DEMO_EMPLOYEE_PASSWORD' = $em
        }
        foreach ($k in $settings.Keys) { Set-EnvValue $envFile $k $settings[$k] }
        php artisan key:generate --force
        Write-Utf8 $credFile @(
            'Akun LOKAL (hanya untuk laptop ini, bukan akun server IBE)'
            '------------------------------------------------------------'
            "Superadmin : superadmin@example.com / $sa"
            "Admin HR   : admin@example.com / $ad"
            "Karyawan   : karyawan@example.com / $em"
        )
    } else {
        Write-Host '.env sudah ada - dibiarkan.'
    }

    $db = Join-Path $backend 'database\database.sqlite'
    if (-not (Test-Path $db)) { New-Item -ItemType File $db | Out-Null }

    Step 'Backend: migrasi + data contoh'
    php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'migrate gagal.' }
    php artisan db:seed --class=LocalHrisDevSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'seeding gagal.' }
    php artisan storage:link 2>$null | Out-Null

    Step 'Face-engine: npm install (model pengenal wajah, sekali saja)'
    npm install --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm install backend gagal.' }
} finally { Pop-Location }

Step 'Aplikasi absensi: npm install + .env.local'
Push-Location $attendance
try {
    npm install --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm install attendance gagal.' }
    Write-Utf8 (Join-Path $attendance '.env.local') @(
        '# Lokal: arahkan ke backend di laptop ini, JANGAN ke app-erp.ibereality.com.'
        'VITE_API_BASE_URL=http://127.0.0.1:8000'
        'VITE_REQUIRE_SSO=false'
    )
} finally { Pop-Location }

Step 'Selesai'
Write-Host @"
Jalankan di 3 terminal terpisah:
  1) cd backend;           php artisan serve --host=127.0.0.1 --port=8000
  2) cd backend;           node ml-face/server.cjs
  3) cd apps\attendance;   npm run dev

Buka http://localhost:5173 . Akun lokal ada di KREDENSIAL-LOKAL.txt
Uji otomatis backend:  cd backend; php -d memory_limit=1G vendor/bin/pest --group=api
"@ -ForegroundColor Green

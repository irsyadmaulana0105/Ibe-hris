<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Surat - {{ $letter->letter_number ?? 'Tidak Ditemukan' }}</title>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 30px; color: #1e293b; }
        .card { background: #fff; max-width: 480px; width: 100%; padding: 40px; border-radius: 14px; box-shadow: 0 4px 16px rgba(15,23,42,0.08); text-align: center; }
        .check { width: 64px; height: 64px; border-radius: 50%; display: inline-grid; place-items: center; font-size: 32px; font-weight: 900; margin-bottom: 16px; color: white; }
        .check.ok { background: #0d9488; }
        .check.no { background: #dc2626; }
        h1 { margin: 0 0 6px; font-size: 22px; }
        .sub { color: #64748b; font-size: 14px; margin: 0 0 24px; }
        table { width: 100%; margin: 20px 0; font-size: 13px; text-align: left; }
        table td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table td:first-child { color: #64748b; width: 40%; }
        .footer-note { font-size: 11px; color: #94a3b8; margin-top: 20px; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        @if($found)
            <div class="check ok">✓</div>
            <h1>Surat Terverifikasi</h1>
            <p class="sub">Dokumen ini RESMI diterbitkan oleh<br><strong>PT Integrasi Bisnis Eksekutif (IBE Reality)</strong></p>
            <table>
                <tr><td>Nomor</td><td><code>{{ $letter->letter_number }}</code></td></tr>
                <tr><td>Tanggal</td><td>{{ $letter->letter_date?->isoFormat('D MMMM YYYY') }}</td></tr>
                <tr><td>Kategori</td><td>{{ $letter->category?->name ?? '-' }}</td></tr>
                <tr><td>Perihal</td><td>{{ $letter->subject }}</td></tr>
                <tr><td>Status</td><td><span style="background:#0d9488;color:white;padding:2px 8px;border-radius:4px;font-size:11px;text-transform:uppercase;">{{ $letter->status }}</span></td></tr>
                @if($letter->signed_at)
                    <tr><td>Ditandatangani</td><td>{{ $letter->signed_at?->isoFormat('D MMMM YYYY HH:mm') }} WIB</td></tr>
                @endif
            </table>
            <p class="footer-note">Halaman ini publik. Kalau ada keraguan, hubungi <strong>admin@ibereality.com</strong> dengan menyebutkan nomor surat di atas.</p>
        @else
            <div class="check no">✗</div>
            <h1>Surat Tidak Terverifikasi</h1>
            <p class="sub">QR code atau token tidak cocok dengan surat resmi IBE Reality.</p>
            <p style="font-size:13px;color:#475569;">Kemungkinan:</p>
            <ul style="text-align:left;font-size:13px;color:#475569;line-height:1.7;">
                <li>QR code di-foto/screenshot dari surat palsu</li>
                <li>Surat sudah dibatalkan</li>
                <li>Token salah atau URL di-modify</li>
            </ul>
            <p class="footer-note">Mohon konfirmasi ke <strong>admin@ibereality.com</strong> kalau Anda menerima dokumen yang mengatasnamakan IBE Reality tapi tidak terverifikasi.</p>
        @endif
    </div>
</body>
</html>

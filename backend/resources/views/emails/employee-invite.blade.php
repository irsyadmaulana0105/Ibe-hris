<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivasi akun IBE Reality</title>
</head>
<body style="margin:0;background:#f3f7f7;color:#102a2e;font-family:Arial,sans-serif">
<div style="max-width:620px;margin:0 auto;padding:32px 16px">
    <div style="background:#ffffff;border:1px solid #d8e4e5;border-radius:16px;overflow:hidden">
        <div style="background:#0b6668;color:#ffffff;padding:24px 28px">
            <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase">PT Integrasi Bisnis Eksekutif</div>
            <h1 style="margin:8px 0 0;font-size:24px">Selamat bergabung di IBE Reality</h1>
        </div>
        <div style="padding:28px">
            <p>Halo {{ $employeeName }},</p>
            <p>Akun Anda sebagai tenaga magang telah dibuat dengan ID karyawan <strong>{{ $employeeNumber }}</strong>.</p>
            <p>Silakan klik tombol berikut untuk membuat password dan mengaktifkan akun:</p>
            <p style="margin:24px 0">
                <a href="{{ $inviteUrl }}" style="display:inline-block;background:#0f8f8b;color:#ffffff;text-decoration:none;padding:13px 20px;border-radius:9px;font-weight:bold">Aktifkan akun</a>
            </p>
            <p style="font-size:13px;color:#536b70">Link bersifat pribadi, hanya dapat dipakai sekali, dan berlaku {{ $ttlHours }} jam. Jangan meneruskan email ini kepada orang lain.</p>
            <hr style="border:0;border-top:1px solid #e1e9ea;margin:24px 0">
            <strong>Langkah pertama</strong>
            <ol style="line-height:1.7;padding-left:20px">
                <li>Buka link aktivasi dan buat password yang kuat.</li>
                <li>Masuk ke Attendance menggunakan email ini.</li>
                <li>Lengkapi pendaftaran wajah jika diminta, lalu tunggu persetujuan HR.</li>
                <li>Gunakan menu Absen untuk clock-in dan clock-out sesuai lokasi kerja.</li>
            </ol>
            <p>Jika link kedaluwarsa atau Anda tidak merasa didaftarkan, hubungi HRD IBE Reality.</p>
        </div>
    </div>
    <p style="text-align:center;color:#71868a;font-size:12px">Email otomatis dari noreply@ibereality.com. Mohon tidak membalas email ini.</p>
</div>
</body>
</html>

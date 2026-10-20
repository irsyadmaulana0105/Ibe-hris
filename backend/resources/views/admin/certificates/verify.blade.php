<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Surat Keterangan Kerja - IBE Reality</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
               background: #f8fafc; color: #1e293b; margin: 0; padding: 24px;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { max-width: 540px; width: 100%; background: white; border-radius: 16px;
                box-shadow: 0 10px 30px rgba(15,23,42,0.1); overflow: hidden; }
        .header { padding: 28px 32px; background: linear-gradient(135deg, #0f766e, #14b8a6); color: white; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .header p { margin: 6px 0 0; opacity: 0.9; font-size: 13px; }
        .body { padding: 24px 32px; }
        .status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px;
                  border-radius: 999px; font-size: 12px; font-weight: 600; margin-bottom: 16px; }
        .status.valid { background: #dcfce7; color: #166534; }
        .status.invalid { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 14px; }
        td.label { color: #64748b; width: 40%; font-weight: 500; }
        td.value { color: #0f172a; font-weight: 600; }
        .footer { padding: 16px 32px; background: #f1f5f9; font-size: 12px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>Verifikasi Surat Keterangan Kerja</h1>
            <p>PT IBE Reality</p>
        </div>
        <div class="body">
            @if($found)
                <div class="status valid">✓ SURAT VALID</div>
                <table>
                    <tr><td class="label">Nomor Surat</td><td class="value">{{ $cert->cert_number }}</td></tr>
                    <tr><td class="label">Nama Karyawan</td><td class="value">{{ $cert->user->name }}</td></tr>
                    <tr><td class="label">Tanggal Terbit</td><td class="value">{{ $cert->issued_date->format('d M Y') }}</td></tr>
                    <tr><td class="label">Ditandatangani</td><td class="value">{{ $cert->signer_name }} - {{ $cert->signer_title }}</td></tr>
                    <tr><td class="label">Perusahaan</td><td class="value">{{ $cert->company?->name ?? 'PT IBE Reality' }}</td></tr>
                    @if($cert->purpose)
                    <tr><td class="label">Keperluan</td><td class="value">{{ $cert->purpose }}</td></tr>
                    @endif
                </table>
            @else
                <div class="status invalid">✗ SURAT TIDAK DITEMUKAN</div>
                <p style="color: #64748b; font-size: 14px;">
                    Kode verifikasi tidak valid. Kemungkinan surat sudah dibatalkan atau
                    URL yang diberikan salah.
                </p>
            @endif
        </div>
        <div class="footer">
            Verifikasi dilakukan pada {{ now()->format('d M Y H:i') }} WIB
        </div>
    </div>
</body>
</html>

{{-- Kwitansi reimburse - A5 portrait, auto-generated saat Finance settle. --}}
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kwitansi {{ $r->receipt_number }}</title>
<style>
  @page { margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #0f172a; margin: 0; }
  .border-card { border: 2px solid #0a363d; border-radius: 4px; padding: 10mm; }
  .header { display: table; width: 100%; margin-bottom: 6mm; }
  .header .col-l { display: table-cell; vertical-align: top; width: 65%; }
  .header .col-r { display: table-cell; vertical-align: top; text-align: right; }
  .company-name { font-size: 14pt; font-weight: 800; color: #0a363d; letter-spacing: .03em; }
  .company-sub { font-size: 8pt; color: #475569; margin-top: 1mm; }
  .receipt-no { font-family: monospace; font-size: 9pt; color: #0a363d; font-weight: 700; }
  .receipt-date { font-size: 8.5pt; color: #475569; margin-top: 1mm; }
  h1 { text-align: center; font-size: 20pt; letter-spacing: .1em; color: #0a363d; margin: 4mm 0 6mm; border-bottom: 2px solid #0a363d; padding-bottom: 2mm; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 1.5mm 2mm; vertical-align: top; }
  td.lbl { width: 38%; color: #475569; font-weight: 600; }
  td.val { font-weight: 500; }
  .amount-box { background: #fefce8; border: 2px dashed #ca8a04; padding: 4mm; text-align: center; margin: 5mm 0; }
  .amount-rp { font-size: 18pt; font-weight: 800; color: #0a363d; }
  .amount-words { font-size: 9pt; color: #475569; font-style: italic; margin-top: 1.5mm; }
  .sig-grid { display: table; width: 100%; margin-top: 8mm; }
  .sig-grid .sig-col { display: table-cell; width: 50%; text-align: center; vertical-align: top; padding: 0 4mm; }
  .sig-line { border-top: 1px solid #0a363d; margin-top: 18mm; padding-top: 1mm; font-size: 8.5pt; }
  .sig-name { font-weight: 700; }
  .sig-role { color: #64748b; font-size: 8pt; }
  .footer { margin-top: 6mm; padding-top: 3mm; border-top: 1px dotted #cbd5e1; font-size: 7.5pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<div class="border-card">
  @php
    // Embed logo as base64 data URI - DomPDF reliable di lokal disk, kurang
    // reliable kalau load dari URL public. Skip kalau logo tidak ada.
    $logoData = null;
    if ($company && $company->logo) {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if ($disk->exists($company->logo)) {
            $ext = strtolower(pathinfo($company->logo, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png');
            // Diperkecil dulu lewat GD (hasilnya di-cache di storage/app/image-cache).
            // Logo asli 4096x3282 membuat Imagick di dalam DomPDF memenuhi /tmp
            // (tmpfs 128 MB) dan sejak itu SEMUA unggahan berkas di ERP gagal
            // - kejadian 28 Sep 2026. 600 px cukup tajam untuk cetak 50 mm.
            // SVG tidak bisa diproses GD, jadi hanya SVG yang ditanam apa adanya.
            $logoData = \App\Helpers::cachedResizedImageDataUri($company->logo, 600, 600)
                ?? ($ext === 'svg' ? 'data:' . $mime . ';base64,' . base64_encode($disk->get($company->logo)) : null);
        }
    }
  @endphp
  <div class="header">
    <div class="col-l">
      @if($logoData)
        <img src="{{ $logoData }}" alt="logo" style="max-height: 16mm; max-width: 50mm; margin-bottom: 2mm;">
      @endif
      <div class="company-name">{{ $company->name ?? 'IBE Reality' }}</div>
      <div class="company-sub">
        {{ $company->address ?? 'PT Integrasi Bisnis Eksekutif' }}<br>
        @if($company && $company->phone){{ $company->phone }} · @endif{{ $company->email ?? 'info@ibereality.com' }}
      </div>
    </div>
    <div class="col-r">
      <div class="receipt-no">{{ $r->receipt_number }}</div>
      <div class="receipt-date">{{ \Carbon\Carbon::parse($r->settled_at)->isoFormat('DD MMMM YYYY') }}</div>
    </div>
  </div>

  <h1>KWITANSI REIMBURSEMENT</h1>

  <table>
    <tr><td class="lbl">Diterima Oleh</td><td class="val"><strong>{{ $user->name }}</strong> ({{ $user->nip ?? '-' }})</td></tr>
    <tr><td class="lbl">Divisi / Jabatan</td><td class="val">{{ $user->division->name ?? '-' }}@if($user->jobTitle) · {{ $user->jobTitle->name }}@endif</td></tr>
    <tr><td class="lbl">Kategori</td><td class="val">{{ $category->name ?? '-' }}</td></tr>
    <tr><td class="lbl">Keperluan / Event</td><td class="val">{{ $r->event }}</td></tr>
    <tr><td class="lbl">Tanggal Pengeluaran</td><td class="val">{{ \Carbon\Carbon::parse($r->date)->isoFormat('DD MMMM YYYY') }}</td></tr>
    <tr><td class="lbl">Rincian</td><td class="val">Rp {{ number_format($r->unit_price, 0, ',', '.') }} × {{ $r->quantity }} = Rp {{ number_format($r->total_amount, 0, ',', '.') }}</td></tr>
  </table>

  <div class="amount-box">
    <div style="font-size: 9pt; color:#92400e; font-weight:700; letter-spacing:.05em;">JUMLAH DICAIRKAN</div>
    <div class="amount-rp">Rp {{ number_format($r->approved_amount ?? $r->total_amount, 0, ',', '.') }}</div>
    <div class="amount-words">
      @php
        $amt = (int) ($r->approved_amount ?? $r->total_amount);
        $words = function ($n) use (&$words) {
            $units = ['','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan','sepuluh','sebelas'];
            if ($n < 12) return $units[$n];
            if ($n < 20) return $words($n - 10) . ' belas';
            if ($n < 100) return $words((int)($n / 10)) . ' puluh' . ($n % 10 ? ' ' . $words($n % 10) : '');
            if ($n < 200) return 'seratus' . ($n - 100 ? ' ' . $words($n - 100) : '');
            if ($n < 1000) return $words((int)($n / 100)) . ' ratus' . ($n % 100 ? ' ' . $words($n % 100) : '');
            if ($n < 2000) return 'seribu' . ($n - 1000 ? ' ' . $words($n - 1000) : '');
            if ($n < 1000000) return $words((int)($n / 1000)) . ' ribu' . ($n % 1000 ? ' ' . $words($n % 1000) : '');
            if ($n < 1000000000) return $words((int)($n / 1000000)) . ' juta' . ($n % 1000000 ? ' ' . $words($n % 1000000) : '');
            return $words((int)($n / 1000000000)) . ' miliar' . ($n % 1000000000 ? ' ' . $words($n % 1000000000) : '');
        };
      @endphp
      « {{ ucfirst(trim($words($amt))) }} rupiah »
    </div>
  </div>

  <table>
    <tr><td class="lbl">Bank</td><td class="val">{{ $r->bank_name ?? '-' }}</td></tr>
    <tr><td class="lbl">No. Rekening</td><td class="val">{{ $r->bank_account_number ?? '-' }}</td></tr>
    <tr><td class="lbl">Atas Nama</td><td class="val">{{ $r->bank_account_holder ?? $user->name }}</td></tr>
    @if($r->payment_reference)
    <tr><td class="lbl">Referensi Transfer</td><td class="val">{{ $r->payment_reference }}</td></tr>
    @endif
  </table>

  <div class="sig-grid">
    <div class="sig-col">
      <div style="font-size:8pt;color:#64748b;">Disetujui HR/Manager</div>
      <div class="sig-line">
        <div class="sig-name">{{ $hrApprover->name ?? '-' }}</div>
        <div class="sig-role">{{ \Carbon\Carbon::parse($r->hr_approved_at)->isoFormat('DD MMM YYYY') }}</div>
      </div>
    </div>
    <div class="sig-col">
      <div style="font-size:8pt;color:#64748b;">Dicairkan oleh Finance</div>
      <div class="sig-line">
        <div class="sig-name">{{ $finance->name ?? '-' }}</div>
        <div class="sig-role">{{ \Carbon\Carbon::parse($r->settled_at)->isoFormat('DD MMM YYYY') }}</div>
      </div>
    </div>
  </div>

  <div class="footer">
    Kwitansi ini di-generate otomatis oleh sistem IBE Reality HRIS. Verifikasi tiket bisa di-scan dengan menghubungi HR.<br>
    Dokumen sah tanpa cap basah karena terbit dari sistem terpercaya.
  </div>
</div>
</body>
</html>

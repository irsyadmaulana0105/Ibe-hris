{{-- Faktur Penjualan / Invoice PDF - A4 portrait. Format kompatibel UU PPN.
     Kalau company belum PKP (npwp kosong), tetap valid sebagai commercial invoice. --}}
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
  @page { margin: 14mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #0f172a; margin: 0; }
  .border-card { padding: 0; }
  .header { display: table; width: 100%; margin-bottom: 6mm; border-bottom: 2px solid #0a363d; padding-bottom: 4mm; }
  .header .col-l { display: table-cell; vertical-align: top; width: 60%; }
  .header .col-r { display: table-cell; vertical-align: top; text-align: right; }
  .company-name { font-size: 14pt; font-weight: 800; color: #0a363d; letter-spacing: .03em; }
  .company-sub { font-size: 8.5pt; color: #475569; margin-top: 1mm; }
  .doc-title { font-size: 16pt; font-weight: 800; color: #0a363d; letter-spacing: .08em; text-align: right; }
  .doc-number { font-family: monospace; font-size: 11pt; color: #1a7a8c; font-weight: 700; margin-top: 1mm; }
  .doc-date { font-size: 9pt; color: #475569; margin-top: 1mm; }
  .info-grid { display: table; width: 100%; margin: 4mm 0; }
  .info-cell { display: table-cell; vertical-align: top; width: 50%; padding: 3mm; background: #f8fafc; border-radius: 4px; }
  .info-cell + .info-cell { padding-left: 4mm; background: transparent; }
  .info-lbl { font-size: 8pt; color: #64748b; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 1mm; }
  .info-val { font-size: 10pt; font-weight: 600; }
  .info-sub { font-size: 9pt; color: #475569; margin-top: 1mm; line-height: 1.4; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 4mm; }
  table.items th { background: #0a363d; color: #fff; padding: 2mm 3mm; font-size: 8.5pt; text-align: left; font-weight: 700; letter-spacing: .03em; }
  table.items th.r { text-align: right; }
  table.items td { padding: 2mm 3mm; border-bottom: 1px solid #e2e8f0; font-size: 9.5pt; vertical-align: top; }
  table.items td.r { text-align: right; font-variant-numeric: tabular-nums; }
  table.items td.c { text-align: center; }
  .totals { width: 70mm; float: right; margin-top: 4mm; font-size: 10pt; }
  .totals .row { display: table; width: 100%; padding: 1mm 0; }
  .totals .row > div { display: table-cell; }
  .totals .row .lbl { color: #64748b; }
  .totals .row .val { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
  .totals .grand { background: #0a363d; color: #fff; padding: 3mm 4mm; border-radius: 4px; margin-top: 2mm; font-weight: 800; font-size: 12pt; }
  .totals .grand .val { color: #fff; }
  .sig-grid { display: table; width: 100%; margin-top: 28mm; clear: both; }
  .sig-grid .sig-col { display: table-cell; width: 50%; vertical-align: top; padding: 0 4mm; }
  .sig-grid .sig-col.r { text-align: right; }
  .sig-line { border-top: 1px solid #0a363d; margin-top: 22mm; padding-top: 1mm; font-size: 9pt; }
  .sig-name { font-weight: 700; }
  .footer { margin-top: 6mm; padding-top: 3mm; border-top: 1px dotted #cbd5e1; font-size: 7.5pt; color: #94a3b8; text-align: center; clear: both; }
  .pkp-notice { background: #fef3c7; border-left: 3px solid #ca8a04; padding: 2mm 3mm; font-size: 8pt; color: #854d0e; margin-top: 3mm; }
</style>
</head>
<body>
  @php
    // Embed company logo sebagai base64 (DomPDF reliable dengan data URI)
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
    // Spell out total in Indonesian words
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

    $taxBase = (int) $invoice->subtotal - (int) ($invoice->discount ?? 0);
  @endphp

  <div class="header">
    <div class="col-l">
      @if($logoData)
        <img src="{{ $logoData }}" alt="logo" style="max-height: 16mm; max-width: 50mm; margin-bottom: 2mm;">
      @endif
      <div class="company-name">{{ $company->name ?? 'PT Integrasi Bisnis Eksekutif' }}</div>
      <div class="company-sub">
        {{ $company->address ?? '-' }}<br>
        @if($company && $company->phone){{ $company->phone }} · @endif{{ $company->email ?? '-' }}<br>
        @if($company && $company->npwp)<strong>NPWP:</strong> {{ $company->npwp }}@endif
      </div>
    </div>
    <div class="col-r">
      <div class="doc-title">FAKTUR PENJUALAN</div>
      <div class="doc-number">{{ $invoice->number }}</div>
      <div class="doc-date">
        Tanggal: <strong>{{ \Carbon\Carbon::parse($invoice->date)->isoFormat('DD MMMM YYYY') }}</strong><br>
        @if($invoice->due_date)
          Jatuh Tempo: <strong>{{ \Carbon\Carbon::parse($invoice->due_date)->isoFormat('DD MMMM YYYY') }}</strong>
        @endif
      </div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-cell">
      <div class="info-lbl">Ditagihkan Kepada</div>
      <div class="info-val">{{ $invoice->customer_name }}</div>
      @if($invoice->customer_address)
        <div class="info-sub">{{ $invoice->customer_address }}</div>
      @endif
      @if($invoice->customer_npwp)
        <div class="info-sub"><strong>NPWP:</strong> {{ $invoice->customer_npwp }}</div>
      @endif
    </div>
    <div class="info-cell">
      <div class="info-lbl">Status & Referensi</div>
      <div class="info-val">
        @php $statusMap = ['draft'=>'DRAFT','sent'=>'Terkirim','partial'=>'Sebagian Dibayar','paid'=>'LUNAS','cancelled'=>'BATAL','overdue'=>'Jatuh Tempo']; @endphp
        {{ $statusMap[$invoice->status] ?? strtoupper($invoice->status) }}
      </div>
      @if($invoice->reference)
        <div class="info-sub"><strong>Ref:</strong> {{ $invoice->reference }}</div>
      @endif
      @if($invoice->description)
        <div class="info-sub">{{ $invoice->description }}</div>
      @endif
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width: 8mm;">#</th>
        <th>Deskripsi</th>
        <th class="r" style="width: 20mm;">Qty</th>
        <th style="width: 18mm;">Unit</th>
        <th class="r" style="width: 32mm;">Harga Satuan</th>
        <th class="r" style="width: 32mm;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invoice->lines as $i => $line)
        <tr>
          <td class="c">{{ $i + 1 }}</td>
          <td>{{ $line->description }}</td>
          <td class="r">{{ rtrim(rtrim(number_format($line->quantity, 2, ',', '.'), '0'), ',') }}</td>
          <td>{{ $line->unit ?? '-' }}</td>
          <td class="r">Rp {{ number_format($line->unit_price, 0, ',', '.') }}</td>
          <td class="r">Rp {{ number_format($line->amount, 0, ',', '.') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div class="row">
      <div class="lbl">Subtotal</div>
      <div class="val">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</div>
    </div>
    @if($invoice->discount > 0)
      <div class="row">
        <div class="lbl">Diskon</div>
        <div class="val">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</div>
      </div>
    @endif
    <div class="row">
      <div class="lbl">DPP (Dasar Pengenaan Pajak)</div>
      <div class="val">Rp {{ number_format($taxBase, 0, ',', '.') }}</div>
    </div>
    @if($invoice->tax_rate > 0)
      <div class="row">
        <div class="lbl">PPN {{ rtrim(rtrim(number_format($invoice->tax_rate, 2, ',', '.'), '0'), ',') }}%</div>
        <div class="val">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</div>
      </div>
    @endif
    <div class="row grand">
      <div class="lbl">TOTAL</div>
      <div class="val">Rp {{ number_format($invoice->total, 0, ',', '.') }}</div>
    </div>
    @if($invoice->paid_amount > 0)
      <div class="row" style="margin-top: 2mm;">
        <div class="lbl" style="color:#16a34a;">Sudah Dibayar</div>
        <div class="val" style="color:#16a34a;">Rp {{ number_format($invoice->paid_amount, 0, ',', '.') }}</div>
      </div>
      <div class="row">
        <div class="lbl" style="color:#dc2626;">Sisa Tagihan</div>
        <div class="val" style="color:#dc2626; font-weight: 800;">Rp {{ number_format($invoice->total - $invoice->paid_amount, 0, ',', '.') }}</div>
      </div>
    @endif
  </div>

  <div style="clear: both; padding-top: 4mm;">
    <div style="font-size: 9pt;">
      <strong>Terbilang:</strong> <em>« {{ ucfirst(trim($words((int) $invoice->total))) }} rupiah »</em>
    </div>
  </div>

  @if(!$company || !$company->npwp)
    <div class="pkp-notice">
      <strong>Catatan:</strong> Dokumen ini adalah Invoice Komersial. Kalau diperlukan Faktur Pajak resmi, hubungi tim Finance untuk penerbitan ulang dengan kode Faktur Pajak DJP.
    </div>
  @endif

  <div class="sig-grid">
    <div class="sig-col">
      <div style="font-size: 9pt; color: #64748b;">Pembeli/Customer</div>
      <div class="sig-line">
        <div class="sig-name">{{ $invoice->customer_name }}</div>
        <div style="font-size: 8pt; color: #64748b;">(Tanda tangan + cap stempel)</div>
      </div>
    </div>
    <div class="sig-col r">
      <div style="font-size: 9pt; color: #64748b;">Hormat Kami,</div>
      <div style="font-size: 9pt; color: #475569; margin-top: 1mm;">
        {{ $company->city ?? '-' }}, {{ \Carbon\Carbon::parse($invoice->date)->isoFormat('DD MMM YYYY') }}
      </div>
      @if($sigData)
        <img src="{{ $sigData }}" alt="signature" style="height: 18mm; margin-top: 4mm;">
      @endif
      <div class="sig-line">
        <div class="sig-name">{{ $company->name ?? 'PT Integrasi Bisnis Eksekutif' }}</div>
        <div style="font-size: 8pt; color: #64748b;">Finance Department</div>
      </div>
    </div>
  </div>

  <div class="footer">
    Pembayaran via transfer ke rekening yang tertera. Konfirmasi pembayaran ke {{ $company->finance_email ?? $company->email ?? 'finance@ibereality.com' }}.<br>
    Dokumen ini di-generate otomatis oleh sistem IBE Reality HRIS pada {{ now()->isoFormat('DD MMM YYYY HH:mm') }} WIB.
  </div>
</body>
</html>

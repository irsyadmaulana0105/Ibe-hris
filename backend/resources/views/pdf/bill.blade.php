{{-- Bill / Hutang Supplier PDF - A4 portrait. Internal record bukti tagihan
     dari supplier yang sudah diterima IBE. Lebih simple dari Invoice keluar. --}}
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $bill->number }}</title>
<style>
  @page { margin: 14mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #0f172a; margin: 0; }
  .header { display: table; width: 100%; margin-bottom: 6mm; border-bottom: 2px solid #b45309; padding-bottom: 4mm; }
  .header .col-l { display: table-cell; vertical-align: top; width: 60%; }
  .header .col-r { display: table-cell; vertical-align: top; text-align: right; }
  .company-name { font-size: 14pt; font-weight: 800; color: #0a363d; letter-spacing: .03em; }
  .company-sub { font-size: 8.5pt; color: #475569; margin-top: 1mm; }
  .doc-title { font-size: 16pt; font-weight: 800; color: #b45309; letter-spacing: .08em; text-align: right; }
  .doc-number { font-family: monospace; font-size: 11pt; color: #b45309; font-weight: 700; margin-top: 1mm; }
  .doc-date { font-size: 9pt; color: #475569; margin-top: 1mm; }
  .info-grid { display: table; width: 100%; margin: 4mm 0; }
  .info-cell { display: table-cell; vertical-align: top; width: 50%; padding: 3mm; }
  .info-cell.bordered { background: #fff7ed; border-left: 3px solid #b45309; border-radius: 2px; }
  .info-lbl { font-size: 8pt; color: #64748b; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 1mm; }
  .info-val { font-size: 10pt; font-weight: 600; }
  .info-sub { font-size: 9pt; color: #475569; margin-top: 1mm; line-height: 1.4; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 4mm; }
  table.items th { background: #b45309; color: #fff; padding: 2mm 3mm; font-size: 8.5pt; text-align: left; font-weight: 700; }
  table.items th.r { text-align: right; }
  table.items td { padding: 2mm 3mm; border-bottom: 1px solid #e2e8f0; font-size: 9.5pt; vertical-align: top; }
  table.items td.r { text-align: right; font-variant-numeric: tabular-nums; }
  table.items td.c { text-align: center; }
  .totals { width: 70mm; float: right; margin-top: 4mm; font-size: 10pt; }
  .totals .row { display: table; width: 100%; padding: 1mm 0; }
  .totals .row > div { display: table-cell; }
  .totals .row .lbl { color: #64748b; }
  .totals .row .val { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
  .totals .grand { background: #b45309; color: #fff; padding: 3mm 4mm; border-radius: 4px; margin-top: 2mm; font-weight: 800; font-size: 12pt; }
  .totals .grand .val { color: #fff; }
  .footer { margin-top: 16mm; padding-top: 3mm; border-top: 1px dotted #cbd5e1; font-size: 7.5pt; color: #94a3b8; text-align: center; clear: both; }
  .note { background: #fef3c7; border-left: 3px solid #b45309; padding: 2mm 3mm; font-size: 8pt; color: #854d0e; margin-top: 3mm; }
</style>
</head>
<body>
  @php
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
    $statusLabel = match ($bill->status) {
        'draft'     => 'DRAFT',
        'received'  => 'Diterima',
        'partial'   => 'Sebagian Dibayar',
        'paid'      => 'LUNAS',
        'cancelled' => 'BATAL',
        'overdue'   => 'Jatuh Tempo',
        default     => strtoupper($bill->status),
    };
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
      <div class="doc-title">BILL / HUTANG SUPPLIER</div>
      <div class="doc-number">{{ $bill->number }}</div>
      <div class="doc-date">
        Tanggal: <strong>{{ \Carbon\Carbon::parse($bill->date)->isoFormat('DD MMMM YYYY') }}</strong><br>
        @if($bill->due_date)
          Jatuh Tempo: <strong>{{ \Carbon\Carbon::parse($bill->due_date)->isoFormat('DD MMMM YYYY') }}</strong>
        @endif
      </div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-cell bordered">
      <div class="info-lbl">Dari Supplier</div>
      <div class="info-val">{{ $bill->supplier_name }}</div>
      @if($bill->supplier_invoice_no)
        <div class="info-sub">No. Faktur Supplier: <strong>{{ $bill->supplier_invoice_no }}</strong></div>
      @endif
    </div>
    <div class="info-cell">
      <div class="info-lbl">Status</div>
      <div class="info-val">{{ $statusLabel }}</div>
      @if($bill->description)
        <div class="info-sub">{{ $bill->description }}</div>
      @endif
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width: 8mm;">#</th>
        <th>Akun Beban/Aset</th>
        <th>Deskripsi</th>
        <th class="r" style="width: 18mm;">Qty</th>
        <th style="width: 14mm;">Unit</th>
        <th class="r" style="width: 28mm;">Harga Satuan</th>
        <th class="r" style="width: 28mm;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      @foreach($bill->lines as $i => $line)
        <tr>
          <td class="c">{{ $i + 1 }}</td>
          <td style="font-size: 8.5pt;">
            @if($line->expenseAccount)
              {{ $line->expenseAccount->code }} - {{ $line->expenseAccount->name }}
            @else
              -
            @endif
          </td>
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
      <div class="val">Rp {{ number_format($bill->subtotal, 0, ',', '.') }}</div>
    </div>
    @if($bill->tax_rate > 0)
      <div class="row">
        <div class="lbl">PPN Masukan {{ rtrim(rtrim(number_format($bill->tax_rate, 2, ',', '.'), '0'), ',') }}%</div>
        <div class="val">Rp {{ number_format($bill->tax_amount, 0, ',', '.') }}</div>
      </div>
    @endif
    <div class="row grand">
      <div class="lbl">TOTAL HUTANG</div>
      <div class="val">Rp {{ number_format($bill->total, 0, ',', '.') }}</div>
    </div>
    @if($bill->paid_amount > 0)
      <div class="row" style="margin-top: 2mm;">
        <div class="lbl" style="color:#16a34a;">Sudah Dibayar</div>
        <div class="val" style="color:#16a34a;">Rp {{ number_format($bill->paid_amount, 0, ',', '.') }}</div>
      </div>
      <div class="row">
        <div class="lbl" style="color:#dc2626;">Sisa Hutang</div>
        <div class="val" style="color:#dc2626; font-weight: 800;">Rp {{ number_format($bill->total - $bill->paid_amount, 0, ',', '.') }}</div>
      </div>
    @endif
  </div>

  <div style="clear: both; padding-top: 8mm;">
    <div class="note">
      <strong>Catatan Internal:</strong> Dokumen ini adalah record tagihan dari supplier untuk arsip Finance.
      Bukan dokumen yang dikirim ke pihak luar. Pembayaran dilakukan berdasarkan persetujuan internal +
      sesuai workflow Bill receive/pay.
    </div>
  </div>

  <div class="footer">
    Bill di-record di sistem IBE Reality Finance pada {{ now()->isoFormat('DD MMM YYYY HH:mm') }} WIB.
    Verifikasi data ke tim Finance ({{ $company->finance_email ?? 'finance@ibereality.com' }}).
  </div>
</body>
</html>

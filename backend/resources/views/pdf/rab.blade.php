<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ $q->quote_number }} - RAB</title>
  <style>
    * { box-sizing: border-box; }
    @page { margin: 18mm 14mm 16mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1f2937; margin: 0; }
    .header { border-bottom: 3px double #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
    .brand-row { display: table; width: 100%; }
    .brand-row > div { display: table-cell; vertical-align: top; }
    .brand-name { font-size: 16pt; font-weight: 800; color: #0f766e; letter-spacing: -.02em; }
    .brand-tag { font-size: 8.5pt; color: #64748b; }
    .doc-meta { text-align: right; }
    .doc-meta .num { font-size: 14pt; font-weight: 800; color: #0f766e; font-family: monospace; }
    .doc-meta .date { font-size: 9pt; color: #64748b; }

    h1 { font-size: 13pt; text-align: center; margin: 6px 0 4px; letter-spacing: .05em; }
    .subtitle { text-align: center; font-size: 9pt; color: #64748b; margin-bottom: 10px; }

    .to-block { margin: 10px 0; padding: 8px 10px; background: #f8fafc; border-left: 3px solid #0f766e; font-size: 9.5pt; }
    .to-block .label { font-size: 8pt; color: #64748b; text-transform: uppercase; letter-spacing: .1em; }
    .to-block .name { font-weight: 700; font-size: 11pt; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.items th { background: #0f766e; color: #fff; padding: 6px 5px; font-size: 9pt; font-weight: 700; text-align: left; }
    table.items td { padding: 5px; border-bottom: 1px solid #e2e8f0; font-size: 9.5pt; vertical-align: top; }
    table.items td.num { text-align: right; font-family: monospace; white-space: nowrap; }
    table.items td.ctr { text-align: center; }
    table.items tr.section { background: #f0fdfa; }
    table.items tr.section td { font-weight: 700; color: #0f766e; font-size: 10pt; padding: 7px 5px; border-top: 2px solid #14b8a6; }
    table.items tr.subtotal td { background: #fafafa; font-weight: 700; padding: 4px 5px; }

    .totals { width: 50%; float: right; margin-top: 10px; border-collapse: collapse; }
    .totals td { padding: 4px 8px; font-size: 10pt; }
    .totals td.label { text-align: right; color: #64748b; }
    .totals td.val { text-align: right; font-family: monospace; font-weight: 600; }
    .totals tr.grand td { border-top: 2px solid #0f766e; border-bottom: 3px double #0f766e; font-size: 12pt; font-weight: 800; color: #0f766e; padding: 8px 8px; }

    .terms { clear: both; margin-top: 24px; font-size: 9pt; }
    .terms h3 { font-size: 10pt; color: #0f766e; margin: 8px 0 4px; border-bottom: 1px dotted #94a3b8; padding-bottom: 2px; }
    .terms ul { margin: 4px 0 8px 16px; padding: 0; }
    .terms li { margin: 2px 0; }

    .signature { margin-top: 36px; width: 100%; }
    .signature .col { display: inline-block; width: 48%; vertical-align: top; text-align: center; }
    .signature .line { margin-top: 50px; border-top: 1px solid #1f2937; padding-top: 4px; font-size: 9pt; }
    .signature .label { font-size: 8.5pt; color: #64748b; }

    .footer { position: fixed; bottom: 6mm; left: 14mm; right: 14mm; text-align: center; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand-row">
      <div>
        <div class="brand-name">{{ $company['brand'] }}</div>
        <div class="brand-tag">{{ $company['name'] }}</div>
        <div class="brand-tag">{{ $company['address'] }} · {{ $company['email'] }} · {{ $company['website'] }}</div>
      </div>
      <div class="doc-meta">
        <div class="num">{{ $q->quote_number }}</div>
        <div class="date">Tanggal: {{ \Carbon\Carbon::parse($q->quote_date)->translatedFormat('d F Y') }}</div>
        @if ($q->valid_until)
          <div class="date">Berlaku s.d.: {{ \Carbon\Carbon::parse($q->valid_until)->translatedFormat('d F Y') }}</div>
        @endif
      </div>
    </div>
  </div>

  <h1>RENCANA ANGGARAN BIAYA (RAB)</h1>
  <div class="subtitle">{{ $q->title }}</div>

  <div class="to-block">
    <div class="label">Kepada Yth.</div>
    <div class="name">{{ $q->customer_name }}</div>
    @if ($q->customer_pic_name)
      <div>{{ $q->customer_pic_name }}@if ($q->customer_pic_title) - {{ $q->customer_pic_title }}@endif</div>
    @endif
    @if ($q->customer_address)
      <div style="white-space: pre-line; margin-top: 2px;">{{ $q->customer_address }}</div>
    @endif
    @if ($q->project_name)
      <div style="margin-top: 4px;"><strong>Proyek:</strong> {{ $q->project_name }}</div>
    @endif
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width: 28px;">No</th>
        <th>Uraian Pekerjaan / Barang</th>
        <th style="width: 50px;">Qty</th>
        <th style="width: 50px;">Satuan</th>
        <th style="width: 90px;">Harga Satuan</th>
        <th style="width: 100px;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sections as $section)
        @if ($section['label'])
          <tr class="section">
            <td colspan="6">{{ $section['label'] }}</td>
          </tr>
        @endif
        @foreach ($section['items'] as $idx => $item)
          <tr>
            <td class="ctr">{{ $idx + 1 }}</td>
            <td>
              {{ $item->description }}
              @if ($item->notes)
                <div style="font-size: 8.5pt; color: #64748b; margin-top: 2px;">{{ $item->notes }}</div>
              @endif
            </td>
            <td class="num">{{ rtrim(rtrim(number_format($item->qty, 4, ',', '.'), '0'), ',') }}</td>
            <td class="ctr">{{ $item->unit }}</td>
            <td class="num">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
            <td class="num">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
          </tr>
        @endforeach
        @if ($section['label'])
          <tr class="subtotal">
            <td colspan="5" style="text-align: right;">Subtotal {{ $section['label'] }}</td>
            <td class="num">{{ number_format($section['subtotal'], 0, ',', '.') }}</td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr>
      <td class="label">Subtotal</td>
      <td class="val">Rp {{ number_format($q->subtotal, 0, ',', '.') }}</td>
    </tr>
    @if ($q->discount > 0)
      <tr>
        <td class="label">Diskon @if ($q->discount_pct > 0)({{ rtrim(rtrim(number_format($q->discount_pct, 2, ',', '.'), '0'), ',') }}%)@endif</td>
        <td class="val">− Rp {{ number_format($q->discount, 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td class="label">Setelah Diskon</td>
        <td class="val">Rp {{ number_format($q->after_discount, 0, ',', '.') }}</td>
      </tr>
    @endif
    <tr>
      <td class="label">PPN {{ rtrim(rtrim(number_format($q->tax_rate, 2, ',', '.'), '0'), ',') }}%</td>
      <td class="val">Rp {{ number_format($q->tax_amount, 0, ',', '.') }}</td>
    </tr>
    <tr class="grand">
      <td class="label">TOTAL</td>
      <td class="val">Rp {{ number_format($q->total, 0, ',', '.') }}</td>
    </tr>
  </table>

  <div class="terms">
    @if ($q->payment_terms)
      <h3>Termin Pembayaran</h3>
      <div style="white-space: pre-line;">{{ $q->payment_terms }}</div>
    @endif
    @if ($q->delivery_terms)
      <h3>Termin Pengiriman</h3>
      <div style="white-space: pre-line;">{{ $q->delivery_terms }}</div>
    @endif
    @if ($q->warranty_terms)
      <h3>Garansi</h3>
      <div style="white-space: pre-line;">{{ $q->warranty_terms }}</div>
    @endif
    @if ($q->notes)
      <h3>Catatan</h3>
      <div style="white-space: pre-line;">{{ $q->notes }}</div>
    @endif
  </div>

  <div class="signature">
    <div class="col">
      <div class="label">Hormat kami,</div>
      <div class="line">
        ({{ $q->preparedBy?->name ?? '-' }})<br>
        <span style="font-size: 8pt; color: #64748b;">{{ $company['brand'] }}</span>
      </div>
    </div>
    <div class="col">
      <div class="label">Disetujui oleh,</div>
      <div class="line">
        ({{ $q->customer_pic_name ?? '....................' }})<br>
        <span style="font-size: 8pt; color: #64748b;">{{ $q->customer_name }}</span>
      </div>
    </div>
  </div>

  <div class="footer">
    {{ $company['brand'] }} · {{ $q->quote_number }} · Generated {{ now()->translatedFormat('d M Y H:i') }}
  </div>
</body>
</html>

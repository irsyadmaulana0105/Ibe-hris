<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ $q->quote_number }} - Surat Penawaran</title>
  <style>
    * { box-sizing: border-box; }
    @page { margin: 18mm 18mm 16mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1f2937; margin: 0; line-height: 1.5; }

    .letterhead { border-bottom: 3px double #0f766e; padding-bottom: 8px; margin-bottom: 24px; }
    .letterhead .brand { font-size: 18pt; font-weight: 800; color: #0f766e; letter-spacing: -.02em; }
    .letterhead .company-name { font-size: 11pt; color: #1f2937; font-weight: 600; }
    .letterhead .address { font-size: 9.5pt; color: #64748b; margin-top: 2px; }

    .doc-meta { margin-bottom: 14px; }
    .doc-meta table { width: 100%; font-size: 10.5pt; }
    .doc-meta td:first-child { width: 70px; vertical-align: top; }
    .doc-meta td:nth-child(2) { width: 10px; }

    .to-block { margin-bottom: 14px; font-size: 10.5pt; }
    .to-block .header { color: #1f2937; }
    .to-block .name { font-weight: 700; }

    h2.title { text-align: center; font-size: 12pt; font-weight: 800; margin: 8px 0 14px; text-transform: uppercase; letter-spacing: .04em; text-decoration: underline; }

    p { margin: 8px 0; text-align: justify; }

    table.summary { width: 100%; border-collapse: collapse; margin: 12px 0; }
    table.summary th { background: #0f766e; color: #fff; padding: 6px 6px; font-size: 9pt; font-weight: 700; text-align: left; }
    table.summary td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; vertical-align: top; }
    table.summary td.num { text-align: right; font-family: monospace; white-space: nowrap; }
    table.summary tr.section td { background: #f0fdfa; font-weight: 700; color: #0f766e; padding: 5px 6px; border-top: 1px solid #14b8a6; }
    table.summary tfoot td { border-top: 2px solid #0f766e; font-weight: 700; padding: 5px 6px; background: #f8fafc; }
    table.summary tfoot tr.grand td { border-bottom: 3px double #0f766e; font-size: 12pt; color: #0f766e; font-weight: 800; padding: 7px 6px; }

    .terms-block { margin-top: 14px; font-size: 10pt; }
    .terms-block h4 { margin: 8px 0 2px; font-size: 10pt; color: #0f766e; }

    .signature { margin-top: 32px; }
    .signature .col { display: inline-block; vertical-align: top; width: 48%; text-align: center; }
    .signature .line { margin-top: 50px; border-top: 1px solid #1f2937; padding-top: 4px; font-size: 9.5pt; }
    .signature .label { font-size: 10pt; color: #1f2937; }

    .footer { position: fixed; bottom: 6mm; left: 18mm; right: 18mm; text-align: center; font-size: 8.5pt; color: #94a3b8; }
  </style>
</head>
<body>
  <div class="letterhead">
    <div class="brand">{{ $company['brand'] }}</div>
    <div class="company-name">{{ $company['name'] }}</div>
    <div class="address">{{ $company['address'] }} · {{ $company['email'] }} · {{ $company['website'] }}</div>
  </div>

  <div class="doc-meta">
    <table>
      <tr>
        <td>Nomor</td><td>:</td>
        <td><strong>{{ $q->quote_number }}</strong></td>
        <td style="text-align: right;">{{ \Carbon\Carbon::parse($q->quote_date)->translatedFormat('d F Y') }}</td>
      </tr>
      <tr>
        <td>Lampiran</td><td>:</td>
        <td>1 (satu) berkas RAB</td>
        <td></td>
      </tr>
      <tr>
        <td>Perihal</td><td>:</td>
        <td colspan="2"><strong>Penawaran Harga - {{ $q->title }}</strong></td>
      </tr>
    </table>
  </div>

  <div class="to-block">
    <div class="header">Kepada Yth.</div>
    <div class="name">{{ $q->customer_name }}</div>
    @if ($q->customer_pic_name)
      <div>{{ $q->customer_pic_name }}@if ($q->customer_pic_title) - {{ $q->customer_pic_title }}@endif</div>
    @endif
    @if ($q->customer_address)
      <div style="white-space: pre-line;">di {{ $q->customer_address }}</div>
    @endif
  </div>

  <h2 class="title">Surat Penawaran Harga</h2>

  @if ($q->preamble)
    <p style="white-space: pre-line;">{{ $q->preamble }}</p>
  @else
    <p>Dengan hormat,</p>
    <p>Sehubungan dengan permintaan penawaran dari Bapak/Ibu, dengan ini kami {{ $company['brand'] }} mengajukan penawaran harga untuk{{ $q->project_name ? ' proyek "' . $q->project_name . '"' : '' }} dengan rincian sebagai berikut:</p>
  @endif

  <table class="summary">
    <thead>
      <tr>
        <th style="width: 24px;">No</th>
        <th>Uraian</th>
        <th style="width: 50px;">Qty</th>
        <th style="width: 50px;">Satuan</th>
        <th style="width: 80px;">Harga</th>
        <th style="width: 95px;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sections as $section)
        @if ($section['label'])
          <tr class="section"><td colspan="6">{{ $section['label'] }}</td></tr>
        @endif
        @foreach ($section['items'] as $idx => $item)
          <tr>
            <td style="text-align: center;">{{ $idx + 1 }}</td>
            <td>{{ $item->description }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($item->qty, 4, ',', '.'), '0'), ',') }}</td>
            <td style="text-align: center;">{{ $item->unit }}</td>
            <td class="num">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
            <td class="num">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
          </tr>
        @endforeach
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5" style="text-align: right;">Subtotal</td>
        <td class="num">{{ number_format($q->subtotal, 0, ',', '.') }}</td>
      </tr>
      @if ($q->discount > 0)
        <tr>
          <td colspan="5" style="text-align: right;">Diskon @if ($q->discount_pct > 0)({{ rtrim(rtrim(number_format($q->discount_pct, 2, ',', '.'), '0'), ',') }}%)@endif</td>
          <td class="num">− {{ number_format($q->discount, 0, ',', '.') }}</td>
        </tr>
      @endif
      <tr>
        <td colspan="5" style="text-align: right;">PPN {{ rtrim(rtrim(number_format($q->tax_rate, 2, ',', '.'), '0'), ',') }}%</td>
        <td class="num">{{ number_format($q->tax_amount, 0, ',', '.') }}</td>
      </tr>
      <tr class="grand">
        <td colspan="5" style="text-align: right;">TOTAL</td>
        <td class="num">Rp {{ number_format($q->total, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>

  <p style="margin-top: 10px;">
    <strong>Terbilang:</strong>
    <em>{{ ucwords(strtolower(\App\Helpers\TerbilangHelper::convert($q->total))) ?? '-' }} Rupiah</em>
  </p>

  <div class="terms-block">
    @if ($q->payment_terms)
      <h4>Termin Pembayaran:</h4>
      <div style="white-space: pre-line;">{{ $q->payment_terms }}</div>
    @endif
    @if ($q->delivery_terms)
      <h4>Pengiriman:</h4>
      <div style="white-space: pre-line;">{{ $q->delivery_terms }}</div>
    @endif
    @if ($q->warranty_terms)
      <h4>Garansi:</h4>
      <div style="white-space: pre-line;">{{ $q->warranty_terms }}</div>
    @endif
    @if ($q->valid_until)
      <h4>Masa Berlaku Penawaran:</h4>
      <div>Penawaran ini berlaku sampai dengan <strong>{{ \Carbon\Carbon::parse($q->valid_until)->translatedFormat('d F Y') }}</strong>.</div>
    @endif
    @if ($q->notes)
      <h4>Catatan Tambahan:</h4>
      <div style="white-space: pre-line;">{{ $q->notes }}</div>
    @endif
  </div>

  <p style="margin-top: 16px;">
    Demikian penawaran ini kami sampaikan. Apabila Bapak/Ibu setuju, mohon kiranya dapat menandatangani halaman ini
    dan mengirimkan kembali kepada kami sebagai bentuk persetujuan. Atas perhatian dan kerjasamanya, kami ucapkan terima kasih.
  </p>

  <div class="signature">
    <div class="col">
      <div class="label">Hormat kami,</div>
      <div class="line">
        ({{ $q->preparedBy?->name ?? '-' }})<br>
        <span style="font-size: 9pt; color: #64748b;">{{ $company['brand'] }}</span>
      </div>
    </div>
    <div class="col">
      <div class="label">Menyetujui,</div>
      <div class="line">
        ({{ $q->customer_pic_name ?? '....................' }})<br>
        <span style="font-size: 9pt; color: #64748b;">{{ $q->customer_name }}</span>
      </div>
    </div>
  </div>

  <div class="footer">{{ $company['brand'] }} · {{ $q->quote_number }}</div>
</body>
</html>

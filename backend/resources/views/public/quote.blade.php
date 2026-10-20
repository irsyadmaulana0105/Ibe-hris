<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Penawaran {{ $q->quote_number }} - {{ $company['brand'] }}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1f2937; line-height: 1.5; }
    .wrap { max-width: 880px; margin: 0 auto; padding: 24px 16px; }
    .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 24px; margin-bottom: 16px; }
    .brand { font-size: 22px; font-weight: 800; color: #0f766e; letter-spacing: -.02em; }
    .brand-tag { font-size: 12px; color: #64748b; }
    .doc-meta { background: #f0fdfa; border-left: 4px solid #0f766e; padding: 12px 16px; border-radius: 6px; margin-top: 16px; }
    .doc-meta .num { font-family: monospace; font-weight: 700; font-size: 18px; color: #0f766e; }
    .doc-meta .date { font-size: 12px; color: #64748b; margin-top: 2px; }

    h1 { font-size: 18px; margin: 16px 0 12px; }
    h2 { font-size: 14px; margin: 14px 0 8px; color: #0f766e; }

    .to { background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 12px; font-size: 14px; }
    .to .label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .1em; }
    .to .name { font-weight: 700; font-size: 15px; }

    table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
    th { background: #0f766e; color: #fff; padding: 8px 6px; text-align: left; font-weight: 700; }
    td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    td.num { text-align: right; font-family: monospace; white-space: nowrap; }
    td.ctr { text-align: center; }
    tr.section td { background: #f0fdfa; font-weight: 700; color: #0f766e; padding-top: 8px; border-top: 2px solid #14b8a6; }
    tr.subtotal td { background: #fafafa; font-weight: 700; }

    .totals { float: right; min-width: 320px; margin-top: 12px; }
    .totals tr td { border-bottom: none; padding: 4px 8px; }
    .totals tr.grand td { border-top: 2px solid #0f766e; border-bottom: 3px double #0f766e; font-size: 17px; font-weight: 800; color: #0f766e; padding: 8px 8px; }
    .totals td.label { text-align: right; color: #64748b; }
    .totals td.val { text-align: right; font-family: monospace; font-weight: 600; }

    .terms { clear: both; margin-top: 12px; font-size: 13px; }
    .terms h3 { font-size: 12px; color: #0f766e; margin: 10px 0 4px; }
    .terms .block { white-space: pre-line; color: #475569; }

    .actions { position: sticky; bottom: 0; background: #fff; box-shadow: 0 -4px 20px rgba(0,0,0,.06); padding: 16px; border-radius: 12px 12px 0 0; margin-top: 16px; }
    .actions .btn { display: inline-block; padding: 12px 24px; font-size: 15px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; }
    .btn-accept { background: #16a34a; color: white; }
    .btn-accept:hover { background: #15803d; }
    .btn-reject { background: white; color: #ef4444; border: 2px solid #ef4444; }
    .btn-reject:hover { background: #fef2f2; }
    .status-tag { display: inline-block; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 13px; }
    .tag-draft { background: #f1f5f9; color: #64748b; }
    .tag-sent { background: #dbeafe; color: #1e40af; }
    .tag-accepted { background: #dcfce7; color: #166534; }
    .tag-rejected { background: #fee2e2; color: #991b1b; }
    .tag-expired { background: #fef3c7; color: #92400e; }

    .modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: none; align-items: center; justify-content: center; z-index: 100; }
    .modal-bg.show { display: flex; }
    .modal { background: white; border-radius: 12px; padding: 24px; max-width: 480px; width: 90%; }
    .modal h3 { margin-bottom: 12px; font-size: 16px; }
    .modal textarea { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 13px; }
    .modal input { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 13px; margin-bottom: 8px; }
    .modal .row { display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; }
    .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
    .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

    @media (max-width: 600px) {
      .totals { width: 100%; min-width: 0; }
      .actions .btn { display: block; width: 100%; margin-top: 8px; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">{{ $company['brand'] }}</div>
    <div class="brand-tag">{{ $company['name'] }}</div>
    <div class="brand-tag">{{ $company['address'] }} · {{ $company['email'] }}</div>

    <div class="doc-meta">
      <div class="num">{{ $q->quote_number }}</div>
      <div class="date">Tanggal: {{ \Carbon\Carbon::parse($q->quote_date)->translatedFormat('d F Y') }}</div>
      @if ($q->valid_until)
        <div class="date">Berlaku s.d.: <strong>{{ \Carbon\Carbon::parse($q->valid_until)->translatedFormat('d F Y') }}</strong></div>
      @endif
      <div style="margin-top: 8px;">
        <span class="status-tag tag-{{ $q->status }}">
          @switch($q->status)
            @case('draft') 📝 Draft @break
            @case('sent') 📤 Menunggu Persetujuan @break
            @case('accepted') ✅ Sudah Disetujui @break
            @case('rejected') ❌ Ditolak @break
            @case('expired') ⏰ Kedaluwarsa @break
            @default {{ $q->status }}
          @endswitch
        </span>
      </div>
    </div>
  </div>

  <div class="card">
    @if ($q->status === 'accepted')
      <div class="alert alert-success">
        ✅ Penawaran ini sudah disetujui pada {{ $q->accepted_at?->translatedFormat('d F Y H:i') }}. Tim kami akan menghubungi Anda untuk langkah selanjutnya.
      </div>
    @elseif ($q->status === 'rejected')
      <div class="alert alert-error">
        ❌ Penawaran ini telah ditolak. @if ($q->rejection_reason) Alasan: {{ $q->rejection_reason }} @endif
      </div>
    @elseif ($q->is_expired)
      <div class="alert alert-error">
        ⏰ Penawaran ini sudah kedaluwarsa. Silakan hubungi kami untuk penawaran terbaru.
      </div>
    @endif

    <h1>{{ $q->title }}</h1>
    @if ($q->project_name)
      <p style="color: #64748b; font-size: 13px;">Proyek: <strong>{{ $q->project_name }}</strong></p>
    @endif

    <div class="to">
      <div class="label">Kepada Yth.</div>
      <div class="name">{{ $q->customer_name }}</div>
      @if ($q->customer_pic_name)
        <div>{{ $q->customer_pic_name }}@if ($q->customer_pic_title) - {{ $q->customer_pic_title }}@endif</div>
      @endif
      @if ($q->customer_address)
        <div style="margin-top: 4px; white-space: pre-line;">{{ $q->customer_address }}</div>
      @endif
    </div>

    @if ($q->preamble)
      <p style="white-space: pre-line; margin: 12px 0; color: #475569;">{{ $q->preamble }}</p>
    @endif

    <h2>Rincian Penawaran</h2>
    <table>
      <thead>
        <tr>
          <th style="width: 28px;">No</th>
          <th>Uraian</th>
          <th style="width: 50px;">Qty</th>
          <th style="width: 50px;">Satuan</th>
          <th style="width: 90px;">Harga</th>
          <th style="width: 110px;">Jumlah</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($sections as $section)
          @if ($section['label'])
            <tr class="section"><td colspan="6">{{ $section['label'] }}</td></tr>
          @endif
          @foreach ($section['items'] as $idx => $item)
            <tr>
              <td class="ctr">{{ $idx + 1 }}</td>
              <td>{{ $item->description }}</td>
              <td class="num">{{ rtrim(rtrim(number_format($item->qty, 4, ',', '.'), '0'), ',') }}</td>
              <td class="ctr">{{ $item->unit }}</td>
              <td class="num">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
              <td class="num">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
            </tr>
          @endforeach
          @if ($section['label'])
            <tr class="subtotal">
              <td colspan="5" style="text-align: right;">Subtotal</td>
              <td class="num">{{ number_format($section['subtotal'], 0, ',', '.') }}</td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>

    <table class="totals">
      <tr><td class="label">Subtotal</td><td class="val">Rp {{ number_format($q->subtotal, 0, ',', '.') }}</td></tr>
      @if ($q->discount > 0)
        <tr><td class="label">Diskon</td><td class="val">− Rp {{ number_format($q->discount, 0, ',', '.') }}</td></tr>
      @endif
      <tr><td class="label">PPN {{ rtrim(rtrim(number_format($q->tax_rate, 2, ',', '.'), '0'), ',') }}%</td><td class="val">Rp {{ number_format($q->tax_amount, 0, ',', '.') }}</td></tr>
      <tr class="grand"><td class="label">TOTAL</td><td class="val">Rp {{ number_format($q->total, 0, ',', '.') }}</td></tr>
    </table>
  </div>

  <div class="card terms">
    @if ($q->payment_terms)<h3>Termin Pembayaran</h3><div class="block">{{ $q->payment_terms }}</div>@endif
    @if ($q->delivery_terms)<h3>Pengiriman</h3><div class="block">{{ $q->delivery_terms }}</div>@endif
    @if ($q->warranty_terms)<h3>Garansi</h3><div class="block">{{ $q->warranty_terms }}</div>@endif
    @if ($q->notes)<h3>Catatan</h3><div class="block">{{ $q->notes }}</div>@endif
  </div>

  @if (in_array($q->status, ['draft', 'sent']) && !$q->is_expired)
    <div class="actions">
      <strong>Persetujuan Online:</strong> Klik salah satu tombol di bawah untuk konfirmasi.
      <div style="margin-top: 12px; text-align: right;">
        <button class="btn btn-reject" onclick="showModal('reject')">❌ Tolak Penawaran</button>
        <button class="btn btn-accept" onclick="showModal('accept')">✅ Setujui Penawaran</button>
      </div>
    </div>
  @endif
</div>

<div id="modal" class="modal-bg">
  <div class="modal">
    <h3 id="modal-title">-</h3>
    <div id="modal-form"></div>
    <div class="row">
      <button class="btn" style="background: #e2e8f0;" onclick="closeModal()">Batal</button>
      <button id="modal-submit" class="btn btn-accept">Konfirmasi</button>
    </div>
  </div>
</div>

<script>
  const TOKEN = @json($token);
  function showModal(action) {
    const modal = document.getElementById('modal');
    const title = document.getElementById('modal-title');
    const form = document.getElementById('modal-form');
    const submit = document.getElementById('modal-submit');
    if (action === 'accept') {
      title.textContent = '✅ Setujui Penawaran';
      form.innerHTML = '<label>Nama PIC yang menyetujui <input id="pic" placeholder="Contoh: Bpk. Ahmad - Procurement Manager"></label>' +
                       '<label>Catatan (opsional) <textarea id="note" rows="3" placeholder="Mohon segera proses..."></textarea></label>';
      submit.className = 'btn btn-accept';
      submit.textContent = 'Setujui';
      submit.onclick = () => doAction('accept', { pic_name: document.getElementById('pic').value, note: document.getElementById('note').value });
    } else {
      title.textContent = '❌ Tolak Penawaran';
      form.innerHTML = '<label>Alasan (opsional) <textarea id="reason" rows="3" placeholder="Harga terlalu tinggi / spek tidak sesuai / dll"></textarea></label>';
      submit.className = 'btn btn-reject';
      submit.textContent = 'Tolak';
      submit.onclick = () => doAction('reject', { reason: document.getElementById('reason').value });
    }
    modal.classList.add('show');
  }
  function closeModal() { document.getElementById('modal').classList.remove('show'); }
  async function doAction(action, payload) {
    const submit = document.getElementById('modal-submit');
    submit.disabled = true; submit.textContent = 'Memproses...';
    try {
      const res = await fetch(`/api/v1/public/quote/${TOKEN}/${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.message ?? 'Gagal');
      alert(json.message ?? 'Berhasil');
      window.location.reload();
    } catch (e) {
      alert('Gagal: ' + e.message);
      submit.disabled = false;
      submit.textContent = 'Coba lagi';
    }
  }
</script>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $letter->subject }}</title>
    <style>
        /* Reserve 30mm di bawah untuk footer fixed (header tetap inline di page 1 saja) */
        @page { margin: 22mm 22mm 32mm 22mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; line-height: 1.5; margin: 0; padding: 0; }
        .center { text-align: center; }
        .right  { text-align: right; }

        /* Footer anchored ke bawah setiap halaman (dompdf position:fixed) */
        .pdf-footer-fixed {
            position: fixed;
            left: 22mm;
            right: 22mm;
            bottom: 12mm;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8px;
            color: #64748b;
        }
        .pdf-footer-band-top { background:#f0fdfa; height:1px; line-height:1px; }
        .pdf-footer-band-bot { background:#0d9488; height:3px; line-height:3px; }
        .doc-title { text-align:center; font-weight:800; text-transform:uppercase; letter-spacing:1.2px; font-size:14px; color:#0f172a; margin:24px 0 4px; }
        .doc-number { text-align:center; font-size:11px; color:#475569; margin-bottom:18px; font-weight:600; }
        .doc-body p { margin: 0 0 10px; text-align: justify; }
        .info-table { width:100%; border-collapse:collapse; margin:10px 0; font-size:10.5px; }
        .info-table td { padding:3px 6px; vertical-align:top; }
        .signoff { margin-top:30px; }
        .sign-block { width:280px; text-align:center; }
        .sign-block-right { margin-left:auto; }
        .sign-location { font-size:10.5px; color:#0f172a; margin-bottom:4px; }
        .sign-title { font-weight:700; color:#0f172a; font-size:10.5px; }
        .sign-space { height:70px; position:relative; text-align:center; }
        .sign-name { font-weight:800; text-decoration:underline; color:#0f172a; padding-top:6px; font-size:10.5px; }
        .sign-position { color:#475569; font-size:10px; margin-top:2px; }
        .stamp-img { position:absolute; left:30px; top:0; max-width:80px; max-height:80px; opacity:0.7; }
        .ttd-img { max-width:100px; max-height:60px; }
        .twoparty td { width:50%; vertical-align:top; }
    </style>
</head>
<body>

@include('surat.partials.kop-header', [
    'kop_company'  => $kop_company,
    'kop_brand'    => $kop_brand,
    'kop_tagline'  => $kop_tagline,
    'kop_address'  => $kop_address,
    'kop_email'    => $kop_email,
    'kop_phone'    => $kop_phone,
    'kop_website'  => $kop_website,
])

<div class="doc-title">{{ $letter->category?->name ?? 'Surat' }}</div>
<div class="doc-number">Nomor: {{ $letter->letter_number ?? '⟨ otomatis saat APPROVED ⟩' }}</div>

<div class="doc-body" style="margin-top:14px;">
    {!! $renderedBody !!}
</div>

{{-- Sign-off block - single signer atau two-party kalau cosigner ada --}}
<div class="signoff">
    @if($cosigner)
        <table class="twoparty" style="width:100%; border-collapse:collapse;">
            <tr>
                <td>
                    <div class="sign-block">
                        <div class="sign-location">
                            {{ $kop_address ? explode(',', $kop_address)[0] : '' }},
                            {{ $letter->letter_date?->isoFormat('D MMMM YYYY') }}
                        </div>
                        <div class="sign-title">Pihak Pertama,</div>
                        <div class="sign-title" style="margin-bottom:4px;">{{ $kop_company }}</div>
                        <div class="sign-space">
                            @if($signer_stamp_data_uri)
                                <img class="stamp-img" src="{{ $signer_stamp_data_uri }}" alt="stamp">
                            @endif
                            @if($signer_signature_data_uri)
                                <img class="ttd-img" src="{{ $signer_signature_data_uri }}" alt="ttd">
                            @elseif($letter->status === 'signed')
                                <em style="color:#64748b;">[TTD digital]</em>
                            @endif
                        </div>
                        <div class="sign-name">{{ $signer?->name ?? 'Muhammad Aldy Iyik Be' }}</div>
                        <div class="sign-position">{{ $signer?->title ?? 'Direktur Utama' }}</div>
                    </div>
                </td>
                <td>
                    <div class="sign-block sign-block-right">
                        <div class="sign-location">&nbsp;</div>
                        <div class="sign-title">Pihak Kedua,</div>
                        <div class="sign-title" style="margin-bottom:4px;">{{ $letter->recipient_company ?? '-' }}</div>
                        <div class="sign-space">
                            @if($cosigner_stamp_data_uri)
                                <img class="stamp-img" src="{{ $cosigner_stamp_data_uri }}" alt="stamp">
                            @endif
                            @if($cosigner_signature_data_uri)
                                <img class="ttd-img" src="{{ $cosigner_signature_data_uri }}" alt="ttd">
                            @else
                                <em style="color:#cbd5e1;">[TTD pihak kedua]</em>
                            @endif
                        </div>
                        <div class="sign-name">{{ $cosigner->name }}</div>
                        <div class="sign-position">{{ $cosigner->title }}</div>
                    </div>
                </td>
            </tr>
        </table>
    @else
        <div class="sign-block sign-block-right">
            <div class="sign-location">
                {{ explode(',', $kop_address)[0] }}, {{ $letter->letter_date?->isoFormat('D MMMM YYYY') }}
            </div>
            <div class="sign-title">{{ $signer?->title ?? 'Direktur Utama' }},</div>
            <div class="sign-title" style="margin-bottom:4px;">{{ $kop_company }}</div>
            <div class="sign-space">
                @if($signer_stamp_data_uri)
                    <img class="stamp-img" src="{{ $signer_stamp_data_uri }}" alt="stamp">
                @endif
                @if($signer_signature_data_uri)
                    <img class="ttd-img" src="{{ $signer_signature_data_uri }}" alt="ttd">
                @elseif($letter->status === 'signed')
                    <em style="color:#64748b;">[TTD digital]</em>
                @endif
            </div>
            <div class="sign-name">{{ $signer?->name ?? 'Muhammad Aldy Iyik Be' }}</div>
            <div class="sign-position">{{ $signer?->title ?? 'Direktur Utama' }} · {{ $signer?->email ?? 'aldy.iyik@ibereality.com' }}</div>
        </div>
    @endif
</div>

{{-- Tembusan / CC list - di bawah signoff block, kalau ada --}}
@if(!empty($letter->cc_list) && is_array($letter->cc_list))
    <div style="margin-top:18px; font-size:9.5px; color:#475569;">
        <em>Tembusan:</em>
        <ol style="margin:2px 0 0 18px; padding:0;">
            @foreach($letter->cc_list as $cc)
                <li>{{ $cc }}</li>
            @endforeach
        </ol>
    </div>
@endif

{{-- Watermark untuk surat non-SIGNED - semi-transparent miring di tengah --}}
@php
    $watermark = null;
    if ($letter->status === 'draft' || $letter->status === 'review')   $watermark = 'DRAFT';
    elseif ($letter->status === 'approved')                            $watermark = 'BELUM DI-TTD';
    elseif ($letter->status === 'cancelled')                           $watermark = 'BATAL';
@endphp
@if($watermark)
    <script type="text/php">
        if (isset($pdf)) {
            $w = $pdf->get_width();
            $h = $pdf->get_height();
            $text = "{!! $watermark !!}";
            $font = $fontMetrics->getFont("DejaVu Sans", "bold");
            $size = 110;
            $textWidth = $fontMetrics->getTextWidth($text, $font, $size);
            // rotation: -30 derajat, centered, opacity via warna abu pucat
            $color = [0.85, 0.55, 0.55]; // pinkish-red translucent appearance
            $pageCount = $pdf->get_page_count();
            for ($p = 1; $p <= $pageCount; $p++) {
                $pdf->page_text(
                    ($w - $textWidth * 0.6) / 2,
                    ($h / 2),
                    $text,
                    $font,
                    $size,
                    $color,
                    0, 0, -30
                );
            }
        }
    </script>
@endif

{{-- QR code untuk verifikasi publik - fixed bottom-right of every page --}}
@if(!empty($letter->qr_token))
    @php
        // Generate QR code SVG inline (data-URL agar dompdf bisa embed tanpa remote)
        // Pakai endroid/qr-code yang sudah include di Laravel default, atau fallback ke simple SVG.
        $verifyUrl = config('app.url', 'https://app-erp.ibereality.com') . '/surat-verify/' . $letter->qr_token;
        $qrDataUri = null;
        try {
            // Pakai endroid qr-code 5.x kalau tersedia
            if (class_exists(\Endroid\QrCode\QrCode::class)) {
                $qr = new \Endroid\QrCode\QrCode($verifyUrl);
                $qr->setSize(120);
                $qr->setMargin(0);
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $result = $writer->write($qr);
                $qrDataUri = 'data:image/png;base64,' . base64_encode($result->getString());
            }
        } catch (\Throwable) { /* fallback to no QR */ }
    @endphp
    @if($qrDataUri)
        <div style="position:fixed; bottom:14mm; left:22mm; width:34mm; text-align:left;">
            <img src="{{ $qrDataUri }}" alt="QR verifikasi" style="width:24mm; height:24mm;">
            <div style="font-size:6.5px; color:#64748b; margin-top:2px; line-height:1.2;">
                Scan untuk verifikasi keaslian<br>{{ $letter->letter_number ?? '-' }}
            </div>
        </div>
    @endif
@endif

{{-- Footer fixed di bawah setiap halaman - pakai position:fixed (dompdf
     auto-replicate ke setiap page) + dompdf PHP script untuk page_num /
     page_count agar nilai aktual ter-substitusi (bukan literal token). --}}
<div class="pdf-footer-fixed">
    <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
        <tr><td class="pdf-footer-band-top">&nbsp;</td></tr>
        <tr><td class="pdf-footer-band-bot">&nbsp;</td></tr>
    </table>
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="vertical-align:top;">
                <span style="font-weight:700; color:#0f172a;">{{ $kop_company_full }}</span>
                <span style="margin:0 4px; color:#cbd5e1;">·</span>
                <span style="color:#0d9488; font-weight:600;">{{ $kop_brand }}</span>
                <span style="margin:0 4px; color:#cbd5e1;">·</span>
                <span>{{ $kop_website }}</span>
            </td>
            <td style="width:160px; vertical-align:top; text-align:right;">
                @if($kop_confidential)
                    <span style="display:inline-block; padding:1px 5px; border:1px solid #dc2626; color:#dc2626; font-weight:900; letter-spacing:1px; font-size:7px; margin-right:6px;">CONFIDENTIAL</span>
                @endif
                {{-- Page number marker - diisi oleh script dompdf di bawah --}}
                <span style="visibility:hidden;">PAGE</span>
            </td>
        </tr>
    </table>
</div>

{{-- dompdf script untuk render page X dari Y. Dipanggil setelah layout
     selesai sehingga page_count akurat. --}}
<script type="text/php">
if (isset($pdf)) {
    $text = "Halaman {PAGE_NUM} dari {PAGE_COUNT}";
    $size = 8;
    $font = $fontMetrics->getFont("DejaVu Sans");
    $width = $fontMetrics->getTextWidth($text, $font, $size);
    $pageWidth = $pdf->get_width();
    $pageHeight = $pdf->get_height();
    // Posisi: kanan-bawah, 22mm (≈62pt) dari kanan, 14mm (≈40pt) dari bawah
    $x = $pageWidth - $width - 62;
    $y = $pageHeight - 40;
    $pdf->page_text($x, $y, $text, $font, $size, [0.39, 0.45, 0.55]); // slate-500
}
</script>
</body>
</html>

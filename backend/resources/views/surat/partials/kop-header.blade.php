{{--
    KOP SURAT HEADER - PT Integrasi Bisnis Eksekutif (IBE Reality)
    Pure HTML + inline CSS untuk dompdf compatibility.
    Dipanggil oleh layout PDF utama via @include.
    Variables yang bisa di-override (opsional):
      $kop_logo_url    - path logo (default: text "IBE")
      $kop_company     - nama PT (default constant)
      $kop_brand       - brand name (default "IBE Reality")
      $kop_tagline     - tagline (default "Integrated Business Excellence")
      $kop_address     - alamat (default Surabaya)
      $kop_phone       - telepon
      $kop_email       - email umum
      $kop_website     - website
--}}
@php
    $kop_company  = $kop_company  ?? 'PT INTEGRASI BISNIS EKSEKUTIF';
    $kop_brand    = $kop_brand    ?? 'IBE REALITY';
    $kop_tagline  = $kop_tagline  ?? 'Software · System Integration · Digital Solutions';
    $kop_address  = $kop_address  ?? 'Surabaya, Jawa Timur, Indonesia';
    $kop_phone    = $kop_phone    ?? '+62 813-3005-8541';
    $kop_email    = $kop_email    ?? 'info@ibereality.com';
    $kop_website  = $kop_website  ?? 'www.ibereality.com';
    $kop_logo_url = $kop_logo_url ?? null;
@endphp
<table style="width:100%; border-collapse:collapse; margin:0; padding:0; font-family:'DejaVu Sans', Helvetica, Arial, sans-serif;">
    <tr>
        {{-- LOGO COLUMN --}}
        <td style="width:90px; vertical-align:middle; padding:0 14px 0 0;">
            @if($kop_logo_url)
                <img src="{{ $kop_logo_url }}" alt="IBE" style="width:80px; height:80px; object-fit:contain;">
            @else
                {{-- Fallback: stylized letter mark IBE dalam square hijau teal --}}
                <div style="width:78px; height:78px; background:#0d9488; border-radius:14px; text-align:center; line-height:78px; color:#ffffff; font-weight:900; font-size:30px; letter-spacing:1px;">
                    IBE
                </div>
            @endif
        </td>

        {{-- COMPANY NAME COLUMN --}}
        <td style="vertical-align:middle; padding:0;">
            <div style="font-size:18px; font-weight:900; color:#0f172a; line-height:1.1; letter-spacing:0.4px;">
                {{ $kop_company }}
            </div>
            <div style="font-size:13px; font-weight:700; color:#0d9488; margin-top:2px; letter-spacing:1.2px;">
                {{ $kop_brand }}
            </div>
            <div style="font-size:9px; color:#64748b; margin-top:2px; font-style:italic;">
                {{ $kop_tagline }}
            </div>
        </td>

        {{-- CONTACT COLUMN - right aligned --}}
        <td style="width:230px; vertical-align:middle; padding:0; text-align:right; font-size:9px; color:#475569; line-height:1.4;">
            <div style="margin-bottom:2px;">
                <span style="color:#0d9488;">📍</span>
                {{ $kop_address }}
            </div>
            <div style="margin-bottom:2px;">
                <span style="color:#0d9488;">✉</span>
                {{ $kop_email }}
                &nbsp;&middot;&nbsp;
                <span style="color:#0d9488;">☎</span>
                {{ $kop_phone }}
            </div>
            <div>
                <span style="color:#0d9488;">🌐</span>
                {{ $kop_website }}
            </div>
        </td>
    </tr>
</table>
{{-- Color band separator - teal gradient via 2 layer trick di dompdf --}}
<table style="width:100%; border-collapse:collapse; margin-top:10px;">
    <tr>
        <td style="height:4px; background:#0d9488; padding:0; line-height:4px;">&nbsp;</td>
    </tr>
    <tr>
        <td style="height:1px; background:#f0fdfa; padding:0; line-height:1px;">&nbsp;</td>
    </tr>
</table>

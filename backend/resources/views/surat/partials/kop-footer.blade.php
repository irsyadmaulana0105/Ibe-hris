{{--
    KOP SURAT FOOTER - PT Integrasi Bisnis Eksekutif (IBE Reality)

    Default footer minimal: company name + halaman + small tagline.
    Bank info, T&C, contact details, dll bukan di sini - itu konteks-specific
    yang di-render INSIDE body surat (misal Penawaran punya block "Rekening
    Bank Mandiri ..." sebagai bagian dari Terms & Conditions).

    Variables:
      $kop_company_full - nama lengkap PT
      $kop_brand        - brand name short
      $kop_website      - website
      $kop_confidential - bool, toggle red CONFIDENTIAL badge
--}}
@php
    $kop_company_full = $kop_company_full ?? 'PT Integrasi Bisnis Eksekutif';
    $kop_brand        = $kop_brand        ?? 'IBE Reality';
    $kop_website      = $kop_website      ?? 'www.ibereality.com';
    $kop_confidential = $kop_confidential ?? false;
@endphp
{{-- Color band sebelum footer text --}}
<table style="width:100%; border-collapse:collapse; margin-top:20px;">
    <tr>
        <td style="height:1px; background:#f0fdfa; padding:0; line-height:1px;">&nbsp;</td>
    </tr>
    <tr>
        <td style="height:3px; background:#0d9488; padding:0; line-height:3px;">&nbsp;</td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; margin-top:6px; font-family:'DejaVu Sans', Helvetica, Arial, sans-serif; font-size:8px; color:#64748b;">
    <tr>
        <td style="vertical-align:top; padding:0;">
            <span style="font-weight:700; color:#0f172a;">{{ $kop_company_full }}</span>
            <span style="margin:0 4px; color:#cbd5e1;">·</span>
            <span style="color:#0d9488; font-weight:600;">{{ $kop_brand }}</span>
            <span style="margin:0 4px; color:#cbd5e1;">·</span>
            <span>{{ $kop_website }}</span>
        </td>
        <td style="width:130px; vertical-align:top; padding:0; text-align:right;">
            @if($kop_confidential)
                <span style="display:inline-block; padding:1px 5px; border:1px solid #dc2626; color:#dc2626; font-weight:900; letter-spacing:1px; font-size:7px; margin-right:6px;">CONFIDENTIAL</span>
            @endif
            <span>Halaman {PAGE_NUM} dari {PAGE_COUNT}</span>
        </td>
    </tr>
</table>

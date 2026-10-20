<?php

/**
 * HTMLPurifier config - dipakai HtmlSanitizerService untuk body surat
 * + template. Profile `letter-body` matches allowlist lama (tags + attrs).
 *
 * Default profile (key 'default') tetap conservative untuk pemakaian umum.
 */
return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0700,

    'settings' => [
        'default' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'div,b,strong,i,em,u,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src]',
            'CSS.AllowedProperties' => 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align',
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => false,
        ],

        // Profile khusus body surat - tag set ≈ sama dengan implementasi
        // regex sebelumnya (heading + table + blockquote + code), tapi DOM-parse.
        // URI scheme dibatasi http/https/mailto/tel agar javascript:/data:/file:
        // otomatis ditolak HTMLPurifier.
        'letter-body' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => implode(',', [
                'p[class|style]',
                'br',
                'strong', 'em', 'b', 'i', 'u', 's',
                'span[class|style]',
                'div[class|style]',
                'h1[class|style]', 'h2[class|style]', 'h3[class|style]',
                'h4[class|style]', 'h5[class|style]', 'h6[class|style]',
                'ul[class|style]', 'ol[class|style]', 'li[class|style]',
                'table[class|style|border|cellpadding|cellspacing]',
                'thead', 'tbody',
                'tr[class|style]',
                'td[class|style|colspan|rowspan|align|valign]',
                'th[class|style|colspan|rowspan|align|valign]',
                'blockquote[class|style|cite]',
                'code', 'pre',
                'a[href|target|rel|class|style]',
                'hr[class|style]',
            ]),
            'CSS.AllowedProperties' => implode(',', [
                'font', 'font-size', 'font-weight', 'font-style', 'font-family',
                'text-decoration', 'text-align', 'text-indent',
                'color', 'background-color',
                'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom',
                'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
                'border', 'border-left', 'border-right', 'border-top', 'border-bottom',
                'border-color', 'border-style', 'border-width',
                'width', 'height', 'min-width', 'min-height',
                'list-style-type', 'vertical-align',
            ]),
            'URI.AllowedSchemes' => [
                'http' => true, 'https' => true, 'mailto' => true, 'tel' => true,
            ],
            // Editable letter bodies must never trigger resource fetches.
            // Company logos/signatures are rendered separately from verified
            // private assets, while links remain explicit user navigation.
            'URI.DisableExternalResources' => true,
            'URI.DisableResources' => true,
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => false,
            'Attr.AllowedFrameTargets' => ['_blank'],
            'HTML.TargetBlank' => true,
        ],

        // Contract templates are rendered in a privileged admin UI and into
        // PDFs. Keep structural rich text, tables, and bounded inline layout,
        // but prohibit every active/embedded resource. Company logos,
        // signatures, and stamps are injected separately from private assets.
        'contract-template' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => implode(',', [
                'p[class|style]',
                'br',
                'strong', 'em', 'b', 'i', 'u', 's',
                'span[class|style]',
                'div[class|style]',
                'h1[class|style]', 'h2[class|style]', 'h3[class|style]',
                'h4[class|style]', 'h5[class|style]', 'h6[class|style]',
                'ul[class|style]', 'ol[class|style]', 'li[class|style]',
                'table[class|style|border|cellpadding|cellspacing]',
                'thead', 'tbody',
                'tr[class|style]',
                'td[class|style|colspan|rowspan|align|valign]',
                'th[class|style|colspan|rowspan|align|valign]',
                'blockquote[class|style|cite]',
                'code', 'pre',
                'a[href|rel|class|style]',
                'hr[class|style]',
            ]),
            'CSS.AllowedProperties' => implode(',', [
                'font', 'font-size', 'font-weight', 'font-style', 'font-family',
                'text-decoration', 'text-align', 'text-indent',
                'color', 'background-color',
                'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom',
                'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
                'border', 'border-left', 'border-right', 'border-top', 'border-bottom',
                'border-color', 'border-style', 'border-width',
                'width', 'height', 'min-width', 'min-height',
                'list-style-type', 'vertical-align',
            ]),
            'URI.AllowedSchemes' => [
                'http' => true, 'https' => true, 'mailto' => true, 'tel' => true,
            ],
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => false,
        ],
    ],
];

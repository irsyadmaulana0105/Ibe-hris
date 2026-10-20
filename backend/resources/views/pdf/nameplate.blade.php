<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Nameplate {{ $sn->serial_number }}</title>
    <style>
        @page { margin: 8mm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            color: #111;
            background: #fff;
            margin: 0;
        }
        .plate {
            border: 2px solid #111;
            padding: 12px 16px;
            background: linear-gradient(180deg, #f5f5f5 0%, #fff 30%);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1.5px solid #111;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .header .brand {
            font-weight: 800;
            font-size: 13pt;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .header .product-name {
            font-weight: 700;
            font-size: 11pt;
        }
        .sn-block {
            background: #111;
            color: #fff;
            padding: 8px 14px;
            margin: 6px 0 10px 0;
            text-align: center;
        }
        .sn-block .label {
            font-size: 8pt;
            letter-spacing: .15em;
            opacity: .7;
        }
        .sn-block .value {
            font-family: 'DejaVu Sans Mono', monospace;
            font-weight: 700;
            font-size: 18pt;
            letter-spacing: .08em;
            margin-top: 2px;
        }
        table.spec {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        table.spec td {
            border: 1px solid #888;
            padding: 4px 8px;
            vertical-align: middle;
        }
        table.spec td.label {
            background: #eee;
            font-weight: 700;
            font-size: 8pt;
            letter-spacing: .04em;
            text-transform: uppercase;
            width: 32%;
        }
        table.spec td.value {
            font-weight: 600;
        }
        .warning {
            background: #f59e0b;
            color: #111;
            padding: 5px 10px;
            margin-top: 6px;
            font-size: 8pt;
            font-weight: 700;
            border: 1px solid #b45309;
        }
        .footer {
            margin-top: 8px;
            font-size: 7.5pt;
            color: #555;
            text-align: right;
        }
    </style>
</head>
<body>
<div class="plate">
    <div class="header">
        <div class="brand">{{ optional($company)->name ?? 'IBE Reality' }}</div>
        <div class="product-name">
            {{ $product?->name ?? $sn->product_name }}
            @if ($product?->model_number)
                - {{ $product->model_number }}
            @endif
        </div>
    </div>

    <div class="sn-block">
        <div class="label">SERIAL NO.</div>
        <div class="value">{{ $sn->serial_number }}</div>
    </div>

    @if (count($fields) > 0)
        <table class="spec">
            @php
                $rows = array_chunk($fields, 2);
            @endphp
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $f)
                        <td class="label">{{ $f['label'] }}</td>
                        <td class="value">
                            {{ $f['value'] }}
                            @if (!empty($f['unit'])) <span style="opacity:.7"> {{ $f['unit'] }}</span> @endif
                        </td>
                    @endforeach
                    @if (count($row) === 1)
                        <td colspan="2"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    @if ($sn->manufactured_at || $sn->warranty_until)
        <table class="spec">
            <tr>
                @if ($sn->manufactured_at)
                    <td class="label">Mfg. Date</td>
                    <td class="value">{{ $sn->manufactured_at->format('d M Y') }}</td>
                @endif
                @if ($sn->warranty_until)
                    <td class="label">Warranty Until</td>
                    <td class="value">{{ $sn->warranty_until->format('d M Y') }}</td>
                @endif
            </tr>
        </table>
    @endif

    <div class="warning">
        ⚠ IMPROPER OPERATION OR MAINTENANCE COULD RESULT IN INJURY OR DEATH.
        TRAINED OPERATORS ONLY. READ OPERATOR'S MANUAL FIRST.
    </div>

    <div class="footer">
        Generated {{ now()->format('d M Y H:i') }} · Verify @ ibereality.com/verify/{{ $sn->serial_number }}
    </div>
</div>
</body>
</html>

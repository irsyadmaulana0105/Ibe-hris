<?php

namespace App\Surat\Services;

use App\Surat\Models\CompanyBank;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterTemplate;
use Carbon\Carbon;

/**
 * Render template body dengan substitusi placeholder.
 *
 * Placeholder syntax di template body_html: {{path.to.value}}
 * Resolver:
 *   {{letter.number}}      → Letter::letter_number
 *   {{letter.date}}        → formatted DD Month YYYY
 *   {{letter.expiry}}      → formatted DD Month YYYY (or - if null)
 *   {{recipient.name}}     → Letter::recipient_name
 *   {{recipient.company}}  → ...
 *   {{recipient.address}}  → ...
 *   {{author.name}}        → User
 *   {{signer.name}}        → LetterSignature
 *   {{signer.title}}       → ...
 *   {{purpose}}            → ...
 *   {{custom.X}}           → from body_data JSON
 */
class LetterTemplateService
{
    private static array $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public function __construct(private readonly HtmlSanitizerService $sanitizer) {}

    /**
     * Render template terhadap data surat. Return body_html final.
     */
    public function render(LetterTemplate $template, Letter $letter): string
    {
        $resolver = $this->buildResolver($letter);

        return $this->substitute($template->body_html, $resolver);
    }

    /**
     * Render body_html arbitrary (e.g., setelah diedit user) dengan
     * placeholders dari letter. Digunakan saat surat udah punya custom body
     * yang di-tweak author.
     */
    public function renderBody(string $bodyHtml, Letter $letter): string
    {
        $resolver = $this->buildResolver($letter);

        return $this->substitute($bodyHtml, $resolver);
    }

    private function substitute(string $html, array $resolver): string
    {
        // Sanitize before substitution so placeholders cannot survive inside
        // prohibited elements/attributes. Sanitize once more afterward to
        // enforce the same policy on the final rendered document.
        $html = $this->sanitizer->clean($html);
        $trustedHtml = [
            'products.table' => $this->sanitizer->clean((string) ($resolver['products.table'] ?? '')),
        ];

        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($resolver, $trustedHtml) {
            $key = $m[1];

            if (array_key_exists($key, $trustedHtml)) {
                return $trustedHtml[$key];
            }

            if (! array_key_exists($key, $resolver)) {
                return "{{ {$key} }}";
            }

            return htmlspecialchars(
                (string) $resolver[$key],
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            );
        }, $html);

        return $this->sanitizer->clean($rendered ?? '');
    }

    private function buildResolver(Letter $letter): array
    {
        $date = $letter->letter_date ? $this->formatDate($letter->letter_date) : '';
        $expiry = $letter->expiry_date ? $this->formatDate($letter->expiry_date) : '-';

        // Bank info - pakai bank yang dipilih untuk surat ini, fallback ke
        // default company bank kalau tidak set. Format string lengkap.
        $bank = $letter->bank;
        if (! $bank && $letter->company_id) {
            $bank = CompanyBank::where('company_id', $letter->company_id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
        }
        $bankString = $bank
            ? $bank->asResolvedString()
            : 'Belum dikonfigurasi';

        $resolver = [
            'letter.number' => $letter->letter_number ?? '⟨ otomatis ⟩',
            'letter.subject' => $letter->subject,
            'letter.purpose' => $letter->purpose ?? '',
            'letter.date' => $date,
            'letter.expiry' => $expiry,
            'recipient.name' => $letter->recipient_name ?? '⟨ Nama Penerima ⟩',
            'recipient.title' => $letter->recipient_title ?? '',
            'recipient.company' => $letter->recipient_company ?? '',
            'recipient.address' => $letter->recipient_address ?? '',
            'recipient.email' => $letter->recipient_email ?? '',
            'author.name' => $letter->author?->name ?? '',
            'author.email' => $letter->author?->email ?? '',
            'signer.name' => $letter->signer?->name ?? 'Muhammad Aldy Iyik Be',
            'signer.title' => $letter->signer?->title ?? 'Direktur Utama',
            'signer.email' => $letter->signer?->email ?? 'aldy.iyik@ibereality.com',
            'cosigner.name' => $letter->cosigner?->name ?? '',
            'cosigner.title' => $letter->cosigner?->title ?? '',
            'company.name' => 'PT Integrasi Bisnis Eksekutif',
            'company.brand' => 'IBE Reality',
            'company.address' => 'Sidoarjo, Jawa Timur, Indonesia',
            'company.phone' => '+62 813-3005-8541',
            'company.email' => 'admin@ibereality.com',
            'company.website' => 'www.ibereality.com',
            'company.bank' => $bankString,
            'company.bank.name' => $bank?->bank_name ?? 'Belum dikonfigurasi',
            'company.bank.no' => $bank?->account_number ?? 'Belum dikonfigurasi',
            'company.bank.an' => $bank?->account_name ?? 'Belum dikonfigurasi',
        ];

        // Products auto-rendered table - dipakai di body Penawaran/Invoice
        // Source: letter.body_data.products (array of items)
        // Auto-calc subtotal + PPN + grand total.
        $products = $letter->body_data['products'] ?? [];
        $taxRate = (float) ($letter->body_data['tax_rate'] ?? 0); // 0.11 = PPN 11%, 0 = no tax
        $showPpn = (bool) ($letter->body_data['show_ppn'] ?? false);
        $resolver['products.table'] = $this->renderProductsTable($products, $taxRate, $showPpn);
        $resolver['products.subtotal'] = 'Rp '.number_format($this->subtotal($products), 0, ',', '.');
        if ($showPpn) {
            $resolver['products.ppn'] = 'Rp '.number_format($this->subtotal($products) * $taxRate, 0, ',', '.');
            $resolver['products.total'] = 'Rp '.number_format($this->subtotal($products) * (1 + $taxRate), 0, ',', '.');
        } else {
            $resolver['products.ppn'] = '-';
            $resolver['products.total'] = 'Rp '.number_format($this->subtotal($products), 0, ',', '.');
        }

        // Custom data from body_data
        foreach ((array) ($letter->body_data ?? []) as $key => $val) {
            if (is_scalar($val)) {
                $resolver["custom.{$key}"] = (string) $val;
            }
        }

        return $resolver;
    }

    /** Sum row totals - handle qty x unit_price or pre-calc total. */
    private function subtotal(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }
            if (isset($it['total']) && is_numeric($it['total'])) {
                $sum += (float) $it['total'];
            } else {
                $qty = (float) ($it['qty'] ?? 0);
                $price = (float) ($it['unit_price'] ?? 0);
                $sum += $qty * $price;
            }
        }

        return $sum;
    }

    /**
     * Render products array sebagai HTML table siap embed di body PDF.
     * Format: No · Produk · Deskripsi · Qty · Unit Price · Total
     * Footer: Subtotal · (opsional PPN) · TOTAL.
     */
    private function renderProductsTable(array $items, float $taxRate = 0, bool $showPpn = false): string
    {
        if (empty($items)) {
            return '<p style="color:#94a3b8;font-style:italic;">⟨ Belum ada item - tambahkan di panel "Items Penawaran" di atas editor ⟩</p>';
        }

        $rows = '';
        $subtotal = 0.0;
        $i = 1;
        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }
            $name = htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $desc = htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $qty = (float) ($it['qty'] ?? 0);
            $unit = htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $price = (float) ($it['unit_price'] ?? 0);
            $total = isset($it['total']) && is_numeric($it['total'])
                ? (float) $it['total']
                : $qty * $price;
            $subtotal += $total;

            $rows .= sprintf(
                '<tr><td style="text-align:center;">%d</td><td>%s</td><td>%s</td><td style="text-align:center;">%s %s</td><td style="text-align:right;font-variant-numeric:tabular-nums;">Rp %s</td><td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">Rp %s</td></tr>',
                $i++,
                $name,
                $desc,
                rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ','),
                $unit,
                number_format($price, 0, ',', '.'),
                number_format($total, 0, ',', '.'),
            );
        }

        $footerRows = sprintf(
            '<tr><td colspan="5" style="text-align:right;font-weight:600;background:#f8fafc;">Subtotal</td><td style="text-align:right;font-variant-numeric:tabular-nums;background:#f8fafc;">Rp %s</td></tr>',
            number_format($subtotal, 0, ',', '.'),
        );

        if ($showPpn && $taxRate > 0) {
            $ppn = $subtotal * $taxRate;
            $total = $subtotal + $ppn;
            $footerRows .= sprintf(
                '<tr><td colspan="5" style="text-align:right;background:#f8fafc;">PPN %s%%</td><td style="text-align:right;font-variant-numeric:tabular-nums;background:#f8fafc;">Rp %s</td></tr>',
                number_format($taxRate * 100, 0, ',', '.'),
                number_format($ppn, 0, ',', '.'),
            );
        } else {
            $total = $subtotal;
        }

        $footerRows .= sprintf(
            '<tr style="background:#f0fdfa;"><td colspan="5" style="text-align:right;font-weight:800;color:#0f172a;">TOTAL</td><td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:#0d9488;">Rp %s</td></tr>',
            number_format($total, 0, ',', '.'),
        );

        return <<<HTML
<table style="width:100%;border-collapse:collapse;margin:10px 0;font-size:10px;">
    <thead>
        <tr style="background:#f1f5f9;">
            <th style="border:1px solid #cbd5e1;padding:8px 6px;width:36px;">No</th>
            <th style="border:1px solid #cbd5e1;padding:8px 6px;">Produk</th>
            <th style="border:1px solid #cbd5e1;padding:8px 6px;">Deskripsi</th>
            <th style="border:1px solid #cbd5e1;padding:8px 6px;width:80px;">Qty</th>
            <th style="border:1px solid #cbd5e1;padding:8px 6px;width:110px;">Unit Price</th>
            <th style="border:1px solid #cbd5e1;padding:8px 6px;width:130px;">Total</th>
        </tr>
    </thead>
    <tbody>
        {$rows}
        {$footerRows}
    </tbody>
</table>
HTML;
    }

    private function formatDate($d): string
    {
        $c = $d instanceof \DateTimeInterface ? Carbon::instance($d) : Carbon::parse($d);

        return $c->day.' '.(self::$months[(int) $c->month] ?? '').' '.$c->year;
    }
}

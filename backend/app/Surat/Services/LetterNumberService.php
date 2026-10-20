<?php

namespace App\Surat\Services;

use App\Surat\Models\Letter;
use App\Surat\Models\LetterCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generator nomor surat - atomic, no race condition.
 *
 * Sequence reset per tahun. Saat APPROVED, increment last_seq_value di
 * LetterCategory via lockForUpdate untuk concurrent safety.
 *
 * Format placeholder yang di-support:
 *   {seq}         → 1, 2, 3 (no padding)
 *   {seq:03d}     → 001, 002, ... (zero-padded ke N digits)
 *   {code}        → category.code (PEN, ST, dll)
 *   {tujuan}      → A (Internal) atau B (Eksternal), kalau ada
 *   {purpose}     → Letter.purpose, slugified pakai dash (Talkshow-Bisnis)
 *   {ROMAN_MONTH} → I, II, III, ... XII
 *   {month}       → 01, 02, ...
 *   {year}        → 2025
 *   {YY}          → 25
 */
class LetterNumberService
{
    /**
     * Generate next number untuk surat. Side effect: increment counter
     * di category. Caller harus pastikan letter.status sedang transition
     * ke APPROVED dan belum punya nomor.
     *
     * Return: tuple [letter_number, seq_value, year]
     */
    public function generate(Letter $letter): array
    {
        return DB::transaction(function () use ($letter) {
            /** @var LetterCategory $cat */
            $cat = LetterCategory::query()
                ->where('id', $letter->category_id)
                ->lockForUpdate()
                ->first();

            if (!$cat) {
                throw new RuntimeException("Category id {$letter->category_id} tidak ditemukan");
            }

            $year = (int) Carbon::parse($letter->letter_date)->format('Y');

            // Reset sequence kalau ganti tahun
            if ((int) $cat->last_seq_year !== $year) {
                $cat->last_seq_year  = $year;
                $cat->last_seq_value = 0;
            }

            $cat->last_seq_value++;
            $cat->save();

            $seq = $cat->last_seq_value;
            $number = $this->format($cat->numbering_format, [
                'seq'         => $seq,
                'code'        => $cat->code,
                'tujuan'      => $letter->target_code ?: 'A',
                'purpose'     => $this->slugify($letter->purpose ?? ''),
                'ROMAN_MONTH' => $this->roman((int) Carbon::parse($letter->letter_date)->format('m')),
                'month'       => str_pad((string) Carbon::parse($letter->letter_date)->format('m'), 2, '0', STR_PAD_LEFT),
                'year'        => (string) $year,
                'YY'          => substr((string) $year, -2),
            ]);

            return [$number, $seq, $year];
        });
    }

    /**
     * Render format string dengan placeholder values. Support {key} dan
     * {key:Nd} untuk zero-padding (mirror Python format spec).
     */
    private function format(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)(?::(\d+)d)?\}/', function ($m) use ($vars) {
            $key = $m[1];
            $pad = isset($m[2]) ? (int) $m[2] : 0;
            $val = $vars[$key] ?? '';
            if ($pad > 0 && is_numeric($val)) {
                return str_pad((string) (int) $val, $pad, '0', STR_PAD_LEFT);
            }
            return (string) $val;
        }, $tpl);
    }

    private function roman(int $m): string
    {
        return ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'][$m - 1] ?? '';
    }

    private function slugify(string $s): string
    {
        if ($s === '') return '';
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    /**
     * Preview format tanpa increment counter (untuk UI). Pakai next-likely
     * sequence (current+1) untuk display.
     */
    public function preview(LetterCategory $cat, Carbon $date, ?string $purpose = null, ?string $tujuan = null): string
    {
        $year = (int) $date->format('Y');
        $nextSeq = ((int) $cat->last_seq_year === $year)
            ? ($cat->last_seq_value + 1)
            : 1;

        return $this->format($cat->numbering_format, [
            'seq'         => $nextSeq,
            'code'        => $cat->code,
            'tujuan'      => $tujuan ?: 'A',
            'purpose'     => $this->slugify($purpose ?? ''),
            'ROMAN_MONTH' => $this->roman((int) $date->format('m')),
            'month'       => str_pad((string) $date->format('m'), 2, '0', STR_PAD_LEFT),
            'year'        => (string) $year,
            'YY'          => substr((string) $year, -2),
        ]);
    }
}

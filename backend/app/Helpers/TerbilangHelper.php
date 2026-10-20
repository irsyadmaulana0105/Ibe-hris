<?php

namespace App\Helpers;

/**
 * Convert angka ke kata-kata dalam Bahasa Indonesia.
 * Contoh: 1500000 → "Satu Juta Lima Ratus Ribu"
 */
class TerbilangHelper
{
    private static array $angka = [
        '', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima',
        'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas',
    ];

    public static function convert(float|int|string|null $n): string
    {
        if ($n === null) return '';
        $n = (float) $n;
        $abs = abs($n);
        $intPart = (int) floor($abs);
        $result = trim(self::recurse($intPart));
        return $result ?: 'Nol';
    }

    private static function recurse(int $n): string
    {
        if ($n < 12) {
            return self::$angka[$n];
        }
        if ($n < 20) {
            return self::$angka[$n - 10] . ' Belas';
        }
        if ($n < 100) {
            return self::$angka[(int) ($n / 10)] . ' Puluh ' . self::$angka[$n % 10];
        }
        if ($n < 200) {
            return 'Seratus ' . self::recurse($n - 100);
        }
        if ($n < 1000) {
            return self::$angka[(int) ($n / 100)] . ' Ratus ' . self::recurse($n % 100);
        }
        if ($n < 2000) {
            return 'Seribu ' . self::recurse($n - 1000);
        }
        if ($n < 1_000_000) {
            return self::recurse((int) ($n / 1000)) . ' Ribu ' . self::recurse($n % 1000);
        }
        if ($n < 1_000_000_000) {
            return self::recurse((int) ($n / 1_000_000)) . ' Juta ' . self::recurse($n % 1_000_000);
        }
        if ($n < 1_000_000_000_000) {
            return self::recurse((int) ($n / 1_000_000_000)) . ' Miliar ' . self::recurse($n % 1_000_000_000);
        }
        return self::recurse((int) ($n / 1_000_000_000_000)) . ' Triliun ' . self::recurse($n % 1_000_000_000_000);
    }
}

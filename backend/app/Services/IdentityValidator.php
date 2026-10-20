<?php

namespace App\Services;

/**
 * Validator untuk identitas Indonesia: NIK (KTP) dan NPWP. Pure algorithmic,
 * tidak butuh external API (offline).
 *
 * NIK 16 digit:
 *   - Pos 1-2: kode provinsi (BPS)
 *   - Pos 3-4: kode kab/kota
 *   - Pos 5-6: kode kecamatan
 *   - Pos 7-12: tanggal lahir (DDMMYY).
 *       Female: DD + 40 (jadi 41-71 untuk Januari-Maret female, dll)
 *   - Pos 13-16: sequence
 *
 * NPWP format lama 15 digit (9.X-X.X-XXX.XXX-X.X.X) atau baru 16 digit
 * (NIK-based, Coretax 2024+).
 */
class IdentityValidator
{
    private const PROVINCE_CODES = [
        '11' => 'Aceh', '12' => 'Sumatera Utara', '13' => 'Sumatera Barat', '14' => 'Riau',
        '15' => 'Jambi', '16' => 'Sumatera Selatan', '17' => 'Bengkulu', '18' => 'Lampung',
        '19' => 'Kep. Bangka Belitung', '21' => 'Kep. Riau', '31' => 'DKI Jakarta',
        '32' => 'Jawa Barat', '33' => 'Jawa Tengah', '34' => 'DI Yogyakarta', '35' => 'Jawa Timur',
        '36' => 'Banten', '51' => 'Bali', '52' => 'NTB', '53' => 'NTT',
        '61' => 'Kalimantan Barat', '62' => 'Kalimantan Tengah', '63' => 'Kalimantan Selatan',
        '64' => 'Kalimantan Timur', '65' => 'Kalimantan Utara', '71' => 'Sulawesi Utara',
        '72' => 'Sulawesi Tengah', '73' => 'Sulawesi Selatan', '74' => 'Sulawesi Tenggara',
        '75' => 'Gorontalo', '76' => 'Sulawesi Barat', '81' => 'Maluku', '82' => 'Maluku Utara',
        '91' => 'Papua Barat', '92' => 'Papua Barat Daya', '94' => 'Papua',
        '95' => 'Papua Selatan', '96' => 'Papua Tengah', '97' => 'Papua Pegunungan',
    ];

    /**
     * Validate NIK + extract metadata.
     *
     * @return array{ valid: bool, errors: string[], province?: string, birth_date?: string, gender?: string }
     */
    public function validateNik(string $nik): array
    {
        $nik = preg_replace('/\s+/', '', $nik);
        $errors = [];

        if (!preg_match('/^[0-9]{16}$/', $nik)) {
            $errors[] = 'NIK harus 16 digit angka.';
            return ['valid' => false, 'errors' => $errors];
        }

        $provinceCode = substr($nik, 0, 2);
        $province = self::PROVINCE_CODES[$provinceCode] ?? null;
        if (!$province) {
            $errors[] = "Kode provinsi {$provinceCode} tidak dikenal (BPS).";
        }

        // Tanggal lahir di pos 7-12 (DDMMYY)
        $rawDay = (int) substr($nik, 6, 2);
        $month = (int) substr($nik, 8, 2);
        $year2 = (int) substr($nik, 10, 2);

        $gender = $rawDay > 40 ? 'female' : 'male';
        $day = $rawDay > 40 ? $rawDay - 40 : $rawDay;

        if ($month < 1 || $month > 12) {
            $errors[] = "Bulan lahir tidak valid (pos 9-10 NIK: {$month}).";
        }
        if ($day < 1 || $day > 31) {
            $errors[] = "Tanggal lahir tidak valid (pos 7-8 NIK: {$rawDay}).";
        }

        // Tebak abad: jika year2 lebih besar dari (tahun sekarang -2000) berarti abad 1900
        $currentYear2 = (int) date('y');
        $fullYear = $year2 <= $currentYear2 ? 2000 + $year2 : 1900 + $year2;
        $birthDate = sprintf('%04d-%02d-%02d', $fullYear, $month, $day);

        // Validate sebagai date asli
        if (!checkdate($month, $day, $fullYear)) {
            $errors[] = "Tanggal lahir {$birthDate} tidak valid (cek tahun kabisat / hari terakhir bulan).";
        }

        return [
            'valid'         => empty($errors),
            'errors'        => $errors,
            'province_code' => $provinceCode,
            'province'      => $province,
            'birth_date'    => $birthDate,
            'gender'        => $gender,
        ];
    }

    /**
     * Validate NPWP. Accepts 15 digit (lama) or 16 digit (NIK-based, baru 2024+).
     * Returns formatted version.
     */
    public function validateNpwp(string $npwp): array
    {
        $digits = preg_replace('/\D/', '', $npwp);
        $errors = [];

        if (!preg_match('/^[0-9]{15,16}$/', $digits)) {
            $errors[] = 'NPWP harus 15 digit (format lama) atau 16 digit (NIK-based Coretax).';
            return ['valid' => false, 'errors' => $errors];
        }

        $formatted = '';
        if (strlen($digits) === 15) {
            // 99.999.999.9-999.999
            $formatted = substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3)
                       . '.' . substr($digits, 8, 1) . '-' . substr($digits, 9, 3) . '.' . substr($digits, 12, 3);
        } else {
            // 16 digit = NIK-based, format = full NIK
            $formatted = $digits;
            // Pakai NIK validator sebagai sub-check
            $nikCheck = $this->validateNik($digits);
            if (!$nikCheck['valid']) {
                $errors = array_merge($errors, $nikCheck['errors']);
            }
        }

        return [
            'valid'     => empty($errors),
            'errors'    => $errors,
            'raw'       => $digits,
            'formatted' => $formatted,
            'type'      => strlen($digits) === 15 ? 'legacy_15' : 'coretax_16',
        ];
    }
}

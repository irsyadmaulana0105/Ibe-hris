<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads spreadsheet contents into $rows using row 1 as headings (snake_case normalized).
 * Kalau file punya multiple sheets (misal template kita punya sheet Petunjuk),
 * kita cuma ambil sheet pertama saja — sheet berikutnya diabaikan agar tidak overwrite.
 */
class HeadingRowImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    private bool $captured = false;

    public function collection(Collection $rows): void
    {
        if ($this->captured) {
            return;
        }
        $this->rows = $rows;
        $this->captured = true;
    }
}

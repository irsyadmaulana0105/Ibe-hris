<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeWriting;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Multi-sheet professional Excel export.
 *  - Sheet 1 "Tasks"/"RAB": row 1 = column headings (styled teal + white bold), row 2+ = data.
 *    Kompatibel dgn HeadingRowImport (WithHeadingRow default membaca row 1).
 *  - Sheet 2 "Petunjuk": metadata perusahaan/projek, cara pakai, deskripsi tiap kolom, legend enum.
 *  - Currency IDR + date formatting per kolom (kind-aware).
 *  - Zebra stripes, freeze pane, autofilter, print landscape fit-to-width, header/footer print.
 *  - Formula injection guard (bindValue).
 *  - Metadata juga disimpan di workbook document properties.
 */
class ProfessionalSpreadsheetExport implements WithMultipleSheets, WithEvents
{
    use Exportable;

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @param  array<int, array{name: string, description: string, values: array<int, array{code: string, label: string}>}>  $legends
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $meta = [],
        private readonly array $legends = [],
    ) {}

    public function sheets(): array
    {
        return [
            0 => new ProfessionalSpreadsheetDataSheet($this->headings, $this->rows, $this->meta),
            1 => new ProfessionalSpreadsheetInstructionSheet($this->meta, $this->headings, $this->legends),
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeWriting::class => function (BeforeWriting $event): void {
                $props = $event->writer->getDelegate()->getProperties();
                $props->setCreator('PT Integrasi Bisnis Eksekutif — IBE Projects');
                $props->setLastModifiedBy($this->meta['generated_by'] ?? '-');
                $props->setTitle($this->meta['title'] ?? 'IBE Projects Export');
                $props->setSubject($this->meta['project_name'] ?? '-');
                $props->setDescription(($this->meta['subtitle'] ?? '').' — Digenerate: '.($this->meta['generated_at'] ?? ''));
                $props->setKeywords('IBE_PROJECTS_TEMPLATE_V1:'.($this->meta['kind'] ?? 'data'));
                $props->setCategory($this->meta['kind'] ?? 'data');
                $props->setCompany('PT Integrasi Bisnis Eksekutif');
            },
        ];
    }
}

class ProfessionalSpreadsheetDataSheet extends DefaultValueBinder implements ShouldAutoSize, WithCustomValueBinder, WithEvents
{
    use Exportable;

    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $meta,
    ) {}

    public function title(): string
    {
        return match ($this->meta['kind'] ?? 'data') {
            'tasks' => 'Tasks',
            'budget' => 'RAB',
            default => 'Data',
        };
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $colCount = count($this->headings);
                if ($colCount === 0) {
                    return;
                }
                $lastCol = Coordinate::stringFromColumnIndex($colCount);

                // === Row 1: headings ===
                foreach ($this->headings as $idx => $heading) {
                    $col = Coordinate::stringFromColumnIndex($idx + 1);
                    $sheet->setCellValue("{$col}1", $heading);
                }
                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A7A8C']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0F766E']],
                    ],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(30);

                // === Row 2+: data ===
                foreach ($this->rows as $rowIdx => $rowData) {
                    $excelRow = $rowIdx + 2;
                    foreach ($rowData as $colIdx => $value) {
                        $col = Coordinate::stringFromColumnIndex($colIdx + 1);
                        $cell = $sheet->getCell("{$col}{$excelRow}");
                        if (is_string($value) && preg_match('/^[=+\-@]/', ltrim($value))) {
                            $cell->setValueExplicit($value, DataType::TYPE_STRING);
                        } else {
                            $cell->setValue($value);
                        }
                    }
                }

                $lastRow = 1 + count($this->rows);

                if ($lastRow > 1) {
                    $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
                        'font' => ['size' => 10, 'color' => ['rgb' => '0F172A']],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                        'borders' => [
                            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']],
                        ],
                    ]);
                    for ($r = 2; $r <= $lastRow; $r++) {
                        if (($r - 2) % 2 === 1) {
                            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
                            ]);
                        }
                        $sheet->getRowDimension($r)->setRowHeight(22);
                    }
                }

                foreach ($this->columnFormats() as $col => $format) {
                    $sheet->getStyle("{$col}2:{$col}".max(2, $lastRow))->getNumberFormat()->setFormatCode($format);
                }

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastCol}".max(1, $lastRow));

                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
                $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);

                $company = 'PT Integrasi Bisnis Eksekutif';
                $title = $this->meta['title'] ?? 'IBE Projects';
                $project = $this->meta['project_name'] ?? '-';
                $generated = $this->meta['generated_at'] ?? '';
                $sheet->getHeaderFooter()
                    ->setOddHeader("&L&B{$company}&R&B{$title}")
                    ->setOddFooter("&LProjek: {$project}  |  Digenerate: {$generated}&RHalaman &P dari &N");
            },
        ];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && preg_match('/^[=+\-@]/', ltrim($value))) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function columnFormats(): array
    {
        $kind = $this->meta['kind'] ?? 'data';
        $letter = fn (int $idx) => Coordinate::stringFromColumnIndex($idx);
        $idr = '_("Rp"* #,##0_);_("Rp"* (#,##0);_("Rp"* "-"_);_(@_)';
        $date = 'yyyy-mm-dd';

        if ($kind === 'tasks') {
            return [
                $letter(11) => $date,
                $letter(12) => $date,
            ];
        }
        if ($kind === 'budget') {
            return [
                $letter(7) => $idr,
                $letter(8) => $date,
                $letter(9) => $date,
                $letter(11) => $date,
                $letter(12) => '0.00',
                $letter(13) => '0.00',
                $letter(14) => $idr,
            ];
        }

        return [];
    }
}

class ProfessionalSpreadsheetInstructionSheet extends DefaultValueBinder implements WithCustomValueBinder, WithEvents, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private readonly array $meta,
        private readonly array $headings,
        private readonly array $legends,
    ) {}

    public function title(): string
    {
        return 'Petunjuk';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->setCellValue('A1', $this->meta['company_name'] ?? 'PT Integrasi Bisnis Eksekutif');
                $sheet->setCellValue('A2', $this->meta['title'] ?? 'IBE Projects Export');
                $sheet->setCellValue('A3', $this->meta['subtitle'] ?? '');
                $sheet->mergeCells('A1:C1');
                $sheet->mergeCells('A2:C2');
                $sheet->mergeCells('A3:C3');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F766E']],
                ]);
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '0F172A']],
                ]);
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '64748B']],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(30);

                $metaRows = [
                    ['Projek', $this->meta['project_name'] ?? '-'],
                    ['Digenerate oleh', $this->meta['generated_by'] ?? '-'],
                    ['Waktu generate', $this->meta['generated_at'] ?? '-'],
                    ['Format', 'IBE Projects Template v1'],
                    ['Tipe file', ($this->meta['kind'] ?? 'data') === 'budget' ? 'RAB (Rencana Anggaran Biaya)' : 'Tasks Projek'],
                    ['Sheet data', ($this->meta['kind'] ?? 'data') === 'budget' ? 'RAB' : 'Tasks'],
                ];
                $row = 5;
                foreach ($metaRows as [$k, $v]) {
                    $sheet->setCellValue("A{$row}", $k);
                    $sheet->setCellValue("B{$row}", (string) $v);
                    $sheet->mergeCells("B{$row}:C{$row}");
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB('64748B');
                    $row++;
                }
                $sheet->getStyle('A5:C'.($row - 1))->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
                ]);

                $row += 2;

                $sheet->setCellValue("A{$row}", 'CARA PAKAI');
                $sheet->mergeCells("A{$row}:C{$row}");
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F766E']],
                ]);
                $row++;
                $dataSheetName = ($this->meta['kind'] ?? 'data') === 'budget' ? 'RAB' : 'Tasks';
                $tips = [
                    "1. Sheet \"{$dataSheetName}\" berisi data. Baris 1 = nama kolom, baris 2 dst = data.",
                    '2. JANGAN mengganti nama kolom di baris 1. Sistem membaca kolom berdasarkan nama tersebut.',
                    '3. Kolom ID: kosongkan untuk data BARU. Isi dengan ID existing untuk UPDATE data yang sudah ada.',
                    '4. Format tanggal: YYYY-MM-DD (contoh 2026-07-28) atau tanggal Excel native.',
                    '5. Nilai enum (Tipe, Status, Prioritas, Kategori) HARUS lowercase persis seperti daftar di bawah.',
                    '6. Maksimal 1.000 baris per file. Untuk data lebih besar, pecah jadi beberapa file.',
                    '7. Formula Excel (dimulai dgn =, +, -, @) otomatis di-treat sebagai text demi keamanan.',
                    '8. Upload melalui tombol Import di halaman Projek. Validasi atomik: 1 baris gagal → tidak ada yg disimpan.',
                ];
                foreach ($tips as $tip) {
                    $sheet->setCellValue("A{$row}", $tip);
                    $sheet->mergeCells("A{$row}:C{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
                        'font' => ['size' => 10],
                    ]);
                    $sheet->getRowDimension($row)->setRowHeight(20);
                    $row++;
                }

                $row += 2;

                $sheet->setCellValue("A{$row}", 'DAFTAR KOLOM');
                $sheet->mergeCells("A{$row}:C{$row}");
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F766E']],
                ]);
                $row++;
                $sheet->setCellValue("A{$row}", 'No');
                $sheet->setCellValue("B{$row}", 'Nama Kolom');
                $sheet->setCellValue("C{$row}", 'Keterangan');
                $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A7A8C']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $tableStart = $row;
                $row++;

                $desc = $this->headingsDescription();
                foreach ($this->headings as $idx => $heading) {
                    $sheet->setCellValue("A{$row}", $idx + 1);
                    $sheet->setCellValue("B{$row}", $heading);
                    $sheet->setCellValue("C{$row}", $desc[$heading] ?? '-');
                    $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                    $row++;
                }
                $sheet->getStyle("A{$tableStart}:C".($row - 1))->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
                ]);

                foreach ($this->legends as $legend) {
                    $row += 2;
                    $sheet->setCellValue("A{$row}", 'NILAI YANG DIPERBOLEHKAN — Kolom: '.$legend['name']);
                    $sheet->mergeCells("A{$row}:C{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0F766E']],
                    ]);
                    $row++;
                    if (! empty($legend['description'])) {
                        $sheet->setCellValue("A{$row}", $legend['description']);
                        $sheet->mergeCells("A{$row}:C{$row}");
                        $sheet->getStyle("A{$row}")->applyFromArray([
                            'font' => ['italic' => true, 'color' => ['rgb' => '64748B'], 'size' => 10],
                        ]);
                        $row++;
                    }
                    $sheet->setCellValue("A{$row}", 'Kode');
                    $sheet->setCellValue("B{$row}", 'Arti / Label');
                    $sheet->mergeCells("B{$row}:C{$row}");
                    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
                    ]);
                    $legendStart = $row;
                    $row++;
                    foreach ($legend['values'] as $v) {
                        $sheet->setCellValue("A{$row}", $v['code']);
                        $sheet->setCellValue("B{$row}", $v['label']);
                        $sheet->mergeCells("B{$row}:C{$row}");
                        $row++;
                    }
                    $sheet->getStyle("A{$legendStart}:C".($row - 1))->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
                    ]);
                }

                $sheet->getColumnDimension('A')->setWidth(20);
                $sheet->getColumnDimension('B')->setWidth(32);
                $sheet->getColumnDimension('C')->setWidth(70);
            },
        ];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        return parent::bindValue($cell, $value);
    }

    private function headingsDescription(): array
    {
        return [
            'ID' => 'Kosongkan untuk membuat baris baru. Isi jika ingin update baris yang sudah ada.',
            'Judul' => 'Wajib. Minimal 3, maksimal 200 karakter.',
            'Deskripsi' => 'Opsional. Maksimal 1000 karakter.',
            'Tipe' => 'Nilai: task, story, bug, epic. Default: task.',
            'Status' => 'Nilai: todo, in_progress, in_review, completed. Default: todo.',
            'Prioritas' => 'Nilai: low, medium, high, urgent. Default: medium.',
            'Story Points' => 'Bilangan bulat 0-255. Opsional.',
            'Estimasi Menit' => 'Bilangan bulat menit. Opsional.',
            'Aktual Menit' => 'Bilangan bulat menit. Opsional.',
            'Email Assignee' => 'Email karyawan aktif di company yang sama. Opsional.',
            'Tanggal Mulai' => 'Format YYYY-MM-DD atau tanggal Excel. Opsional.',
            'Tenggat' => 'Format YYYY-MM-DD. Harus >= Tanggal Mulai jika keduanya diisi.',
            'Kode' => 'Wajib. Kode unik item RAB per projek. Maksimal 50 karakter.',
            'Kategori' => 'Nilai: material, labor, equipment, subcontract, overhead, other. Default: other.',
            'Uraian' => 'Wajib. Deskripsi item pekerjaan. Maksimal 255 karakter.',
            'Satuan' => 'Wajib. Contoh: sak, HOK, kg, m3, ls, unit. Maksimal 30 karakter.',
            'Volume' => 'Wajib. Angka > 0. Boleh desimal.',
            'Harga Satuan' => 'Wajib. Rupiah tanpa titik/koma. Contoh: 75000.',
            'Mulai Rencana' => 'Opsional. Format YYYY-MM-DD.',
            'Selesai Rencana' => 'Opsional. Harus >= Mulai Rencana.',
            'Catatan Item' => 'Opsional.',
            'Tanggal Progres' => 'Opsional. Jika diisi maka field progres wajib divalidasi.',
            'Rencana Persen' => 'Persen kumulatif rencana 0-100.',
            'Aktual Persen' => 'Persen kumulatif aktual 0-100.',
            'Biaya Aktual' => 'Rupiah biaya aktual kumulatif.',
            'Catatan Progres' => 'Opsional.',
        ];
    }
}

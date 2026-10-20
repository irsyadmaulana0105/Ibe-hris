<?php

namespace Database\Seeders;

use App\Surat\Models\LetterCategory;
use App\Surat\Models\LetterSignature;
use App\Surat\Models\LetterTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed master data Surat:
 *   - 46 LetterCategory (HR, Sales, Ops, Finance, Legal, Internal)
 *   - 1 default LetterSignature (Aldy Iyik Be, Direktur Utama)
 *   - 8 LetterTemplate untuk surat paling umum (sisanya bisa di-add via UI)
 *
 * Idempotent - pakai updateOrCreate sehingga aman dijalankan ulang.
 */
class SuratSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = 1; // IBE Reality

        $this->seedCategories($companyId);
        $this->seedDefaultSigner($companyId);
        $this->seedTemplates($companyId);
    }

    private function seedCategories(int $companyId): void
    {
        $shortFormat = '{seq:03d}/IBE-{code}/{ROMAN_MONTH}/{year}';
        $longFormat  = '{seq:03d}/{code}/IBE-Reality/{tujuan}/{purpose}/{ROMAN_MONTH}/{year}';

        // [code, name, format, display_order, description]
        $cats = [
            // HR
            ['LOA',       'Letter of Acceptance',          $shortFormat, 110, 'Onboarding intern atau karyawan baru. Bisa Indonesia / Inggris.'],
            ['SK',        'Surat Keterangan Kerja',        $shortFormat, 120, 'Verifikasi status karyawan untuk permohonan visa, bank, dll.'],
            ['SK-R',      'Surat Penerimaan Pengunduran Diri', $shortFormat, 121, 'Acceptance resignation karyawan.'],
            ['SK-PROMO',  'Surat Promosi Jabatan',         $shortFormat, 122, 'Kenaikan jabatan / posisi karyawan.'],
            ['SK-M',      'Surat Mutasi',                  $shortFormat, 123, 'Perpindahan divisi / lokasi karyawan.'],
            ['SC',        'Surat Cuti (Approval)',         $shortFormat, 124, 'Persetujuan cuti karyawan.'],
            ['SP',        'Surat Peringatan',              $shortFormat, 130, 'SP-1, SP-2, atau SP-3 untuk karyawan. Pilih level di body.'],
            ['PHK-T',     'Pemutusan Hubungan Kerja',      $shortFormat, 131, 'Termination letter (PHK).'],
            ['ST',        'Surat Tugas',                   $shortFormat, 140, 'Penugasan kerja, perjalanan dinas, kegiatan tertentu.'],

            // Sales
            ['PEN',       'Surat Penawaran',               $shortFormat, 210, 'Quotation ke klien dengan tabel produk + T&C.'],
            ['POC',       'Konfirmasi Purchase Order',     $shortFormat, 211, 'Acknowledgment PO dari klien.'],
            ['SPK',       'Surat Perintah Kerja',          $shortFormat, 212, 'Work order internal untuk pelaksanaan proyek.'],
            ['BAST',      'Berita Acara Serah Terima',     $shortFormat, 220, 'Handover proyek/barang ke klien. Two-party signoff.'],
            ['BAUT',      'Berita Acara Uji Terima (UAT)', $shortFormat, 221, 'Sign-off UAT proyek software.'],
            ['BAPP',      'Berita Acara Penyelesaian Pekerjaan', $shortFormat, 222, 'Final completion certificate.'],
            ['SPB',       'Surat Pengantar Barang',        $shortFormat, 223, 'Delivery note untuk shipment ke klien.'],
            ['REF',       'Surat Referensi Klien',         $shortFormat, 224, 'Reference letter untuk klien.'],

            // Operasional (SOP format)
            ['UDG',       'Surat Undangan',                $longFormat,  310, 'Undangan kegiatan ke pihak internal/eksternal.'],
            ['UDG-N',     'Undangan Narasumber',           $longFormat,  311, 'Undangan untuk pakar/speaker.'],
            ['PHK',       'Permohonan Kerjasama',          $longFormat,  312, 'Inisiasi kerjasama dengan mitra.'],
            ['PHF',       'Peminjaman Fasilitas',          $longFormat,  313, 'Permohonan pinjam ruang / peralatan.'],
            ['PUB',       'Surat Publikasi',               $longFormat,  314, 'Press release / pengumuman media.'],
            ['IK',        'Surat Izin Kegiatan',           $longFormat,  315, 'Permit untuk pelaksanaan acara.'],
            ['PH',        'Surat Permohonan (Umum)',       $longFormat,  316, 'Permohonan fleksibel ke pihak lain.'],
            ['KEG',       'Surat Kegiatan (Umum)',         $longFormat,  317, 'Surat kegiatan umum.'],

            // Finance
            ['INV',       'Invoice / Tagihan',             $shortFormat, 410, 'Tagihan resmi dengan PPN dan instruksi pembayaran.'],
            ['PROF',      'Proforma Invoice',              $shortFormat, 411, 'Pre-invoice / pro forma.'],
            ['KW',        'Kwitansi',                      $shortFormat, 412, 'Bukti penerimaan uang.'],
            ['TT',        'Tanda Terima',                  $shortFormat, 413, 'Receipt umum (barang/dokumen).'],
            ['CN',        'Credit Note',                   $shortFormat, 414, 'Nota kredit (pengurangan tagihan).'],
            ['DN',        'Debit Note',                    $shortFormat, 415, 'Nota debit (tambahan tagihan).'],
            ['SPP',       'Surat Penagihan Pembayaran',    $shortFormat, 416, 'Collection letter untuk piutang.'],
            ['REM',       'Reminder Pembayaran',           $shortFormat, 417, 'Pengingat jatuh tempo invoice.'],

            // Legal
            ['PERN',      'Surat Pernyataan',              $shortFormat, 510, 'Pernyataan tertulis dengan materai.'],
            ['PRJN',      'Perjanjian / MoU',              $shortFormat, 520, 'Memorandum of Understanding. Multi-party signoff.'],
            ['KSO',       'Kerjasama Operasi',             $shortFormat, 521, 'Consortium agreement antar perusahaan.'],
            ['NDA',       'Non-Disclosure Agreement',      $shortFormat, 522, 'NDA untuk pertukaran info rahasia.'],
            ['KP',        'Surat Kuasa',                   $shortFormat, 530, 'Power of attorney.'],
            ['SK-DIR',    'Surat Keputusan Direksi',       $shortFormat, 540, 'Decree direksi untuk kebijakan internal.'],
            ['SK-BOD',    'Surat Keputusan Board',         $shortFormat, 541, 'Board resolution untuk keputusan strategis.'],

            // Internal
            ['MEMO',      'Memo Internal',                 $shortFormat, 610, 'Short message internal antar tim/divisi.'],
            ['NOTULEN',   'Notulen Rapat',                 $shortFormat, 620, 'Meeting minutes.'],
            ['AGENDA',    'Agenda Rapat',                  $shortFormat, 621, 'Pre-meeting agenda.'],
            ['DH',        'Daftar Hadir',                  $shortFormat, 630, 'Attendance sheet untuk acara/rapat.'],
            ['LAP-K',     'Laporan Kegiatan',              $shortFormat, 640, 'Activity report pasca-kegiatan.'],
            ['LAP-B',     'Laporan Bulanan',               $shortFormat, 641, 'Monthly division report.'],
            ['LPJ',       'Laporan Pertanggungjawaban',    $shortFormat, 642, 'Accountability report finansial.'],
        ];

        foreach ($cats as [$code, $name, $format, $order, $desc]) {
            LetterCategory::updateOrCreate(
                ['company_id' => $companyId, 'code' => $code],
                [
                    'name'             => $name,
                    'description'      => $desc,
                    'numbering_format' => $format,
                    'display_order'    => $order,
                    'active'           => true,
                ]
            );
        }
    }

    private function seedDefaultSigner(int $companyId): void
    {
        LetterSignature::updateOrCreate(
            ['company_id' => $companyId, 'name' => 'Muhammad Aldy Iyik Be'],
            [
                'title'              => 'Direktur Utama',
                'email'              => 'aldy.iyik@ibereality.com',
                'is_active'          => true,
                'is_company_default' => true,
            ]
        );
    }

    /**
     * Seed 8 template paling umum. Sisanya 38 dapat di-add via admin UI.
     * Pakai placeholder syntax {{...}} yang di-resolve oleh LetterTemplateService.
     */
    private function seedTemplates(int $companyId): void
    {
        $templates = [
            ['PEN',  $this->bodyPenawaran()],
            ['ST',   $this->bodyTugas()],
            ['LOA',  $this->bodyLoa()],
            ['SK',   $this->bodyKeteranganKerja()],
            ['BAST', $this->bodyBast()],
            ['INV',  $this->bodyInvoice()],
            ['PERN', $this->bodyPernyataan()],
            ['MEMO', $this->bodyMemo()],
            ['UDG',  $this->bodyUndangan()],
            ['SP',   $this->bodySp()],
        ];

        foreach ($templates as [$code, $bodyHtml]) {
            $cat = LetterCategory::where('company_id', $companyId)->where('code', $code)->first();
            if (!$cat) continue;
            LetterTemplate::updateOrCreate(
                ['company_id' => $companyId, 'category_id' => $cat->id, 'code' => "{$code}-DEFAULT", 'language' => 'id'],
                [
                    'name'        => "Template Default - {$cat->name}",
                    'description' => "Template default untuk {$cat->name}. Edit/duplicate sesuai kebutuhan.",
                    'body_html'   => $bodyHtml,
                    'is_system'   => true,
                    'active'      => true,
                    'version'     => 1,
                ]
            );
        }
    }

    // ─── Template body content ─────────────────────────────────────────

    private function bodyPenawaran(): string
    {
        return <<<'HTML'
<table style="width:100%; font-size:10.5px; margin:0 0 14px;">
    <tr>
        <td style="width:55%; vertical-align:top;">
            <div><strong>Release Date</strong>&nbsp;: {{letter.date}}</div>
            <div><strong>Expire Date</strong>&nbsp;&nbsp;: {{letter.expiry}}</div>
            <div style="margin-top:6px;"><strong>Kota</strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: Sidoarjo</div>
        </td>
        <td style="vertical-align:top;">
            <div><strong>Kepada Yth.</strong></div>
            <div style="margin-top:4px;">{{recipient.name}}</div>
            <div>{{recipient.company}}</div>
            <div>{{recipient.address}}</div>
        </td>
    </tr>
</table>
<p>Berikut penawaran <strong>{{letter.subject}}</strong> untuk meningkatkan efisiensi operasional Anda.</p>
{{products.table}}
<div style="background:#fafafa; border-left:3px solid #0d9488; padding:10px 14px; margin:14px 0; font-size:10px;">
    <strong>Syarat dan Ketentuan:</strong>
    <ul style="margin:4px 0 0 16px;">
        <li>Rekening <strong>{{company.bank}}</strong></li>
        <li>Pengerjaan dilakukan 7 hari sesudah Surat Resmi Kesepakatan atau diterimanya DP</li>
        <li>Down Payment (DP): Minimal 40%</li>
        <li>Garansi Unit: 1 Tahun</li>
    </ul>
</div>
HTML;
    }

    private function bodyTugas(): string
    {
        return <<<'HTML'
<p>Dalam rangka <strong>{{letter.subject}}</strong>, maka dengan ini <strong>{{company.name}}</strong> memberikan tugas kepada:</p>
<p><em>⟨ Tabel daftar nama - kolom: No, Nama, Instansi/NIK, Jabatan ⟩</em></p>
<table style="width:100%; background:#f8fafc; border-left:3px solid #0d9488; font-size:10.5px; margin:10px 0;">
    <tr><td style="width:30%; padding:3px 6px;">Tempat Tujuan</td><td>: {{custom.destination}}</td></tr>
    <tr><td style="padding:3px 6px;">Berangkat</td><td>: {{custom.depart_date}}</td></tr>
    <tr><td style="padding:3px 6px;">Kembali</td><td>: {{custom.return_date}}</td></tr>
</table>
<p>Seluruh biaya perjalanan dinas, konsumsi, dan akomodasi selama kegiatan menjadi tanggung jawab perusahaan, sesuai dengan ketentuan dan peraturan yang berlaku.</p>
<p>Demikian surat tugas ini dibuat untuk dapat digunakan sebagaimana mestinya.</p>
HTML;
    }

    private function bodyLoa(): string
    {
        return <<<'HTML'
<p style="text-align:right;">{{letter.date}}</p>
<p>Dear <strong>{{recipient.name}}</strong>,</p>
<p>This letter is to confirm that you have been accepted for an internship program with our Company, <strong>{{company.name}} ({{company.brand}})</strong>.</p>
<p>The internship is on-site and includes meaningful tasks focused on developing the knowledge, skills, and abilities applicable to future employment opportunities. An on-site supervisor will conduct weekly check-in meetings to discuss progress and write a final evaluation report.</p>
<p>The program will start on <strong>{{custom.start_date}}</strong> and continue until the required number of internship hours has been completed.</p>
<p>We look forward to having you onboard.</p>
<p style="margin-top:18px;">Best regards,</p>
HTML;
    }

    private function bodyKeteranganKerja(): string
    {
        return <<<'HTML'
<p>Yang bertanda tangan di bawah ini:</p>
<table style="width:100%; font-size:10.5px;">
    <tr><td style="width:30%;">Nama</td><td>: {{signer.name}}</td></tr>
    <tr><td>Jabatan</td><td>: {{signer.title}}</td></tr>
    <tr><td>Perusahaan</td><td>: {{company.name}} ({{company.brand}})</td></tr>
</table>
<p>Dengan ini menerangkan bahwa:</p>
<table style="width:100%; font-size:10.5px;">
    <tr><td style="width:30%;">Nama</td><td>: {{recipient.name}}</td></tr>
    <tr><td>NIK Karyawan</td><td>: {{custom.nik}}</td></tr>
    <tr><td>Jabatan</td><td>: {{custom.posisi}}</td></tr>
    <tr><td>Divisi</td><td>: {{custom.divisi}}</td></tr>
    <tr><td>Tanggal masuk</td><td>: {{custom.tgl_masuk}}</td></tr>
    <tr><td>Status</td><td>: {{custom.status}}</td></tr>
</table>
<p>Adalah benar karyawan {{company.name}} dengan status sebagaimana tersebut di atas. Yang bersangkutan menjalankan tugas dengan baik dan tidak memiliki catatan negatif selama masa kerja.</p>
<p>Surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
HTML;
    }

    private function bodyBast(): string
    {
        return <<<'HTML'
<p>Pada hari ini <strong>{{letter.date}}</strong>, bertempat di kantor {{company.name}}, telah dilakukan serah terima pekerjaan/barang sebagai berikut:</p>
<table style="width:100%; font-size:10.5px;">
    <tr><td style="width:30%;">Nama Proyek</td><td>: {{letter.subject}}</td></tr>
    <tr><td>Mengacu pada</td><td>: {{custom.po_ref}}</td></tr>
    <tr><td>Total Nilai</td><td>: {{custom.total_value}}</td></tr>
</table>
<p>Dengan rincian item yang diserahterimakan:</p>
<p><em>⟨ Tabel item serah terima ⟩</em></p>
<p>Pekerjaan/barang yang diserahterimakan telah sesuai spesifikasi dan dapat diterima oleh pihak Penerima dengan baik. Selanjutnya pekerjaan masuk masa garansi {{custom.warranty}} sejak tanggal berita acara ini.</p>
<p>Demikian Berita Acara Serah Terima ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
HTML;
    }

    private function bodyInvoice(): string
    {
        return <<<'HTML'
<table style="width:100%; font-size:10.5px; margin:0 0 14px;">
    <tr>
        <td style="width:55%; vertical-align:top;">
            <div style="color:#64748b;">Bill To:</div>
            <div style="font-weight:700; margin-top:2px;">{{recipient.name}}</div>
            <div>{{recipient.company}}</div>
            <div>{{recipient.address}}</div>
            <div>NPWP: {{custom.npwp}}</div>
        </td>
        <td style="vertical-align:top;">
            <div><strong>Issue Date</strong>&nbsp;: {{letter.date}}</div>
            <div><strong>Due Date</strong>&nbsp;&nbsp;&nbsp;: {{letter.expiry}}</div>
            <div style="margin-top:6px;"><strong>Ref PO</strong>&nbsp;&nbsp;&nbsp;&nbsp;: {{custom.po_ref}}</div>
            <div><strong>Ref BAST</strong>&nbsp;&nbsp;: {{custom.bast_ref}}</div>
        </td>
    </tr>
</table>
<p><em>⟨ Tabel item invoice - kolom: No, Item, Deskripsi, Qty, Harga Satuan, Total. Subtotal + PPN + Total ⟩</em></p>
<div style="background:#fef3c7; border-left:3px solid #f59e0b; padding:10px 14px; margin:14px 0; font-size:10.5px;">
    <strong>Pembayaran transfer ke:</strong><br>
    {{company.bank}}
    <div style="font-size:9px; color:#92400e; margin-top:6px;">Mohon cantumkan No. Invoice <strong>{{letter.number}}</strong> pada keterangan transfer untuk mempercepat rekonsiliasi.</div>
</div>
HTML;
    }

    private function bodyPernyataan(): string
    {
        return <<<'HTML'
<p>Yang bertanda tangan di bawah ini:</p>
<table style="width:100%; font-size:10.5px;">
    <tr><td style="width:30%;">Nama</td><td>: {{recipient.name}}</td></tr>
    <tr><td>Jabatan</td><td>: {{recipient.title}}</td></tr>
    <tr><td>NIK / KTP</td><td>: {{custom.nik}}</td></tr>
    <tr><td>Alamat</td><td>: {{recipient.address}}</td></tr>
</table>
<p>Dengan ini menyatakan dengan sesungguhnya bahwa:</p>
<p><em>⟨ Edit poin-poin pernyataan di rich text editor ⟩</em></p>
<p>Demikian surat pernyataan ini saya buat <strong>dengan sebenarnya, tanpa paksaan dari pihak manapun</strong>, dan apabila di kemudian hari ternyata pernyataan ini tidak benar, saya bersedia menerima konsekuensi sesuai peraturan yang berlaku.</p>
HTML;
    }

    private function bodyMemo(): string
    {
        return <<<'HTML'
<div style="margin:18px 0; padding:14px 18px; background:#f8fafc; border-left:3px solid #0d9488; font-family:'Courier New', monospace; font-size:11px;">
    <div style="margin-bottom:4px;"><strong style="width:80px; display:inline-block;">Kepada</strong>: {{recipient.name}}</div>
    <div style="margin-bottom:4px;"><strong style="width:80px; display:inline-block;">Dari</strong>: {{author.name}}</div>
    <div style="margin-bottom:4px;"><strong style="width:80px; display:inline-block;">Tanggal</strong>: {{letter.date}}</div>
    <div><strong style="width:80px; display:inline-block;">Perihal</strong>: {{letter.subject}}</div>
</div>
<p><em>⟨ Tulis isi memo di rich text editor ⟩</em></p>
HTML;
    }

    private function bodyUndangan(): string
    {
        return <<<'HTML'
<table style="width:100%; font-size:10.5px; margin:14px 0;">
    <tr><td style="width:80px;">Perihal</td><td>: {{letter.subject}}</td></tr>
    <tr><td>Lampiran</td><td>: {{custom.lampiran}}</td></tr>
</table>
<p><strong>Kepada Yth.</strong><br>{{recipient.name}}<br>{{recipient.company}}<br>{{recipient.address}}</p>
<p>Dengan hormat,</p>
<p><em>⟨ Pembuka undangan - context kegiatan ⟩</em></p>
<table style="width:100%; background:#f8fafc; border-left:3px solid #0d9488; font-size:10.5px;">
    <tr><td style="width:30%; padding:3px 6px;">Hari, Tanggal</td><td>: {{custom.event_date}}</td></tr>
    <tr><td style="padding:3px 6px;">Waktu</td><td>: {{custom.event_time}}</td></tr>
    <tr><td style="padding:3px 6px;">Tempat</td><td>: {{custom.event_venue}}</td></tr>
    <tr><td style="padding:3px 6px;">Dress code</td><td>: {{custom.dress_code}}</td></tr>
</table>
<p>Konfirmasi kehadiran paling lambat <strong>{{custom.rsvp_deadline}}</strong>. Atas perhatian dan kehadirannya, kami ucapkan terima kasih.</p>
HTML;
    }

    private function bodySp(): string
    {
        return <<<'HTML'
<p>Berdasarkan hasil evaluasi kinerja dan pelaksanaan tugas, dengan ini {{company.name}} memberikan <strong style="color:#dc2626;">{{custom.sp_level}}</strong> kepada:</p>
<table style="width:100%; font-size:10.5px;">
    <tr><td style="width:30%;">Nama</td><td>: {{recipient.name}}</td></tr>
    <tr><td>NIK</td><td>: {{custom.nik}}</td></tr>
    <tr><td>Jabatan</td><td>: {{recipient.title}}</td></tr>
    <tr><td>Divisi</td><td>: {{custom.divisi}}</td></tr>
</table>
<p>Atas pelanggaran berikut:</p>
<div style="background:#fef2f2; border-left:3px solid #dc2626; padding:10px 14px; margin:8px 0; font-size:10.5px;">
    <em>⟨ Detail pelanggaran (poin-poin) ⟩</em>
</div>
<p>Perbuatan tersebut melanggar <strong>Peraturan Perusahaan Pasal {{custom.peraturan_pasal}}</strong> mengenai disiplin kerja.</p>
<p>Surat peringatan ini berlaku selama <strong>{{custom.masa_berlaku}}</strong> sejak tanggal penerbitan. Apabila dalam masa berlaku tersebut saudara/i kembali melakukan pelanggaran, maka akan diberikan surat peringatan tingkat berikutnya.</p>
<p>Demikian surat peringatan ini disampaikan untuk dipatuhi.</p>
<p style="font-size:10px; color:#475569; font-style:italic;">Tembusan: 1) Arsip HRD · 2) Atasan langsung yang bersangkutan</p>
HTML;
    }
}

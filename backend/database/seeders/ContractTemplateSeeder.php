<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ContractTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed default contract templates untuk semua company yang belum ada template.
 * Style: multinational corporate, compliance UU Ketenagakerjaan (No. 13/2003 +
 * UU Cipta Kerja + PP 35/2021), BPJS, PPh21, aman secara hukum.
 *
 * Idempotent: skip kalau company sudah punya template untuk employment_type
 * tersebut.
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function ($company) {
            foreach ($this->allTemplates() as $type => $tpl) {
                ContractTemplate::firstOrCreate(
                    ['company_id' => $company->id, 'employment_type' => $type],
                    [
                        'title'       => $tpl['title'],
                        'body_html'   => $tpl['body_html'],
                        'source_type' => 'text',
                        'is_active'   => true,
                        'variables'   => [],
                        'version'     => 1,
                    ],
                );
            }
        });
    }

    private function allTemplates(): array
    {
        return [
            'permanent' => [
                'title' => 'Perjanjian Kerja Waktu Tidak Tertentu (PKWTT)',
                'body_html' => $this->permanent(),
            ],
            'probation' => [
                'title' => 'Perjanjian Kerja Masa Percobaan',
                'body_html' => $this->probation(),
            ],
            'pkwt' => [
                'title' => 'Perjanjian Kerja Waktu Tertentu (PKWT)',
                'body_html' => $this->pkwt(),
            ],
            'training' => [
                'title' => 'Perjanjian Program Pelatihan Karyawan',
                'body_html' => $this->training(),
            ],
            'intern_campus' => [
                'title' => 'Perjanjian Magang Program Kampus',
                'body_html' => $this->internCampus(),
            ],
            'intern_hub' => [
                'title' => 'Perjanjian Magang Independen (Hub)',
                'body_html' => $this->internHub(),
            ],
            'pkl_smk' => [
                'title' => 'Perjanjian Praktik Kerja Lapangan (PKL) SMK',
                'body_html' => $this->pklSmk(),
            ],
        ];
    }

    private function permanent(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN KERJA WAKTU TIDAK TERTENTU</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Pada hari ini, <strong>{{tanggal_kontrak}}</strong>, telah dibuat perjanjian kerja waktu tidak tertentu (selanjutnya disebut "Perjanjian") antara:</p>

<ol>
<li>
<strong>{{nama_perusahaan}}</strong>, suatu perseroan terbatas yang didirikan berdasarkan hukum Republik Indonesia, berkedudukan di {{alamat_perusahaan}}, dalam hal ini diwakili oleh <strong>{{direktur_utama}}</strong> selaku Direktur Utama, selanjutnya disebut <strong>"PERUSAHAAN"</strong>.
</li>
<li>
<strong>{{nama}}</strong>, tempat/tanggal lahir {{tempat_lahir}}, {{tanggal_lahir}}, beralamat di {{alamat}}, pemegang KTP No. {{ktp_number}}, selanjutnya disebut <strong>"KARYAWAN"</strong>.
</li>
</ol>

<p>PERUSAHAAN dan KARYAWAN secara bersama-sama disebut <strong>"PARA PIHAK"</strong> dan masing-masing disebut <strong>"PIHAK"</strong>.</p>

<h3>Pasal 1 Jabatan dan Ruang Lingkup Pekerjaan</h3>
<ol>
<li>PERUSAHAAN mempekerjakan KARYAWAN untuk jabatan <strong>{{jabatan}}</strong> pada <strong>{{divisi}}</strong>, dengan ruang lingkup tugas sesuai uraian tugas dan tanggung jawab yang ditetapkan PERUSAHAAN dan/atau atasan langsung.</li>
<li>PERUSAHAAN berhak memindahkan KARYAWAN ke jabatan atau unit kerja lain sesuai kebutuhan operasional, dengan tetap memperhatikan kompetensi KARYAWAN, tanpa pengurangan gaji dan tunjangan tetap, serta dalam wilayah kerja yang wajar.</li>
</ol>

<h3>Pasal 2 Jangka Waktu</h3>
<ol>
<li>Perjanjian ini merupakan Perjanjian Kerja Waktu Tidak Tertentu (PKWTT), berlaku efektif sejak <strong>{{tanggal_mulai_kerja}}</strong>.</li>
<li>KARYAWAN telah menyelesaikan masa percobaan sesuai Pasal 60 UU No. 13/2003 tentang Ketenagakerjaan sebagaimana telah diubah oleh UU No. 6/2023 tentang Cipta Kerja.</li>
</ol>

<h3>Pasal 3 Gaji dan Tunjangan</h3>
<ol>
<li>PERUSAHAAN membayarkan gaji pokok kepada KARYAWAN sebesar <strong>Rp {{gaji_pokok}}</strong> per bulan.</li>
<li>KARYAWAN berhak atas <strong>Bonus Uang Makan</strong> sebesar <strong>Rp 200.000 (dua ratus ribu Rupiah) per bulan</strong> yang diberikan HANYA apabila KARYAWAN memenuhi kehadiran penuh <strong>20 (dua puluh) hari kerja</strong> dalam bulan tersebut. Bonus bersifat mutlak: apabila kehadiran KARYAWAN kurang dari 20 hari kerja dalam sebulan, Bonus Uang Makan tidak diberikan (Rp 0) tanpa perhitungan proporsional. Hari izin, cuti, sakit, dan ketidakhadiran lainnya tidak dihitung sebagai hari kerja.</li>
<li>Pembayaran gaji dilakukan setiap tanggal 28 melalui transfer bank ke rekening KARYAWAN.</li>
<li>Pajak Penghasilan Pasal 21 (PPh21) atas gaji KARYAWAN dipotong langsung oleh PERUSAHAAN sesuai peraturan perpajakan yang berlaku (PMK No. 168/2023 dan perubahannya).</li>
<li>KARYAWAN berhak atas Tunjangan Hari Raya (THR) Keagamaan sesuai PP No. 36/2021: sebesar 1 (satu) bulan gaji bagi KARYAWAN yang telah bekerja 12 bulan atau lebih, atau proporsional (masa kerja dalam bulan dibagi 12 dikalikan 1 bulan gaji) bagi KARYAWAN dengan masa kerja kurang dari 12 bulan.</li>
</ol>

<h3>Pasal 4 Waktu Kerja</h3>
<ol>
<li>Waktu kerja adalah 8 (delapan) jam per hari dan 40 (empat puluh) jam per minggu, sesuai Pasal 77 UU Ketenagakerjaan.</li>
<li>Hari kerja adalah Senin sampai dengan Jumat, dengan pengaturan shift yang dapat disesuaikan berdasarkan kebutuhan operasional PERUSAHAAN.</li>
<li>Ketentuan lembur mengikuti kebijakan PERUSAHAAN dan Pasal 78 UU Ketenagakerjaan.</li>
</ol>

<h3>Pasal 5 Cuti dan Izin</h3>
<ol>
<li>KARYAWAN berhak atas cuti tahunan sebanyak 12 (dua belas) hari kerja setelah bekerja selama 12 bulan berturut-turut, sesuai Pasal 79 UU Ketenagakerjaan.</li>
<li>Pengajuan cuti tahunan wajib mendapat persetujuan tertulis dari atasan langsung minimal 3 (tiga) hari kerja sebelum tanggal cuti.</li>
<li>Cuti sakit dengan surat keterangan dokter tidak mengurangi hak cuti tahunan.</li>
<li>KARYAWAN wanita berhak atas cuti melahirkan selama 3 (tiga) bulan, yaitu 1,5 (satu setengah) bulan sebelum dan 1,5 (satu setengah) bulan setelah melahirkan, sesuai Pasal 82 UU Ketenagakerjaan, dengan gaji penuh. Perpanjangan dapat diberikan berdasarkan surat keterangan dokter.</li>
<li>KARYAWAN wanita yang mengalami keguguran berhak atas cuti selama 1,5 (satu setengah) bulan dengan surat keterangan dokter.</li>
<li>KARYAWAN berhak atas cuti bersama sesuai keputusan Pemerintah Republik Indonesia setiap tahun.</li>
</ol>

<h3>Pasal 6 Jaminan Sosial</h3>
<ol>
<li>PERUSAHAAN wajib mendaftarkan KARYAWAN pada program <strong>BPJS Kesehatan</strong> sejak hari pertama hubungan kerja sesuai UU No. 24/2011 dan PP No. 82/2018. Iuran sebesar 5% dari upah dengan komposisi 4% ditanggung PERUSAHAAN dan 1% dipotong dari gaji KARYAWAN.</li>
<li>PERUSAHAAN mendaftarkan KARYAWAN pada program <strong>BPJS Ketenagakerjaan</strong> dengan pendekatan bertahap sesuai UU No. 24/2011, UU No. 6/2023, dan PP No. 6/2025:
  <ol type="a">
  <li>Sejak awal hubungan kerja: Jaminan Kecelakaan Kerja (JKK) sebesar 0,24% sampai 1,74% dari upah dan Jaminan Kematian (JKM) sebesar 0,30% dari upah, keduanya sepenuhnya ditanggung PERUSAHAAN.</li>
  <li>Sesuai kesiapan operasional PERUSAHAAN: Jaminan Hari Tua (JHT) sebesar 5,7% (3,7% PERUSAHAAN, 2% KARYAWAN), Jaminan Pensiun (JP) sebesar 3% (2% PERUSAHAAN, 1% KARYAWAN) dengan batas upah tertinggi mengikuti ketentuan tahunan BPJS Ketenagakerjaan, dan Jaminan Kehilangan Pekerjaan (JKP) sebesar 0,46% dari rekomposisi JKK dan subsidi Pemerintah.</li>
  </ol>
</li>
<li>Pemberitahuan penambahan program disampaikan kepada KARYAWAN secara tertulis paling lambat pada saat pendaftaran diproses.</li>
<li>Bukti kepesertaan berupa kartu digital tersedia melalui aplikasi Mobile JKN (BPJS Kesehatan) dan JMO (BPJS Ketenagakerjaan).</li>
</ol>

<h3>Pasal 7 Keselamatan dan Kesehatan Kerja</h3>
<ol>
<li>PERUSAHAAN menyediakan lingkungan kerja yang aman dan sehat sesuai UU No. 1/1970 tentang Keselamatan Kerja dan peraturan pelaksananya.</li>
<li>KARYAWAN wajib mematuhi seluruh prosedur Keselamatan dan Kesehatan Kerja (K3) yang ditetapkan PERUSAHAAN, termasuk penggunaan Alat Pelindung Diri (APD) di area kerja tertentu.</li>
<li>KARYAWAN wajib melaporkan setiap kondisi tidak aman, kecelakaan kerja, atau potensi bahaya kepada atasan langsung sesegera mungkin.</li>
</ol>

<h3>Pasal 8 Kerahasiaan dan Larangan Kompetisi</h3>
<ol>
<li>KARYAWAN wajib menjaga kerahasiaan informasi PERUSAHAAN, termasuk namun tidak terbatas pada data klien, strategi bisnis, teknologi, dan kekayaan intelektual, selama masa kerja dan 2 (dua) tahun setelah berakhirnya hubungan kerja.</li>
<li>Pelanggaran kewajiban kerahasiaan dikenakan sanksi hukum sesuai peraturan yang berlaku, termasuk kewajiban ganti rugi kepada PERUSAHAAN.</li>
<li>Selama masa kerja, KARYAWAN dilarang bekerja pada perusahaan pesaing langsung PERUSAHAAN di bidang usaha yang sama tanpa persetujuan tertulis dari PERUSAHAAN.</li>
<li>Setelah berakhirnya hubungan kerja, larangan bekerja pada pesaing langsung berlaku selama 6 (enam) bulan, terbatas pada perusahaan yang bersaing langsung dengan bidang usaha inti PERUSAHAAN di wilayah operasional yang sama.</li>
</ol>

<h3>Pasal 9 Hak atas Kekayaan Intelektual</h3>
<ol>
<li>Segala hasil karya, invensi, kreasi, atau kekayaan intelektual yang dibuat oleh KARYAWAN dalam rangka pelaksanaan tugas atau dengan menggunakan fasilitas dan sumber daya PERUSAHAAN menjadi milik penuh PERUSAHAAN.</li>
<li>Kekayaan intelektual yang dibuat KARYAWAN di luar jam kerja, tidak terkait dengan pekerjaan pada PERUSAHAAN, dan tanpa menggunakan sumber daya PERUSAHAAN, tetap menjadi hak KARYAWAN.</li>
</ol>

<h3>Pasal 10 Pengakhiran Hubungan Kerja</h3>
<ol>
<li>Pengakhiran hubungan kerja dilakukan sesuai UU No. 6/2023 tentang Cipta Kerja (sebagaimana telah diubah oleh Putusan Mahkamah Konstitusi No. 168/PUU-XXI/2023 tanggal 31 Oktober 2024), PP No. 35/2021, dan peraturan pelaksana lainnya.</li>
<li>KARYAWAN yang mengundurkan diri wajib memberikan pemberitahuan tertulis minimal <strong>30 (tiga puluh) hari kalender</strong> sebelum tanggal efektif pengunduran diri.</li>
<li>PERUSAHAAN berhak mengakhiri hubungan kerja apabila KARYAWAN:
  <ol type="a">
  <li>tidak masuk kerja 5 (lima) hari kerja atau lebih berturut-turut tanpa keterangan tertulis yang dapat dipertanggungjawabkan, setelah dipanggil 2 (dua) kali secara tertulis oleh PERUSAHAAN;</li>
  <li>melakukan pelanggaran sebagaimana diatur dalam Pasal 52 PP No. 35/2021;</li>
  <li>melanggar ketentuan kerahasiaan atau larangan kompetisi sebagaimana diatur dalam Pasal 8 Perjanjian ini.</li>
  </ol>
</li>
<li>PERUSAHAAN dapat mengakhiri hubungan kerja karena alasan efisiensi, restrukturisasi, penutupan perusahaan, atau pailit, dengan pembayaran pesangon, uang penghargaan masa kerja, dan uang penggantian hak sesuai skema Pasal 40 sampai dengan Pasal 47 PP No. 35/2021.</li>
</ol>

<h3>Pasal 11 Keadaan Kahar</h3>
<p>Apabila terjadi keadaan kahar (force majeure) seperti bencana alam, wabah penyakit, kerusuhan, perang, atau peristiwa serupa yang berada di luar kendali PARA PIHAK, kewajiban yang tidak dapat dilaksanakan karena keadaan tersebut tidak dianggap sebagai pelanggaran Perjanjian. PARA PIHAK sepakat untuk membahas penyesuaian pelaksanaan Perjanjian secara musyawarah dalam kondisi tersebut.</p>

<h3>Pasal 12 Perubahan Perjanjian</h3>
<p>Perubahan atas ketentuan Perjanjian ini hanya dapat dilakukan berdasarkan kesepakatan tertulis PARA PIHAK dalam bentuk addendum yang menjadi bagian tidak terpisahkan dari Perjanjian ini.</p>

<h3>Pasal 13 Ketentuan Penutup</h3>
<ol>
<li>Perjanjian ini tunduk pada hukum Republik Indonesia, khususnya UU Ketenagakerjaan dan peraturan pelaksananya.</li>
<li>Segala perselisihan yang timbul akan diselesaikan terlebih dahulu secara musyawarah. Apabila tidak tercapai kesepakatan, PARA PIHAK sepakat menyelesaikannya melalui Pengadilan Hubungan Industrial (PHI) di wilayah hukum PERUSAHAAN.</li>
<li>Perjanjian ini dibuat dalam 2 (dua) rangkap, masing-masing untuk PARA PIHAK, dan dianggap sah serta mengikat sejak ditandatangani PARA PIHAK. Materai fisik dapat ditempelkan pada dokumen cetak sesuai kebutuhan administratif.</li>
</ol>

<p>Demikian Perjanjian ini dibuat dengan itikad baik oleh PARA PIHAK.</p>
HTML;
    }

    private function probation(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN KERJA MASA PERCOBAAN</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Pada hari ini, <strong>{{tanggal_kontrak}}</strong>, dibuat perjanjian kerja masa percobaan (selanjutnya disebut "Perjanjian") antara:</p>

<ol>
<li><strong>{{nama_perusahaan}}</strong>, berkedudukan di {{alamat_perusahaan}}, diwakili oleh <strong>{{direktur_utama}}</strong>, selanjutnya disebut <strong>"PERUSAHAAN"</strong>.</li>
<li><strong>{{nama}}</strong>, pemegang KTP No. {{ktp_number}}, beralamat di {{alamat}}, selanjutnya disebut <strong>"KARYAWAN"</strong>.</li>
</ol>

<h3>Pasal 1 Masa Percobaan</h3>
<ol>
<li>PERUSAHAAN menerima KARYAWAN untuk menjalani masa percobaan selama <strong>3 (tiga) bulan</strong> terhitung sejak <strong>{{tanggal_mulai_kerja}}</strong>.</li>
<li>Masa percobaan ini sesuai dengan Pasal 60 UU No. 13/2003.</li>
<li>Selama masa percobaan, PERUSAHAAN akan mengevaluasi kinerja KARYAWAN untuk menentukan kelanjutan hubungan kerja.</li>
</ol>

<h3>Pasal 2 Jabatan</h3>
<p>KARYAWAN akan bekerja sebagai <strong>{{jabatan}}</strong> di divisi <strong>{{divisi}}</strong>.</p>

<h3>Pasal 3 Gaji</h3>
<ol>
<li>Gaji pokok selama masa percobaan sebesar <strong>Rp {{gaji_pokok}}</strong> per bulan.</li>
<li>Uang makan dan transport sebesar Rp {{uang_makan}} per hari kehadiran.</li>
<li>Pembayaran tanggal 28 setiap bulan via transfer bank.</li>
</ol>

<h3>Pasal 4 Waktu Kerja</h3>
<p>Senin s.d. Jumat, 8 jam per hari, 40 jam per minggu.</p>

<h3>Pasal 5 Pengakhiran Selama Masa Percobaan</h3>
<ol>
<li>PARA PIHAK dapat mengakhiri hubungan kerja kapan saja selama masa percobaan tanpa syarat pesangon, dengan pemberitahuan tertulis minimal 7 (tujuh) hari sebelumnya.</li>
<li>KARYAWAN yang tidak lulus masa percobaan tidak berhak atas pesangon apapun.</li>
</ol>

<h3>Pasal 6 Setelah Masa Percobaan</h3>
<ol>
<li>Apabila kinerja KARYAWAN dinilai memuaskan, PERUSAHAAN akan menawarkan perjanjian kerja waktu tidak tertentu (PKWTT).</li>
<li>Apabila kinerja tidak memuaskan, hubungan kerja berakhir tanpa kewajiban pemberian pesangon dari PERUSAHAAN.</li>
</ol>

<h3>Pasal 7 Jaminan Sosial</h3>
<ol>
<li>Merujuk pada Undang-Undang Nomor 24 Tahun 2011 tentang BPJS, Undang-Undang Nomor 6 Tahun 2023 tentang Cipta Kerja, dan Peraturan Pemerintah Nomor 6 Tahun 2025, PERUSAHAAN mendaftarkan KARYAWAN pada program BPJS Ketenagakerjaan sejak hari pertama masa percobaan sebagai bentuk pemenuhan kewajiban dasar pemberi kerja. Program yang diikutsertakan meliputi Jaminan Kecelakaan Kerja dan Jaminan Kematian, dengan iuran keduanya sepenuhnya menjadi tanggungan PERUSAHAAN.</li>
<li>Program Jaminan Hari Tua, Jaminan Pensiun, dan Jaminan Kehilangan Pekerjaan diselenggarakan oleh PERUSAHAAN sesuai kebijakan bertahap pada saat KARYAWAN dipromosikan menjadi karyawan tetap, dengan mempertimbangkan kesiapan operasional PERUSAHAAN dan peraturan pelaksana yang berlaku pada saat itu.</li>
<li>Apabila KARYAWAN tidak lolos masa percobaan, kepesertaan BPJS Ketenagakerjaan yang telah aktif dinon-aktifkan sesuai ketentuan BPJS.</li>
</ol>

<h3>Pasal 8 Ketentuan Umum</h3>
<ol>
<li>Ketentuan cuti sakit, pajak (PPh21), dan kerahasiaan mengikuti kebijakan PERUSAHAAN dan peraturan perundangan yang berlaku.</li>
<li>KARYAWAN wajib menjaga kerahasiaan informasi PERUSAHAAN selama masa kerja dan sesudahnya.</li>
</ol>

<p>Demikian Perjanjian ini dibuat dengan itikad baik.</p>
HTML;
    }

    private function pkwt(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN KERJA WAKTU TERTENTU</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Pada hari ini, <strong>{{tanggal_kontrak}}</strong>, telah dibuat Perjanjian Kerja Waktu Tertentu (selanjutnya disebut "Perjanjian") antara:</p>

<ol>
<li>
<strong>{{nama_perusahaan}}</strong>, suatu perseroan terbatas yang didirikan berdasarkan hukum Republik Indonesia, berkedudukan di {{alamat_perusahaan}}, dalam hal ini diwakili oleh <strong>{{direktur_utama}}</strong> selaku Direktur Utama, selanjutnya disebut <strong>"PERUSAHAAN"</strong>.
</li>
<li>
<strong>{{nama}}</strong>, tempat/tanggal lahir {{tempat_lahir}}, {{tanggal_lahir}}, beralamat di {{alamat}}, pemegang KTP No. {{ktp_number}}, selanjutnya disebut <strong>"KARYAWAN"</strong>.
</li>
</ol>

<p>PERUSAHAAN dan KARYAWAN secara bersama-sama disebut <strong>"PARA PIHAK"</strong> dan masing-masing disebut <strong>"PIHAK"</strong>.</p>

<h3>Pasal 1 Jabatan dan Ruang Lingkup Pekerjaan</h3>
<ol>
<li>PERUSAHAAN mempekerjakan KARYAWAN untuk jabatan <strong>{{jabatan}}</strong> pada <strong>{{divisi}}</strong>, dengan ruang lingkup tugas sesuai uraian tugas dan tanggung jawab yang ditetapkan PERUSAHAAN dan/atau atasan langsung.</li>
<li>PERUSAHAAN berhak memindahkan KARYAWAN ke jabatan atau unit kerja lain sesuai kebutuhan operasional, dengan tetap memperhatikan kompetensi KARYAWAN, tanpa pengurangan gaji dan tunjangan tetap, serta dalam wilayah kerja yang wajar.</li>
</ol>

<h3>Pasal 2 Jangka Waktu Kontrak</h3>
<ol>
<li>Perjanjian ini merupakan Perjanjian Kerja Waktu Tertentu (PKWT) sesuai Pasal 56–59 UU No. 13/2003 sebagaimana diubah dengan UU No. 6/2023 dan diatur teknis dalam PP No. 35/2021 serta Permenaker No. 5/2023.</li>
<li>Jangka waktu Perjanjian adalah <strong>{{durasi_kontrak}}</strong>, terhitung sejak <strong>{{tanggal_mulai_kerja}}</strong> sampai dengan <strong>{{tanggal_akhir}}</strong>.</li>
<li>Sesuai Pasal 58 UU Ketenagakerjaan, Perjanjian ini <strong>TIDAK</strong> mensyaratkan masa percobaan. Setiap klausul masa percobaan dalam PKWT batal demi hukum.</li>
</ol>

<h3>Pasal 3 Perpanjangan dan Pembaharuan</h3>
<ol>
<li>Perjanjian ini dapat diperpanjang atau dibaharui berdasarkan kesepakatan tertulis PARA PIHAK, dengan ketentuan total jangka waktu PKWT tidak melebihi <strong>5 (lima) tahun</strong> sesuai Permenaker No. 5/2023.</li>
<li>Perpanjangan harus disepakati paling lambat 14 (empat belas) hari sebelum berakhirnya Perjanjian.</li>
<li>Apabila hubungan kerja terus berlanjut melewati jangka waktu maksimum 5 (lima) tahun PKWT, hubungan kerja demi hukum berubah menjadi Perjanjian Kerja Waktu Tidak Tertentu (PKWTT).</li>
</ol>

<h3>Pasal 4 Gaji dan Tunjangan</h3>
<ol>
<li>PERUSAHAAN membayarkan gaji pokok kepada KARYAWAN sebesar <strong>Rp {{gaji_pokok}}</strong> per bulan.</li>
<li>KARYAWAN berhak atas <strong>Bonus Uang Makan</strong> sebesar <strong>Rp 200.000 (dua ratus ribu Rupiah) per bulan</strong> yang diberikan HANYA apabila KARYAWAN memenuhi kehadiran penuh <strong>20 (dua puluh) hari kerja</strong> dalam bulan tersebut. Bonus bersifat mutlak: apabila kehadiran KARYAWAN kurang dari 20 hari kerja dalam sebulan, Bonus Uang Makan tidak diberikan (Rp 0) tanpa perhitungan proporsional. Hari izin, cuti, sakit, dan ketidakhadiran lainnya tidak dihitung sebagai hari kerja.</li>
<li>Pembayaran gaji dilakukan setiap tanggal 28 melalui transfer bank ke rekening KARYAWAN.</li>
<li>Pajak Penghasilan Pasal 21 (PPh21) atas gaji KARYAWAN dipotong langsung oleh PERUSAHAAN sesuai peraturan perpajakan yang berlaku (PMK No. 168/2023 dan perubahannya).</li>
<li>KARYAWAN berhak atas Tunjangan Hari Raya (THR) Keagamaan proporsional atas masa kerja sesuai PP No. 36/2021, dengan hitungan (masa kerja dalam bulan dibagi 12 dikalikan 1 bulan gaji).</li>
</ol>

<h3>Pasal 5 Waktu Kerja</h3>
<ol>
<li>Waktu kerja adalah 8 (delapan) jam per hari dan 40 (empat puluh) jam per minggu, sesuai Pasal 77 UU Ketenagakerjaan.</li>
<li>Hari kerja adalah Senin sampai dengan Jumat, dengan pengaturan shift yang dapat disesuaikan berdasarkan kebutuhan operasional PERUSAHAAN.</li>
<li>Ketentuan lembur mengikuti kebijakan PERUSAHAAN dan Pasal 78 UU Ketenagakerjaan.</li>
</ol>

<h3>Pasal 6 Cuti dan Izin</h3>
<ol>
<li>KARYAWAN berhak atas cuti tahunan proporsional dengan masa kerja, dengan hitungan 12 (dua belas) hari kerja per 12 bulan masa kerja berturut-turut.</li>
<li>Pengajuan cuti tahunan wajib mendapat persetujuan tertulis dari atasan langsung minimal 3 (tiga) hari kerja sebelum tanggal cuti.</li>
<li>Cuti sakit dengan surat keterangan dokter tidak mengurangi hak cuti tahunan.</li>
<li>KARYAWAN wanita berhak atas cuti melahirkan selama 3 (tiga) bulan, yaitu 1,5 (satu setengah) bulan sebelum dan 1,5 (satu setengah) bulan setelah melahirkan, sesuai Pasal 82 UU Ketenagakerjaan, dengan gaji penuh.</li>
<li>KARYAWAN wanita yang mengalami keguguran berhak atas cuti selama 1,5 (satu setengah) bulan dengan surat keterangan dokter.</li>
<li>KARYAWAN berhak atas cuti bersama sesuai keputusan Pemerintah Republik Indonesia.</li>
</ol>

<h3>Pasal 7 Jaminan Sosial</h3>
<ol>
<li>PERUSAHAAN wajib mendaftarkan KARYAWAN pada program <strong>BPJS Kesehatan</strong> sejak hari pertama hubungan kerja sesuai UU No. 24/2011 dan PP No. 82/2018. Iuran sebesar 5% dari upah dengan komposisi 4% ditanggung PERUSAHAAN dan 1% dipotong dari gaji KARYAWAN.</li>
<li>PERUSAHAAN mendaftarkan KARYAWAN pada program <strong>BPJS Ketenagakerjaan</strong> dengan pendekatan bertahap sesuai UU No. 24/2011, UU No. 6/2023, dan PP No. 6/2025:
  <ol type="a">
  <li>Sejak awal hubungan kerja: Jaminan Kecelakaan Kerja (JKK) sebesar 0,24% sampai 1,74% dari upah dan Jaminan Kematian (JKM) sebesar 0,30% dari upah, keduanya sepenuhnya ditanggung PERUSAHAAN.</li>
  <li>Sesuai kesiapan operasional PERUSAHAAN: Jaminan Hari Tua (JHT) sebesar 5,7% (3,7% PERUSAHAAN, 2% KARYAWAN), Jaminan Pensiun (JP) sebesar 3% (2% PERUSAHAAN, 1% KARYAWAN), dan Jaminan Kehilangan Pekerjaan (JKP) sebesar 0,46% dari rekomposisi JKK dan subsidi Pemerintah.</li>
  </ol>
</li>
<li>Bukti kepesertaan berupa kartu digital tersedia melalui aplikasi Mobile JKN (BPJS Kesehatan) dan JMO (BPJS Ketenagakerjaan).</li>
</ol>

<h3>Pasal 8 Keselamatan dan Kesehatan Kerja</h3>
<ol>
<li>PERUSAHAAN menyediakan lingkungan kerja yang aman dan sehat sesuai UU No. 1/1970 tentang Keselamatan Kerja dan peraturan pelaksananya.</li>
<li>KARYAWAN wajib mematuhi seluruh prosedur Keselamatan dan Kesehatan Kerja (K3) yang ditetapkan PERUSAHAAN, termasuk penggunaan Alat Pelindung Diri (APD) di area kerja tertentu.</li>
<li>KARYAWAN wajib melaporkan setiap kondisi tidak aman, kecelakaan kerja, atau potensi bahaya kepada atasan langsung sesegera mungkin.</li>
</ol>

<h3>Pasal 9 Uang Kompensasi Akhir Kontrak (UMK PKWT)</h3>
<ol>
<li>Sesuai <strong>Pasal 15 ayat (1) PP No. 35/2021</strong>, PERUSAHAAN wajib memberikan Uang Kompensasi kepada KARYAWAN pada saat berakhirnya Perjanjian.</li>
<li>Besaran Uang Kompensasi dihitung dengan rumus: <strong>(masa kerja dalam bulan dibagi 12) dikalikan 1 (satu) bulan upah</strong>, yaitu upah pokok ditambah tunjangan tetap.</li>
<li>Apabila Perjanjian ini diperpanjang, Uang Kompensasi diberikan pada saat selesainya jangka waktu Perjanjian sebelum perpanjangan dilakukan.</li>
<li>Uang Kompensasi juga wajib dibayarkan secara proporsional apabila Perjanjian berakhir sebelum jangka waktu yang disepakati atas inisiatif salah satu PIHAK, dengan tetap memperhatikan ketentuan Pasal 10 Perjanjian ini.</li>
<li>Uang Kompensasi <strong>tidak berlaku</strong> bagi tenaga kerja asing sesuai Pasal 15 ayat (5) PP No. 35/2021.</li>
</ol>

<h3>Pasal 10 Pengakhiran Sebelum Jangka Waktu Berakhir</h3>
<ol>
<li>Merujuk pada <strong>Pasal 62 UU No. 13/2003</strong>, apabila salah satu PIHAK mengakhiri hubungan kerja sebelum berakhirnya jangka waktu Perjanjian, PIHAK yang mengakhiri wajib membayar ganti rugi kepada PIHAK lainnya sebesar upah KARYAWAN sampai dengan batas waktu berakhirnya Perjanjian.</li>
<li>Kewajiban ganti rugi sebagaimana dimaksud pada ayat (1) tidak berlaku apabila pengakhiran hubungan kerja terjadi karena sebab-sebab yang diatur dalam Pasal 61 ayat (1) UU Ketenagakerjaan, termasuk namun tidak terbatas pada:
  <ol type="a">
  <li>KARYAWAN meninggal dunia;</li>
  <li>berakhirnya jangka waktu Perjanjian;</li>
  <li>selesainya suatu pekerjaan tertentu;</li>
  <li>adanya putusan pengadilan dan/atau putusan lembaga penyelesaian perselisihan hubungan industrial yang telah mempunyai kekuatan hukum tetap;</li>
  <li>adanya keadaan atau kejadian tertentu yang dicantumkan dalam Perjanjian yang dapat menyebabkan berakhirnya hubungan kerja.</li>
  </ol>
</li>
<li>Merujuk pada <strong>Pasal 63 UU No. 13/2003</strong>, KARYAWAN dapat mengakhiri hubungan kerja tanpa kewajiban membayar ganti rugi apabila PERUSAHAAN melakukan pelanggaran termasuk namun tidak terbatas pada penganiayaan, penghinaan berat, tidak membayar upah tepat pada waktunya selama 3 (tiga) bulan berturut-turut atau lebih, atau tidak melakukan kewajiban yang dijanjikan.</li>
</ol>

<h3>Pasal 11 Kerahasiaan dan Larangan Kompetisi</h3>
<ol>
<li>KARYAWAN wajib menjaga kerahasiaan informasi PERUSAHAAN, termasuk namun tidak terbatas pada data klien, strategi bisnis, teknologi, dan kekayaan intelektual, selama masa kerja dan 2 (dua) tahun setelah berakhirnya hubungan kerja.</li>
<li>Pelanggaran kewajiban kerahasiaan dikenakan sanksi hukum sesuai peraturan yang berlaku, termasuk kewajiban ganti rugi kepada PERUSAHAAN.</li>
<li>Selama masa kerja, KARYAWAN dilarang bekerja pada perusahaan pesaing langsung PERUSAHAAN di bidang usaha yang sama tanpa persetujuan tertulis dari PERUSAHAAN.</li>
<li>Setelah berakhirnya hubungan kerja, larangan bekerja pada pesaing langsung berlaku selama 6 (enam) bulan, terbatas pada perusahaan yang bersaing langsung dengan bidang usaha inti PERUSAHAAN di wilayah operasional yang sama.</li>
</ol>

<h3>Pasal 12 Hak atas Kekayaan Intelektual</h3>
<ol>
<li>Segala hasil karya, invensi, kreasi, atau kekayaan intelektual yang dibuat oleh KARYAWAN dalam rangka pelaksanaan tugas atau dengan menggunakan fasilitas dan sumber daya PERUSAHAAN menjadi milik penuh PERUSAHAAN.</li>
<li>Kekayaan intelektual yang dibuat KARYAWAN di luar jam kerja, tidak terkait dengan pekerjaan pada PERUSAHAAN, dan tanpa menggunakan sumber daya PERUSAHAAN, tetap menjadi hak KARYAWAN.</li>
</ol>

<h3>Pasal 13 Keadaan Kahar</h3>
<p>Apabila terjadi keadaan kahar (force majeure) seperti bencana alam, wabah penyakit, kerusuhan, perang, atau peristiwa serupa yang berada di luar kendali PARA PIHAK, kewajiban yang tidak dapat dilaksanakan karena keadaan tersebut tidak dianggap sebagai pelanggaran Perjanjian. PARA PIHAK sepakat untuk membahas penyesuaian pelaksanaan Perjanjian secara musyawarah dalam kondisi tersebut.</p>

<h3>Pasal 14 Perubahan Perjanjian</h3>
<p>Perubahan atas ketentuan Perjanjian ini hanya dapat dilakukan berdasarkan kesepakatan tertulis PARA PIHAK dalam bentuk addendum yang menjadi bagian tidak terpisahkan dari Perjanjian ini.</p>

<h3>Pasal 15 Ketentuan Penutup</h3>
<ol>
<li>Perjanjian ini tunduk pada hukum Republik Indonesia, khususnya UU Ketenagakerjaan dan peraturan pelaksananya.</li>
<li>Segala perselisihan yang timbul akan diselesaikan terlebih dahulu secara musyawarah. Apabila tidak tercapai kesepakatan, PARA PIHAK sepakat menyelesaikannya melalui Pengadilan Hubungan Industrial (PHI) di wilayah hukum PERUSAHAAN.</li>
<li>Perjanjian ini dibuat dalam 2 (dua) rangkap, masing-masing untuk PARA PIHAK, dan dianggap sah serta mengikat sejak ditandatangani PARA PIHAK. Materai fisik dapat ditempelkan pada dokumen cetak sesuai kebutuhan administratif.</li>
</ol>

<p>Demikian Perjanjian ini dibuat dengan itikad baik oleh PARA PIHAK.</p>
HTML;
    }

    private function training(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN PROGRAM PELATIHAN KARYAWAN</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Pada tanggal <strong>{{tanggal_kontrak}}</strong>, dibuat perjanjian antara:</p>

<ol>
<li><strong>{{nama_perusahaan}}</strong>, berkedudukan di {{alamat_perusahaan}}, diwakili oleh <strong>{{direktur_utama}}</strong> (Direktur Utama), selanjutnya disebut <strong>"PERUSAHAAN"</strong>.</li>
<li><strong>{{nama}}</strong>, KTP {{ktp_number}}, beralamat di {{alamat}}, selanjutnya disebut <strong>"PESERTA"</strong>.</li>
</ol>

<h3>Pasal 1 Program Pelatihan</h3>
<ol>
<li>PERUSAHAAN mengikutsertakan PESERTA dalam program pelatihan sebagai <strong>{{jabatan}}</strong> di divisi <strong>{{divisi}}</strong>.</li>
<li>Program dimulai <strong>{{training_mulai}}</strong> sampai <strong>{{training_akhir}}</strong>.</li>
<li>Program ini merupakan program pelatihan untuk peningkatan keterampilan, bukan hubungan kerja tetap.</li>
</ol>

<h3>Pasal 2 Uang Pelatihan (Training Allowance)</h3>
<ol>
<li>PERUSAHAAN memberikan uang pelatihan sebesar <strong>Rp {{daily_rate}}</strong> per hari kehadiran.</li>
<li>Uang pelatihan dibayarkan bulanan berdasarkan jumlah hari hadir yang terverifikasi.</li>
<li>PESERTA memahami bahwa program ini bukan hubungan kerja formal, sehingga tidak berhak atas gaji tetap, THR, pesangon, atau pengakhiran hubungan kerja sesuai UU Ketenagakerjaan.</li>
</ol>

<h3>Pasal 3 Waktu Program</h3>
<p>Senin s.d. Jumat, 8 jam per hari. PESERTA diharapkan mengikuti seluruh sesi sesuai jadwal yang ditetapkan.</p>

<h3>Pasal 4 Kewajiban PESERTA</h3>
<ol>
<li>Mengikuti seluruh sesi pelatihan dengan sungguh-sungguh.</li>
<li>Menjaga kerahasiaan informasi PERUSAHAAN.</li>
<li>Tidak menyalahgunakan fasilitas PERUSAHAAN untuk kepentingan pribadi.</li>
</ol>

<h3>Pasal 5 Setelah Program</h3>
<ol>
<li>Setelah program berakhir, PERUSAHAAN dapat menawarkan hubungan kerja lanjutan (probation atau permanent) berdasarkan hasil evaluasi.</li>
<li>PESERTA tidak memiliki hak otomatis atas hubungan kerja tetap.</li>
</ol>

<h3>Pasal 6 Perlindungan Kecelakaan Kerja</h3>
<ol>
<li>Meskipun status hubungan bukan merupakan hubungan kerja formal, PERUSAHAAN memberikan perlindungan kepada PESERTA melalui pendaftaran BPJS Ketenagakerjaan kategori Bukan Penerima Upah pada program Jaminan Kecelakaan Kerja dan Jaminan Kematian, merujuk pada Undang-Undang Nomor 24 Tahun 2011 tentang BPJS dan Peraturan Pemerintah Nomor 6 Tahun 2025.</li>
<li>Iuran kedua program tersebut sepenuhnya menjadi tanggungan PERUSAHAAN.</li>
<li>PESERTA berhak atas manfaat medis dan santunan apabila terjadi kecelakaan kerja atau kejadian yang dijamin selama masa program pelatihan berjalan.</li>
<li>Program Jaminan Hari Tua, Jaminan Pensiun, dan Jaminan Kehilangan Pekerjaan tidak diikutsertakan mengingat status yang bukan hubungan kerja formal, sehingga perlindungan tersebut menjadi tanggung jawab pribadi PESERTA.</li>
</ol>

<h3>Pasal 7 Pengakhiran Program</h3>
<p>Program dapat diakhiri oleh salah satu PIHAK dengan pemberitahuan sekurang-kurangnya 3 (tiga) hari sebelumnya. PERUSAHAAN tidak berkewajiban memberikan kompensasi apapun selain uang pelatihan hingga hari terakhir kehadiran. Kepesertaan BPJS Ketenagakerjaan yang telah aktif dinon-aktifkan bersamaan dengan berakhirnya program.</p>

<p>Demikian perjanjian ini dibuat dengan itikad baik.</p>
HTML;
    }

    private function internCampus(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN MAGANG PROGRAM KAMPUS</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Dibuat pada <strong>{{tanggal_kontrak}}</strong> antara:</p>

<ol>
<li><strong>{{nama_perusahaan}}</strong>, {{alamat_perusahaan}}, diwakili <strong>{{direktur_utama}}</strong>, selanjutnya <strong>"PERUSAHAAN"</strong>.</li>
<li><strong>{{nama}}</strong>, mahasiswa/i, KTP {{ktp_number}}, selanjutnya <strong>"PEMAGANG"</strong>.</li>
</ol>

<h3>Pasal 1 Tujuan Magang</h3>
<p>Program magang bertujuan memberikan pengalaman kerja praktis kepada PEMAGANG dalam rangka pengembangan kompetensi mahasiswa sesuai dengan program studi di kampus asal.</p>

<h3>Pasal 2 Divisi Penempatan</h3>
<p>PEMAGANG ditempatkan di divisi <strong>{{divisi}}</strong> dengan tugas sesuai uraian tugas magang.</p>

<h3>Pasal 3 Uang Saku (Allowance)</h3>
<ol>
<li>PERUSAHAAN memberikan uang saku bulanan sebesar <strong>Rp {{gaji_pokok}}</strong>.</li>
<li>Uang saku bukan merupakan gaji dan tidak dipotong PPh21 sepanjang di bawah batas PTKP.</li>
<li>Program magang tidak menciptakan hubungan kerja sesuai UU Ketenagakerjaan.</li>
</ol>

<h3>Pasal 4 Waktu Magang</h3>
<p>Senin s.d. Jumat, 8 jam per hari, mengikuti jam kerja PERUSAHAAN. Waktu magang menyesuaikan durasi program yang ditetapkan bersama kampus.</p>

<h3>Pasal 5 Kewajiban PEMAGANG</h3>
<ol>
<li>Mematuhi peraturan PERUSAHAAN.</li>
<li>Menjaga kerahasiaan informasi PERUSAHAAN.</li>
<li>Menyerahkan laporan magang di akhir program.</li>
</ol>

<h3>Pasal 6 Perlindungan Kecelakaan Kerja</h3>
<ol>
<li>Merujuk pada Peraturan Menteri Ketenagakerjaan Nomor 6 Tahun 2020 tentang Penyelenggaraan Pemagangan di Dalam Negeri, Undang-Undang Nomor 6 Tahun 2023 tentang Cipta Kerja, serta ketentuan BPJS Ketenagakerjaan kategori Bukan Penerima Upah, PERUSAHAAN mendaftarkan PEMAGANG pada program Jaminan Kecelakaan Kerja dan Jaminan Kematian.</li>
<li>Iuran kedua program tersebut sepenuhnya menjadi tanggungan PERUSAHAAN.</li>
<li>Kepesertaan berlaku selama masa magang aktif dan dinon-aktifkan pada saat program berakhir.</li>
</ol>

<h3>Pasal 7 Sertifikat</h3>
<p>Setelah menyelesaikan program dengan baik, PERUSAHAAN mengeluarkan sertifikat magang bagi PEMAGANG.</p>

<h3>Pasal 8 Pengakhiran</h3>
<p>Program dapat diakhiri kapan saja oleh salah satu PIHAK dengan pemberitahuan sekurang-kurangnya 7 (tujuh) hari sebelumnya. Uang saku dibayarkan secara proporsional hingga hari terakhir kehadiran.</p>
HTML;
    }

    private function internHub(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN MAGANG INDEPENDEN</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Dibuat pada <strong>{{tanggal_kontrak}}</strong> antara <strong>{{nama_perusahaan}}</strong> (diwakili <strong>{{direktur_utama}}</strong>) dan <strong>{{nama}}</strong> (KTP {{ktp_number}}).</p>

<h3>Pasal 1</h3>
<p>PEMAGANG mengikuti program magang independen di divisi <strong>{{divisi}}</strong> sejak <strong>{{tanggal_mulai_kerja}}</strong>.</p>

<h3>Pasal 2 Uang Saku</h3>
<p>Rp {{gaji_pokok}} per bulan sebagai kontribusi untuk transportasi dan konsumsi.</p>

<h3>Pasal 3 Waktu</h3>
<p>Senin s.d. Jumat, 8 jam/hari. Program tidak menciptakan hubungan kerja formal.</p>

<h3>Pasal 4 Kewajiban</h3>
<p>Kerahasiaan informasi PERUSAHAAN wajib dijaga selama magang dan 1 tahun setelahnya.</p>

<h3>Pasal 5 Perlindungan Kecelakaan Kerja</h3>
<p>PERUSAHAAN mendaftarkan PEMAGANG pada program Jaminan Kecelakaan Kerja dan Jaminan Kematian BPJS Ketenagakerjaan kategori Bukan Penerima Upah, merujuk pada Peraturan Menteri Ketenagakerjaan Nomor 6 Tahun 2020 tentang Penyelenggaraan Pemagangan di Dalam Negeri. Iuran sepenuhnya menjadi tanggungan PERUSAHAAN. Kepesertaan berlaku selama masa magang berjalan.</p>

<h3>Pasal 6 Pengakhiran</h3>
<p>Pengakhiran program dilakukan dengan pemberitahuan sekurang-kurangnya 7 (tujuh) hari sebelumnya.</p>
HTML;
    }

    private function pklSmk(): string
    {
        return <<<'HTML'
<h2 style="text-align:center;">PERJANJIAN PRAKTIK KERJA LAPANGAN (PKL)</h2>
<p style="text-align:center;"><strong>Nomor: {{nomor_kontrak}}</strong></p>

<p>Perjanjian PKL antara <strong>{{nama_perusahaan}}</strong> (diwakili <strong>{{direktur_utama}}</strong>) dan siswa/i <strong>{{nama}}</strong> pada tanggal <strong>{{tanggal_kontrak}}</strong>.</p>

<h3>Pasal 1 Program PKL</h3>
<ol>
<li>Program praktik kerja lapangan sesuai kurikulum SMK di divisi <strong>{{divisi}}</strong>.</li>
<li>Bukan hubungan kerja dalam pengertian UU Ketenagakerjaan.</li>
</ol>

<h3>Pasal 2 Kompensasi</h3>
<p>PERUSAHAAN memberikan uang transport dan konsumsi Rp {{uang_makan}} per hari hadir.</p>

<h3>Pasal 3 Waktu Program</h3>
<p>Senin s.d. Jumat, 8 jam per hari, mengikuti jadwal yang disepakati bersama sekolah.</p>

<h3>Pasal 4 Kewajiban Siswa</h3>
<p>Mengikuti seluruh program dengan disiplin, menjaga kerahasiaan, dan menyelesaikan laporan PKL.</p>

<h3>Pasal 5 Perlindungan Kecelakaan Kerja</h3>
<ol>
<li>Merujuk pada Peraturan Menteri Pendidikan, Kebudayaan, Riset, dan Teknologi Nomor 12 Tahun 2024 tentang Kurikulum pada Pendidikan Anak Usia Dini, Jenjang Pendidikan Dasar, dan Jenjang Pendidikan Menengah (yang mengatur PKL sebagai mata pelajaran wahana pembelajaran di dunia kerja) serta ketentuan BPJS Ketenagakerjaan kategori Bukan Penerima Upah, PERUSAHAAN mendaftarkan SISWA pada program Jaminan Kecelakaan Kerja dan Jaminan Kematian selama masa PKL berlangsung.</li>
<li>Iuran kedua program tersebut sepenuhnya menjadi tanggungan PERUSAHAAN.</li>
<li>SISWA berhak atas manfaat medis dan santunan apabila terjadi kecelakaan kerja atau kejadian yang dijamin selama masa PKL.</li>
<li>Kepesertaan dinon-aktifkan pada saat program PKL berakhir.</li>
</ol>

<h3>Pasal 6 Sertifikat</h3>
<p>Sertifikat PKL dikeluarkan setelah program diselesaikan dengan baik oleh SISWA.</p>
HTML;
    }
}

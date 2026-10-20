<?php

/**
 * Pengaman insiden 28 Sep & 1 Okt 2026: logo 4096x3282 ditanam utuh ke PDF,
 * Imagick di DomPDF memenuhi /tmp container (tmpfs 128 MB), lalu SEMUA
 * unggahan berkas di ERP gagal ("Proof gagal diunggah").
 */

use App\Helpers;
use Illuminate\Support\Facades\Storage;

function gambarUji(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 10, 54, 61, 40));
    ob_start();
    imagepng($img);
    imagedestroy($img);

    return (string) ob_get_clean();
}

it('memperkecil logo besar di tempat, tetap PNG ber-alpha', function () {
    Storage::fake('public');
    Storage::disk('public')->put('logos/besar.png', gambarUji(4096, 3282));

    Helpers::shrinkStoredImage('public', 'logos/besar.png');

    $info = getimagesize(Storage::disk('public')->path('logos/besar.png'));
    expect($info[0])->toBe(1200)
        ->and($info[1])->toBe(962)
        ->and($info['mime'])->toBe('image/png');
});

it('membiarkan logo kecil apa adanya', function () {
    Storage::fake('public');
    $bytes = gambarUji(800, 400);
    Storage::disk('public')->put('logos/kecil.png', $bytes);

    Helpers::shrinkStoredImage('public', 'logos/kecil.png');

    expect(Storage::disk('public')->get('logos/kecil.png'))->toBe($bytes);
});

it('tidak merusak berkas yang bukan gambar', function () {
    Storage::fake('public');
    Storage::disk('public')->put('logos/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

    Helpers::shrinkStoredImage('public', 'logos/logo.svg');

    expect(Storage::disk('public')->get('logos/logo.svg'))->toContain('<svg');
});

it('DomPDF memakai folder kerja di disk, bukan /tmp', function () {
    $dir = config('dompdf.options.temp_dir');

    expect($dir)->toBe(storage_path('app/dompdf-tmp'))
        ->and(is_dir($dir))->toBeTrue();
});

it('pdf:prune-temp menghapus berkas basi saja', function () {
    $dir = sys_get_temp_dir().'/pdf-prune-'.uniqid();
    mkdir($dir);
    config(['dompdf.options.temp_dir' => $dir]);
    file_put_contents("$dir/magick-lama", 'x');
    touch("$dir/magick-lama", time() - 7200);
    file_put_contents("$dir/magick-baru", 'x');

    $this->artisan('pdf:prune-temp')->assertSuccessful();

    expect(file_exists("$dir/magick-lama"))->toBeFalse()
        ->and(file_exists("$dir/magick-baru"))->toBeTrue();

    @unlink("$dir/magick-baru");
    @rmdir($dir);
});

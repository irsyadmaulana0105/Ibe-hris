<?php

use App\Surat\Models\CompanyBank;
use App\Surat\Models\Letter;
use App\Surat\Services\HtmlSanitizerService;
use App\Surat\Services\LetterTemplateService;
use Database\Seeders\SuratSeeder;
use Illuminate\Support\Facades\File;

uses()->group('api', 'documents', 'security');

it('creates HTML purifier definition caches with private modes', function () {
    $cachePath = storage_path('framework/testing/html-purifier-permissions');
    $originalPath = config('purifier.cachePath');
    $originalMode = config('purifier.cacheFileMode');
    File::deleteDirectory($cachePath);
    config()->set('purifier.cachePath', $cachePath);
    config()->set('purifier.cacheFileMode', 0700);

    try {
        $clean = app(HtmlSanitizerService::class)->clean('<p>Private cache</p>');
        expect($clean)->toContain('Private cache')
            ->and((int) config('purifier.cacheFileMode'))->toBe(0700)
            ->and(File::isDirectory($cachePath))->toBeTrue();

        if (DIRECTORY_SEPARATOR === '/') {
            $fileCount = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            expect(fileperms($cachePath) & 0777)->toBe(0700);
            foreach ($iterator as $node) {
                if ($node->isDir()) {
                    expect($node->getPerms() & 0777)->toBe(0700);
                } else {
                    $fileCount++;
                    expect($node->isFile())->toBeTrue()
                        ->and($node->getPerms() & 0777)->toBe(0600);
                }
            }
            expect($fileCount)->toBeGreaterThan(0);
        }
    } finally {
        File::deleteDirectory($cachePath);
        config()->set('purifier.cachePath', $originalPath);
        config()->set('purifier.cacheFileMode', $originalMode);
    }
});

it('removes embedded resources and remote CSS while preserving letter structure', function () {
    $html = <<<'HTML'
<p style="color:#0f172a;background-image:url(https://tracker.example/pixel);width:80%">Safe paragraph</p>
<img src="https://tracker.example/pixel.png" alt="tracking pixel">
<style>@import url('https://tracker.example/theme.css');</style>
<table><tbody><tr><td>Preserved cell</td></tr></tbody></table>
<a href="https://docs.example.test/guide" target="_blank">Explicit navigation</a>
HTML;

    $clean = app(HtmlSanitizerService::class)->clean($html);

    expect($clean)
        ->toContain('Safe paragraph')
        ->toContain('<table>')
        ->toContain('Preserved cell')
        ->toContain('https://docs.example.test/guide')
        ->not->toContain('<img')
        ->not->toContain('<style')
        ->not->toContain('tracker.example')
        ->not->toContain('background-image')
        ->not->toContain('url(')
        ->not->toContain('@import');
});

it('escapes every scalar placeholder and keeps only the sanitized products table as rich HTML', function () {
    $letter = new Letter([
        'subject' => '<em>Quarterly offer</em>',
        'recipient_name' => '<strong>Alice & Bob</strong>',
        'body_data' => [
            'note' => '<u>scalar note</u>',
            'products' => [[
                'name' => '<em>Managed service</em>',
                'description' => '<script>not markup</script>',
                'qty' => 2,
                'unit' => '<b>bulan</b>',
                'unit_price' => 125000,
            ]],
            'tax_rate' => 0.11,
            'show_ppn' => true,
        ],
    ]);
    $letter->setRelation('author', null);
    $letter->setRelation('signer', null);
    $letter->setRelation('cosigner', null);
    $letter->setRelation('bank', null);

    $body = <<<'HTML'
<h2>{{recipient.name}}</h2>
<p>{{letter.subject}}</p>
<div>{{custom.note}}</div>
{{products.table}}
<p>{{missing.key}}</p>
<p>{{company.bank}}</p>
<p>{{company.bank.no}}</p>
<img src="https://template-tracker.example/pixel.png">
<div style="background-image:url(https://template-tracker.example/background.png)">Layout</div>
HTML;

    $rendered = app(LetterTemplateService::class)->renderBody($body, $letter);

    expect($rendered)
        ->toContain('&lt;strong&gt;Alice &amp; Bob&lt;/strong&gt;')
        ->toContain('&lt;em&gt;Quarterly offer&lt;/em&gt;')
        ->toContain('&lt;u&gt;scalar note&lt;/u&gt;')
        ->toContain('<table')
        ->toContain('&lt;em&gt;Managed service&lt;/em&gt;')
        ->toContain('&lt;script&gt;not markup&lt;/script&gt;')
        ->toContain('&lt;b&gt;bulan&lt;/b&gt;')
        ->toContain('{{ missing.key }}')
        ->toContain('Belum dikonfigurasi')
        ->not->toMatch('/\b[0-9]{8,20}\b/')
        ->not->toContain('<img')
        ->not->toContain('template-tracker.example')
        ->not->toContain('background-image')
        ->not->toContain('<script>');
});

it('does not seed a default company bank account', function () {
    $this->seed(SuratSeeder::class);

    expect(CompanyBank::query()->withoutGlobalScopes()->count())->toBe(0);
});

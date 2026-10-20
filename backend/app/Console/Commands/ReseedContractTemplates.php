<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Update contract templates dengan default terbaru - TANPA wipe row.
 * Update in-place per (company_id, employment_type) supaya template_id yang
 * sudah di-refer kontrak existing tetap valid (tidak orphan).
 *
 * Kalau template dengan employment_type baru muncul di seeder, dibuat baru.
 * Kalau template existing sudah ada, cuma body_html + title yang di-refresh.
 *
 * Usage: php artisan contracts:reseed [--force]
 */
class ReseedContractTemplates extends Command
{
    protected $signature = 'contracts:reseed {--force : Skip konfirmasi}';
    protected $description = 'Refresh contract templates dengan default terbaru (update in-place, tidak wipe)';

    public function handle(): int
    {
        $seeder = new ContractTemplateSeeder();
        $templates = $this->getAllTemplates($seeder);
        $companies = Company::all();

        $updated = 0;
        $created = 0;
        $skippedManual = 0;

        $this->info("Akan me-refresh " . count($templates) . " template default untuk " . $companies->count() . " company.");

        if (!$this->option('force')) {
            if (!$this->confirm('Lanjutkan? (in-place update, template ID tidak berubah)')) {
                $this->warn('Dibatalkan.');
                return self::SUCCESS;
            }
        }

        foreach ($companies as $company) {
            foreach ($templates as $type => $tpl) {
                $existing = ContractTemplate::where('company_id', $company->id)
                    ->where('employment_type', $type)
                    ->first();

                if ($existing) {
                    // Update in-place - template_id tidak berubah, kontrak yang refer tetap valid
                    $existing->update([
                        'title'     => $tpl['title'],
                        'body_html' => $tpl['body_html'],
                        'source_type' => 'text',
                        'is_active' => true,
                        'version'   => ($existing->version ?? 0) + 1,
                    ]);
                    $existing->variables = $existing->detectVariables();
                    $existing->save();
                    $updated++;
                } else {
                    // Baru - buat dengan variable detected
                    $new = ContractTemplate::create([
                        'company_id'      => $company->id,
                        'employment_type' => $type,
                        'title'           => $tpl['title'],
                        'body_html'       => $tpl['body_html'],
                        'source_type'     => 'text',
                        'is_active'       => true,
                        'variables'       => [],
                        'version'         => 1,
                    ]);
                    $new->variables = $new->detectVariables();
                    $new->save();
                    $created++;
                }
            }
        }

        // Health check: cari kontrak dengan template_id orphaned (pointing ke ID yang tidak ada)
        $orphanCount = DB::table('contracts')
            ->whereNotNull('contracts.template_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('contract_templates')
                    ->whereColumn('contract_templates.id', 'contracts.template_id');
            })
            ->count();

        $this->newLine();
        $this->info("Selesai:");
        $this->line("  Updated in-place : {$updated}");
        $this->line("  Created baru     : {$created}");

        if ($orphanCount > 0) {
            $this->warn("⚠  Ada {$orphanCount} kontrak dengan template_id orphan. Jalankan:");
            $this->line("     php artisan contracts:heal-orphaned-templates");
        }

        return self::SUCCESS;
    }

    private function getAllTemplates(ContractTemplateSeeder $seeder): array
    {
        // Access private allTemplates via reflection - seeder yang define template map
        $r = new \ReflectionMethod($seeder, 'allTemplates');
        $r->setAccessible(true);
        return $r->invoke($seeder);
    }
}

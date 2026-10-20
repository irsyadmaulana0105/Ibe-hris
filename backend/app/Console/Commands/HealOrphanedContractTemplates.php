<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Console\Command;

/**
 * Cari kontrak dengan template_id yang menunjuk ke template yang sudah tidak ada
 * (orphaned - akibat dari old reseed command yang wipe + reseed).
 *
 * Remap ke template current yang match employment_type (via contract_type mapping).
 *
 * Usage: php artisan contracts:heal-orphaned-templates [--dry-run]
 */
class HealOrphanedContractTemplates extends Command
{
    protected $signature = 'contracts:heal-orphaned-templates {--dry-run : Preview tanpa mutasi}';
    protected $description = 'Remap kontrak dengan template_id orphan ke template current per employment_type';

    /** Mapping contract_type → employment_type template */
    private array $typeMap = [
        'permanent'     => 'permanent',
        'probation'     => 'probation',
        'pkwt'          => 'pkwt',
        'training'      => 'training',
        'internship'    => 'intern_campus', // legacy alias
        'intern_campus' => 'intern_campus',
        'intern_hub'    => 'intern_hub',
        'pkl_smk'       => 'pkl_smk',
    ];

    public function handle(): int
    {
        $existingTemplateIds = ContractTemplate::pluck('id')->all();

        $orphans = Contract::whereNotNull('template_id')
            ->whereNotIn('template_id', $existingTemplateIds)
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Tidak ada kontrak dengan template orphan. Semua sehat.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$orphans->count()} kontrak dengan template_id orphan.");
        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN mode - tidak ada mutasi DB.');
        }

        $healed = 0;
        $skipped = 0;

        foreach ($orphans as $c) {
            $targetEmpType = $this->typeMap[$c->contract_type] ?? null;
            if (!$targetEmpType) {
                $this->line("  #{$c->id} {$c->contract_number} contract_type={$c->contract_type} → tidak ada mapping, SKIP");
                $skipped++;
                continue;
            }

            $newTpl = ContractTemplate::where('company_id', $c->company_id)
                ->where('employment_type', $targetEmpType)
                ->first();

            if (!$newTpl) {
                $this->line("  #{$c->id} {$c->contract_number} → template {$targetEmpType} tidak ada di company {$c->company_id}, SKIP");
                $skipped++;
                continue;
            }

            $oldTplId = $c->template_id;

            if (!$this->option('dry-run')) {
                $c->template_id = $newTpl->id;
                $c->save();
            }
            $this->line("  #{$c->id} {$c->contract_number} template {$oldTplId} → {$newTpl->id} ({$targetEmpType})");
            $healed++;
        }

        $this->newLine();
        $this->info("Selesai: {$healed} healed, {$skipped} skipped.");
        return self::SUCCESS;
    }
}

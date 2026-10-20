<?php

namespace App\Console\Commands;

use App\Services\CompanyContractPrivateAssetCutover;
use Illuminate\Console\Command;
use Throwable;

class CutoverCompanyContractPrivateAssets extends Command
{
    protected $signature = 'private-assets:company-contract-20260809
        {phase : plan|copy-private|remediate-missing|switch-private|purge-public|restore-public}
        {--tenant= : Limit to one numeric company ID}
        {--apply : Required for every mutating phase}
        {--uat-confirmed : Required before purge-public}
        {--evidence= : Evidence token printed by plan for an archived missing-reference tombstone}';

    protected $description = 'Safely cut company and contract assets between verified public and tenant-private storage.';

    public function handle(CompanyContractPrivateAssetCutover $cutover): int
    {
        $phase = (string) $this->argument('phase');
        if (! in_array($phase, ['plan', 'copy-private', 'remediate-missing', 'switch-private', 'purge-public', 'restore-public'], true)) {
            $this->error('Invalid phase.');

            return self::INVALID;
        }

        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && (! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1)) {
            $this->error('Tenant must be a positive numeric company ID.');

            return self::INVALID;
        }

        try {
            $report = $cutover->run(
                $phase,
                $tenantOption === null ? null : (int) $tenantOption,
                (bool) $this->option('uat-confirmed'),
                $this->option('evidence') === null ? null : (string) $this->option('evidence'),
                (bool) $this->option('apply'),
            );
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('Cutover phase failed closed. Review application logs; no paths are printed.');

            return self::FAILURE;
        }
    }
}

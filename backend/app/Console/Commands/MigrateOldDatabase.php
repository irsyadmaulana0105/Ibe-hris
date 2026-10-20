<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateOldDatabase extends Command
{
    protected $signature = 'db:migrate-old {--sql= : Path to old SQL dump file}';

    protected $description = 'Migrate users + attendances from old ibereali_absen SQL dump';

    public function handle(): int
    {
        $sqlPath = $this->option('sql')
            ?? base_path('../ibereali_absen_2026-04-17_12-45-55.sql');

        if (! file_exists($sqlPath)) {
            $this->error("SQL file not found: {$sqlPath}");
            $this->line('Usage: php artisan db:migrate-old --sql=/path/to/dump.sql');

            return 1;
        }

        $company = Company::first();
        if (! $company) {
            $this->error('No company found. Run db:seed or create a company first.');

            return 1;
        }

        $this->info("Using company: {$company->name} (id: {$company->id})");
        $this->info('Reading SQL dump…');
        $sql = file_get_contents($sqlPath);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $this->importMasterData($sql);
        $imported = $this->importUsers($sql, $company->id);
        $attendances = $this->importAttendances($sql);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('✓ Migration complete');
        $this->table(['Table', 'Imported'], [
            ['educations',  'synced'],
            ['divisions',   'synced'],
            ['job_titles',  'synced'],
            ['shifts',      'synced'],
            ['users',       $imported],
            ['attendances', $attendances],
        ]);

        return 0;
    }

    // ── Master data ─────────────────────────────────────────────────────────

    private function importMasterData(string $sql): void
    {
        $this->line('→ Importing master data (educations, divisions, job_titles, shifts)…');

        // Educations
        DB::table('educations')->truncate();
        DB::statement("
            INSERT IGNORE INTO educations (id, name, created_at, updated_at) VALUES
            (1,'SD','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (2,'SMP','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (3,'SMA','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (4,'SMK','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (5,'D1','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (6,'D2','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (7,'D3','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (8,'D4','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (9,'S1','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (10,'S2','2025-12-16 03:26:23','2025-12-16 03:26:23'),
            (11,'S3','2025-12-16 03:26:23','2025-12-16 03:26:23')
        ");

        // Divisions
        DB::table('divisions')->truncate();
        DB::statement("
            INSERT IGNORE INTO divisions (id, name, created_at, updated_at) VALUES
            (3,'Divisi Finance','2025-12-16 03:26:23','2025-12-16 03:33:59'),
            (4,'Divisi Produksi','2025-12-16 03:26:23','2025-12-16 03:34:20'),
            (5,'Divisi Personalia','2025-12-16 03:34:39','2025-12-16 03:34:49'),
            (6,'Divisi Marketing','2025-12-16 03:35:23','2025-12-16 03:35:23'),
            (7,'Divisi Kesekretariatan','2025-12-16 03:35:42','2025-12-16 03:35:42')
        ");

        // Job Titles
        DB::table('job_titles')->truncate();
        DB::statement("
            INSERT IGNORE INTO job_titles (id, name, created_at, updated_at) VALUES
            (2,'Electrical Engineer','2025-12-16 03:26:23','2026-01-26 01:26:28'),
            (3,'Magang','2025-12-16 03:26:23','2025-12-16 03:30:26'),
            (5,'Project Admin','2025-12-16 03:26:23','2026-01-27 08:54:15'),
            (6,'Electrical / Electronics Engineering','2025-12-16 03:36:11','2026-01-18 22:37:52'),
            (7,'Mechanical Design Engineer','2025-12-16 03:36:23','2026-01-18 22:38:03'),
            (8,'Finance Admin / Billing Admin','2025-12-16 03:36:33','2026-01-18 22:38:12'),
            (9,'Quality Control','2025-12-16 03:36:54','2026-01-26 01:59:07'),
            (10,'Talent Assessment & Development Specialist','2025-12-16 03:37:15','2026-01-18 22:38:27'),
            (11,'Admin & Document Control','2025-12-16 03:37:27','2026-01-18 22:38:37'),
            (12,'Marketing & Content Creation','2025-12-16 03:37:39','2026-01-18 22:38:43'),
            (13,'Embedded Systems Engineer','2025-12-16 03:38:11','2026-01-18 22:38:49'),
            (14,'Mechanical Engineer','2026-01-18 22:48:08','2026-01-18 22:48:08')
        ");

        // Shifts
        DB::table('shifts')->truncate();
        DB::statement("
            INSERT IGNORE INTO shifts (id, name, start_time, end_time, created_at, updated_at) VALUES
            (1,'Jam Efektif Kantor','08:00:00','16:00:00','2025-12-16 03:26:24','2026-03-27 02:49:49'),
            (2,'Electrical Engineer','13:00:00','21:00:00','2025-12-16 03:26:24','2026-04-13 05:53:10')
        ");

        $this->line('  ✓ Master data imported');
    }

    // ── Users ────────────────────────────────────────────────────────────────

    private function importUsers(string $sql, int|string $companyId): int
    {
        $this->line('→ Importing users…');

        if (! preg_match('/INSERT INTO `users` VALUES\s*(.*?);/s', $sql, $m)) {
            $this->warn('  No users INSERT found in SQL.');

            return 0;
        }

        // Detect which optional columns actually exist in the target DB
        $existingCols = array_column(DB::select('SHOW COLUMNS FROM users'), 'Field');
        $has = fn (string $col) => in_array($col, $existingCols);

        $rows = $this->parseValues($m[1]);
        $count = 0;

        // Column order in old SQL:
        // 0=id, 1=nip, 2=name, 3=email, 4=phone, 5=gender,
        // 6=birth_date, 7=birth_place, 8=address, 9=city,
        // 10=education_id, 11=division_id, 12=job_title_id,
        // 13=employment_status, 14=salary_monthly, 15=join_date,
        // 16=company_name, 17=company_address, 18=finance_email,
        // 19=finance_signature_path, 20=hrd_signature_path,
        // 21=password, 22=two_factor_secret, 23=two_factor_recovery_codes,
        // 24=two_factor_confirmed_at, 25=raw_password, 26=group,
        // 27=email_verified_at, 28=profile_photo_path,
        // 29=remember_token, 30=created_at, 31=updated_at

        foreach ($rows as $r) {
            $group = $r[26] ?? 'user';
            if (! in_array($group, ['user', 'admin', 'superadmin', 'finance'])) {
                $group = 'user';
            }

            $data = [
                'id' => $r[0],
                'company_id' => $companyId,
                'nip' => $this->nullIfEmpty($r[1] ?? null),
                'name' => $r[2],
                'email' => $r[3],
                'phone' => $r[4] ?: '-',
                'gender' => in_array($r[5], ['male', 'female']) ? $r[5] : 'male',
                'birth_date' => $this->nullIfEmpty($r[6] ?? null),
                'birth_place' => $this->nullIfEmpty($r[7] ?? null),
                'address' => $r[8] ?: '-',
                'city' => $r[9] ?: '-',
                'education_id' => $this->nullIfEmpty($r[10] ?? null),
                'division_id' => $this->nullIfEmpty($r[11] ?? null),
                'job_title_id' => $this->nullIfEmpty($r[12] ?? null),
                'employment_status' => $this->nullIfEmpty($r[13] ?? null),
                'salary_monthly' => $this->nullIfEmpty($r[14] ?? null),
                'join_date' => $this->nullIfEmpty($r[15] ?? null),
                'company_name' => $this->nullIfEmpty($r[16] ?? null),
                'company_address' => $this->nullIfEmpty($r[17] ?? null),
                'finance_email' => $this->nullIfEmpty($r[18] ?? null),
                'finance_signature_path' => $this->nullIfEmpty($r[19] ?? null),
                'hrd_signature_path' => $this->nullIfEmpty($r[20] ?? null),
                'password' => $r[21],
                'two_factor_secret' => $this->nullIfEmpty($r[22] ?? null),
                'two_factor_recovery_codes' => $this->nullIfEmpty($r[23] ?? null),
                'two_factor_confirmed_at' => $this->nullIfEmpty($r[24] ?? null),
                'group' => $group,
                'email_verified_at' => $this->nullIfEmpty($r[27] ?? null),
                'profile_photo_path' => $this->nullIfEmpty($r[28] ?? null),
                'remember_token' => $this->nullIfEmpty($r[29] ?? null),
                'created_at' => $r[30] ?? now(),
                'updated_at' => $r[31] ?? now(),
            ];

            // Only add optional columns if they exist in target schema
            if ($has('raw_password')) {
                $data['raw_password'] = $this->nullIfEmpty($r[25] ?? null);
            }
            if ($has('face_descriptor')) {
                $data['face_descriptor'] = null;
            }
            if ($has('face_descriptor_pending')) {
                $data['face_descriptor_pending'] = null;
            }

            // Remove any keys not in target schema
            $data = array_filter($data, fn ($k) => $has($k), ARRAY_FILTER_USE_KEY);

            DB::table('users')->upsert($data, ['id'], array_keys($data));
            $count++;
        }

        $this->line("  ✓ {$count} users imported");

        return $count;
    }

    // ── Attendances ──────────────────────────────────────────────────────────

    private function importAttendances(string $sql): int
    {
        $this->line('→ Importing attendances…');

        if (! preg_match('/INSERT INTO `attendances` VALUES\s*(.*?);/s', $sql, $m)) {
            $this->warn('  No attendances INSERT found in SQL.');

            return 0;
        }

        $rows = $this->parseValues($m[1]);
        $batch = [];
        $count = 0;

        // Column order (same in old & new):
        // 0=id, 1=user_id, 2=barcode_id, 3=date, 4=time_in, 5=time_out,
        // 6=shift_id, 7=latitude, 8=longitude, 9=status, 10=note,
        // 11=attachment, 12=selfie_in_path, 13=selfie_out_path,
        // 14=selfie_in_latitude, 15=selfie_in_longitude,
        // 16=selfie_out_latitude, 17=selfie_out_longitude,
        // 18=selfie_in_taken_at, 19=selfie_in_address,
        // 20=selfie_out_address, 21=selfie_out_taken_at,
        // 22=created_at, 23=updated_at

        foreach ($rows as $r) {
            // Legacy dumps never authorize a public-media write. Persist only a
            // non-reversible fingerprint and an unavailable marker; operators
            // must run the evidence-bound private-media import/cutover instead.
            $attachmentReference = $this->nullIfEmpty($r[11] ?? null);
            $selfieInReference = $this->nullIfEmpty($r[12] ?? null);
            $selfieOutReference = $this->nullIfEmpty($r[13] ?? null);
            $batch[] = [
                'id' => $r[0],
                'user_id' => $r[1],
                'barcode_id' => $this->nullIfEmpty($r[2] ?? null),
                'date' => $r[3],
                'time_in' => $this->nullIfEmpty($r[4] ?? null),
                'time_out' => $this->nullIfEmpty($r[5] ?? null),
                'shift_id' => $this->nullIfEmpty($r[6] ?? null),
                'latitude' => $this->nullIfEmpty($r[7] ?? null),
                'longitude' => $this->nullIfEmpty($r[8] ?? null),
                'status' => $r[9] ?? 'present',
                'note' => $this->nullIfEmpty($r[10] ?? null),
                'attachment' => null,
                'attachment_unavailable_reason' => $attachmentReference ? 'legacy_import_requires_private_media_cutover' : null,
                'attachment_reference_fingerprint' => $attachmentReference ? hash('sha256', $attachmentReference) : null,
                'selfie_in_path' => null,
                'selfie_in_unavailable_reason' => $selfieInReference ? 'legacy_import_requires_private_media_cutover' : null,
                'selfie_in_reference_fingerprint' => $selfieInReference ? hash('sha256', $selfieInReference) : null,
                'selfie_out_path' => null,
                'selfie_out_unavailable_reason' => $selfieOutReference ? 'legacy_import_requires_private_media_cutover' : null,
                'selfie_out_reference_fingerprint' => $selfieOutReference ? hash('sha256', $selfieOutReference) : null,
                'selfie_in_latitude' => $this->nullIfEmpty($r[14] ?? null),
                'selfie_in_longitude' => $this->nullIfEmpty($r[15] ?? null),
                'selfie_out_latitude' => $this->nullIfEmpty($r[16] ?? null),
                'selfie_out_longitude' => $this->nullIfEmpty($r[17] ?? null),
                'selfie_in_taken_at' => $this->nullIfEmpty($r[18] ?? null),
                'selfie_in_address' => $this->nullIfEmpty($r[19] ?? null),
                'selfie_out_address' => $this->nullIfEmpty($r[20] ?? null),
                'selfie_out_taken_at' => $this->nullIfEmpty($r[21] ?? null),
                'created_at' => $r[22] ?? now(),
                'updated_at' => $r[23] ?? now(),
            ];

            if (count($batch) >= 200) {
                DB::table('attendances')->upsert($batch, ['id'], array_keys($batch[0]));
                $count += count($batch);
                $batch = [];
                $this->output->write('.');
            }
        }

        if (! empty($batch)) {
            DB::table('attendances')->upsert($batch, ['id'], array_keys($batch[0]));
            $count += count($batch);
        }

        $this->newLine();
        $this->line("  ✓ {$count} attendance records imported");

        return $count;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Parse SQL VALUES tuples into arrays of scalar values.
     * Handles NULL, quoted strings with escaped quotes, and numbers.
     */
    private function parseValues(string $valuesStr): array
    {
        $rows = [];
        $i = 0;
        $len = strlen($valuesStr);

        while ($i < $len) {
            // Skip whitespace and commas between tuples
            while ($i < $len && in_array($valuesStr[$i], [' ', "\n", "\r", "\t", ','])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            if ($valuesStr[$i] !== '(') {
                $i++;

                continue;
            }

            $i++; // skip '('
            $row = [];

            while ($i < $len && $valuesStr[$i] !== ')') {
                // skip whitespace
                while ($i < $len && in_array($valuesStr[$i], [' ', "\t"])) {
                    $i++;
                }

                if ($i >= $len) {
                    break;
                }

                if ($valuesStr[$i] === "'") {
                    // Quoted string
                    $i++; // skip opening quote
                    $val = '';
                    while ($i < $len) {
                        if ($valuesStr[$i] === '\\') {
                            $i++;
                            $val .= match ($valuesStr[$i] ?? '') {
                                'n' => "\n",
                                'r' => "\r",
                                't' => "\t",
                                "'" => "'",
                                '\\' => '\\',
                                default => '\\'.($valuesStr[$i] ?? ''),
                            };
                            $i++;
                        } elseif ($valuesStr[$i] === "'") {
                            // Check for escaped quote ''
                            if (isset($valuesStr[$i + 1]) && $valuesStr[$i + 1] === "'") {
                                $val .= "'";
                                $i += 2;
                            } else {
                                $i++; // closing quote
                                break;
                            }
                        } else {
                            $val .= $valuesStr[$i++];
                        }
                    }
                    $row[] = $val;
                } elseif (strtoupper(substr($valuesStr, $i, 4)) === 'NULL') {
                    $row[] = null;
                    $i += 4;
                } else {
                    // Number or bare word
                    $val = '';
                    while ($i < $len && ! in_array($valuesStr[$i], [',', ')', ' ', "\t", "\n"])) {
                        $val .= $valuesStr[$i++];
                    }
                    $row[] = $val === '' ? null : $val;
                }

                // Skip comma between values
                while ($i < $len && in_array($valuesStr[$i], [' ', "\t"])) {
                    $i++;
                }
                if ($i < $len && $valuesStr[$i] === ',') {
                    $i++;
                }
            }

            if ($i < $len && $valuesStr[$i] === ')') {
                $i++;
            } // skip ')'

            if (! empty($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function nullIfEmpty(mixed $val): mixed
    {
        if ($val === null || $val === '' || $val === 'NULL') {
            return null;
        }

        return $val;
    }
}

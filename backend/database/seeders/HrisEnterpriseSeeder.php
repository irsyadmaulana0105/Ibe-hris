<?php

namespace Database\Seeders;

use App\Models\BackupConfig;
use App\Models\Company;
use App\Models\CompanyTheme;
use App\Models\Course;
use App\Models\Integration;
use App\Models\LeaveHoliday;
use App\Models\LeavePolicyGlobal;
use App\Models\LeavePolicyQuota;
use App\Models\NotificationTemplate;
use App\Models\PayrollFormulaComponent;
use App\Models\RbacCapability;
use App\Models\RbacRole;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HrisEnterpriseSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company) {
            $this->seedForCompany($company->id);
        });
    }

    private function seedForCompany(int $companyId): void
    {
        // RBAC roles
        $roles = [
            ['key' => 'superadmin', 'label' => 'Superadmin', 'color' => '#ef4444', 'description' => 'Akses penuh - owner / tech lead'],
            ['key' => 'admin', 'label' => 'Admin HR', 'color' => '#1a7a8c', 'description' => 'HR Operasional'],
            ['key' => 'finance', 'label' => 'Finance', 'color' => '#f59e0b', 'description' => 'Payroll, reimburse, kasbon approval'],
            ['key' => 'user', 'label' => 'Karyawan', 'color' => '#22c55e', 'description' => 'Karyawan tetap/kontrak'],
            ['key' => 'intern', 'label' => 'Magang', 'color' => '#3b82f6', 'description' => 'Anak magang'],
        ];
        foreach ($roles as $r) {
            RbacRole::query()->firstOrCreate(
                ['company_id' => $companyId, 'key' => $r['key']],
                [...$r, 'is_builtin' => true],
            );
        }

        // RBAC capabilities
        $caps = [
            ['Karyawan', 'employees.view'],
            ['Karyawan', 'employees.manage'],
            ['Absensi', 'attendance.view'],
            ['Absensi', 'attendance.manage'],
            ['Cuti', 'leave.submit'],
            ['Cuti', 'leave.approve'],
            ['Lembur', 'overtime.submit'],
            ['Lembur', 'overtime.approve'],
            ['Payroll', 'payroll.view_self'],
            ['Payroll', 'payroll.manage'],
            ['Reimburse', 'reimburse.submit'],
            ['Reimburse', 'reimburse.approve'],
            ['Kasbon', 'salary_advance.submit'],
            ['Kasbon', 'salary_advance.approve'],
            ['Master Data', 'master_data.manage'],
            ['Master Data', 'company.manage'],
            ['Audit', 'audit.view'],
            ['RBAC', 'rbac.manage'],
            ['Learning', 'learning.manage'],
            ['Performance', 'performance.manage'],
            ['Recruitment', 'recruitment.manage'],
        ];
        foreach ($caps as [$module, $key]) {
            RbacCapability::query()->firstOrCreate(
                ['company_id' => $companyId, 'capability_key' => $key],
                ['module' => $module],
            );
        }

        // Default role-capability matrix
        $defaultMatrix = [
            'employees.view'         => ['superadmin', 'admin', 'user', 'intern'],
            'employees.manage'       => ['superadmin', 'admin'],
            'attendance.view'        => ['superadmin', 'admin', 'finance', 'user', 'intern'],
            'attendance.manage'      => ['superadmin', 'admin'],
            'leave.submit'           => ['superadmin', 'user', 'intern'],
            'leave.approve'          => ['superadmin', 'admin'],
            'overtime.submit'        => ['superadmin', 'user'],
            'overtime.approve'       => ['superadmin', 'admin'],
            'payroll.view_self'      => ['superadmin', 'finance', 'user', 'intern'],
            'payroll.manage'         => ['superadmin', 'finance'],
            'reimburse.submit'       => ['superadmin', 'user'],
            'reimburse.approve'      => ['superadmin', 'finance'],
            'salary_advance.submit'  => ['superadmin', 'user'],
            'salary_advance.approve' => ['superadmin', 'finance'],
            'master_data.manage'     => ['superadmin', 'admin'],
            'company.manage'         => ['superadmin'],
            'audit.view'             => ['superadmin'],
            'rbac.manage'            => ['superadmin'],
            'learning.manage'        => ['superadmin', 'admin'],
            'performance.manage'     => ['superadmin', 'admin'],
            'recruitment.manage'     => ['superadmin', 'admin'],
        ];

        $roleIds = RbacRole::query()->where('company_id', $companyId)->pluck('id', 'key');
        $capIds = RbacCapability::query()->where('company_id', $companyId)->pluck('id', 'capability_key');

        foreach ($defaultMatrix as $capKey => $roleKeys) {
            $capId = $capIds[$capKey] ?? null;
            if (!$capId) continue;
            foreach ($roleKeys as $rk) {
                $rid = $roleIds[$rk] ?? null;
                if (!$rid) continue;
                DB::table('rbac_role_capability')->updateOrInsert(
                    ['role_id' => $rid, 'capability_id' => $capId],
                    [],
                );
            }
        }

        // Leave Quotas
        $quotas = [
            ['type_key' => 'annual', 'name' => 'Cuti Tahunan', 'permanent' => 12, 'contract' => 12, 'intern' => 0],
            ['type_key' => 'sick', 'name' => 'Cuti Sakit', 'permanent' => 14, 'contract' => 14, 'intern' => 7],
            ['type_key' => 'maternity', 'name' => 'Cuti Melahirkan', 'permanent' => 90, 'contract' => 90, 'intern' => 0],
            ['type_key' => 'paternity', 'name' => 'Cuti Ayah', 'permanent' => 2, 'contract' => 2, 'intern' => 0],
            ['type_key' => 'wedding', 'name' => 'Cuti Menikah', 'permanent' => 3, 'contract' => 3, 'intern' => 0],
            ['type_key' => 'bereavement', 'name' => 'Cuti Duka', 'permanent' => 2, 'contract' => 2, 'intern' => 1],
            ['type_key' => 'hajj', 'name' => 'Cuti Haji', 'permanent' => 40, 'contract' => 0, 'intern' => 0],
        ];
        foreach ($quotas as $q) {
            LeavePolicyQuota::query()->firstOrCreate(
                ['company_id' => $companyId, 'type_key' => $q['type_key']],
                $q,
            );
        }

        // Holidays 2026
        $holidays = [
            '2026-01-01' => 'Tahun Baru Masehi',
            '2026-01-29' => 'Isra Mi\'raj',
            '2026-03-17' => 'Nyepi',
            '2026-04-03' => 'Wafat Isa Almasih',
            '2026-04-19' => 'Idul Fitri 1447 H',
            '2026-04-20' => 'Idul Fitri (Hari 2)',
            '2026-05-01' => 'Hari Buruh',
            '2026-05-14' => 'Kenaikan Isa Almasih',
            '2026-06-01' => 'Hari Lahir Pancasila',
            '2026-06-26' => 'Idul Adha',
            '2026-07-17' => 'Tahun Baru Islam',
            '2026-08-17' => 'HUT RI',
            '2026-09-25' => 'Maulid Nabi',
            '2026-12-25' => 'Natal',
        ];
        foreach ($holidays as $date => $name) {
            LeaveHoliday::query()->firstOrCreate(
                ['company_id' => $companyId, 'date' => $date, 'name' => $name],
                ['type' => 'Libur Nasional'],
            );
        }
        LeavePolicyGlobal::query()->firstOrCreate(['company_id' => $companyId]);

        // Payroll Formula
        $components = [
            ['Gaji Pokok', 'earning', '{basic_salary}', 'gross', true, 10],
            ['Tunjangan Transport', 'earning', '{basic_salary} * 0.1', 'gross', true, 20],
            ['Tunjangan Makan', 'earning', '{working_days} * 25000', 'gross', true, 30],
            ['Tunjangan Hari Raya', 'earning', '{basic_salary}', 'gross', true, 40],
            ['BPJS Kesehatan (Employee)', 'deduction', '{gross} * 0.01', 'gross', false, 100],
            ['BPJS JHT (Employee)', 'deduction', '{basic_salary} * 0.02', 'gross', false, 110],
            ['BPJS JP (Employee)', 'deduction', '{basic_salary} * 0.01', 'gross', false, 120],
            ['PPh21', 'deduction', 'progressive({taxable_income})', 'gross', false, 200],
        ];
        foreach ($components as [$name, $category, $formula, $method, $taxable, $sort]) {
            PayrollFormulaComponent::query()->firstOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['category' => $category, 'formula' => $formula, 'method' => $method, 'taxable' => $taxable, 'sort_order' => $sort],
            );
        }

        // Workflows
        $workflows = [
            'leave' => ['name' => 'Permohonan Cuti', 'steps' => [
                ['Karyawan', 'submit', '#64748b'],
                ['Direct Manager', 'approve', '#1a7a8c'],
                ['HR Admin', 'finalize', '#a855f7'],
            ]],
            'overtime' => ['name' => 'Lembur', 'steps' => [
                ['Karyawan', 'submit', '#64748b'],
                ['Direct Manager', 'approve', '#1a7a8c'],
                ['HR Admin', 'finalize', '#a855f7'],
            ]],
            'reimburse' => ['name' => 'Reimburse', 'steps' => [
                ['Karyawan', 'submit', '#64748b'],
                ['Direct Manager', 'verify', '#1a7a8c'],
                ['Finance', 'approve', '#22c55e'],
                ['Finance Payout', 'pay', '#f59e0b'],
            ]],
            'kasbon' => ['name' => 'Kasbon', 'steps' => [
                ['Karyawan', 'submit', '#64748b'],
                ['HR Verify', 'verify', '#1a7a8c'],
                ['Finance Approve', 'approve', '#22c55e'],
            ]],
        ];
        foreach ($workflows as $key => $wf) {
            $def = WorkflowDefinition::query()->firstOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                ['name' => $wf['name']],
            );
            // Only seed steps if none exist
            if ($def->steps()->count() === 0) {
                foreach ($wf['steps'] as $idx => [$label, $role, $color]) {
                    WorkflowStep::create([
                        'workflow_definition_id' => $def->id,
                        'label' => $label, 'role' => $role, 'color' => $color,
                        'sort_order' => ($idx + 1) * 10,
                    ]);
                }
            }
        }

        // Notification Templates
        $notifs = [
            ['leave_approved',     'Cuti Disetujui',         true, true,  false, 'Halo {{user_name}}, permohonan cuti tanggal {{start_date}} s.d. {{end_date}} telah DISETUJUI oleh {{approver_name}}. Selamat menikmati cuti! Catatan: {{notes}}'],
            ['leave_rejected',     'Cuti Ditolak',           true, true,  false, 'Halo {{user_name}}, permohonan cuti tanggal {{start_date}} s.d. {{end_date}} DITOLAK. Alasan: {{rejection_reason}}'],
            ['overtime_approved',  'Lembur Disetujui',       true, false, true,  'Halo {{user_name}}, lembur tanggal {{date}} ({{hours}} jam) telah DISETUJUI. Akan dibayarkan di payroll bulan berjalan.'],
            ['overtime_rejected',  'Lembur Ditolak',         true, false, false, 'Halo {{user_name}}, pengajuan lembur tanggal {{date}} DITOLAK. Alasan: {{rejection_reason}}'],
            ['reimburse_approved', 'Reimburse Disetujui',    true, true,  false, 'Halo {{user_name}}, reimburse sebesar Rp {{amount}} telah DISETUJUI Finance. Pencairan dalam 3 hari kerja.'],
            ['reimburse_rejected', 'Reimburse Ditolak',      true, true,  false, 'Halo {{user_name}}, pengajuan reimburse DITOLAK. Alasan: {{rejection_reason}}'],
            ['kasbon_approved',    'Kasbon Disetujui',       true, true,  false, 'Halo {{user_name}}, pengajuan kasbon Rp {{amount}} DISETUJUI. Pencairan via transfer dalam 1-2 hari kerja.'],
            ['kasbon_rejected',    'Kasbon Ditolak',         true, true,  false, 'Halo {{user_name}}, pengajuan kasbon Rp {{amount}} DITOLAK. Alasan: {{rejection_reason}}'],
            ['payroll_approved',   'Slip Gaji Disetujui HR', true, false, false, 'Halo {{user_name}}, slip gaji periode {{period}} sudah final dan akan ditransfer Finance. Take home: Rp {{net_pay}}.'],
            ['payroll_ready',      'Slip Gaji Sudah Cair',   true, false, false, 'Halo {{user_name}}, gaji periode {{period}} sudah DITRANSFER. Take home: Rp {{net_pay}}. Login portal untuk download slip PDF.'],
            ['birthday',           'Ulang Tahun',            false, true, true,  '🎉 Selamat ulang tahun {{user_name}}! Semoga sehat, sukses, dan bahagia selalu. - Tim IBE Reality'],
            ['contract_expiring',  'Kontrak Akan Habis',     true, false, false, 'Halo HR, kontrak {{user_name}} akan berakhir tanggal {{end_date}} ({{days_left}} hari lagi). Mohon segera ditindaklanjuti.'],
        ];
        foreach ($notifs as [$key, $label, $em, $wa, $sl, $body]) {
            NotificationTemplate::query()->firstOrCreate(
                ['company_id' => $companyId, 'event_key' => $key],
                ['event_label' => $label, 'email_enabled' => $em, 'whatsapp_enabled' => $wa, 'slack_enabled' => $sl, 'body' => $body],
            );
        }

        // Integrations (slots only, no credentials)
        $integrations = [
            ['whatsapp', 'WhatsApp Business', 'communication', '💬', 'API Token', 'Kirim notifikasi cuti, slip gaji via WA.'],
            ['slack', 'Slack', 'communication', '🔔', 'Webhook URL', 'Channel notification untuk @hr, @finance.'],
            ['gcal', 'Google Calendar', 'calendar', '📅', 'OAuth Client ID', 'Auto-create interview event.'],
            ['peruri', 'e-Meterai Peruri', 'legal', '📜', 'Merchant Key', 'e-meterai Rp 10.000 otomatis.'],
            ['openai', 'OpenAI (GPT-4o)', 'ai', '🤖', 'API Key (sk-…)', 'CV parser, AI helpers.'],
            ['s3', 'AWS S3', 'storage', '☁️', 'Access Key ID', 'Backup ke S3.'],
            ['stalwart', 'Stalwart Mail Server', 'communication', '✉️', 'SMTP Host', 'SMTP/IMAP internal.'],
        ];
        foreach ($integrations as [$key, $name, $cat, $icon, $field, $desc]) {
            Integration::query()->firstOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                ['name' => $name, 'category' => $cat, 'icon' => $icon, 'field_label' => $field, 'description' => $desc, 'status' => 'disconnected'],
            );
        }

        // Backup config + theme
        BackupConfig::query()->firstOrCreate(['company_id' => $companyId]);
        CompanyTheme::query()->firstOrCreate(['company_id' => $companyId]);

        // Sample courses (mandatory + optional)
        $courses = [
            ['Onboarding IBE Reality 2026', 'Onboarding', 'Internal HR', 4, 'internal', null, true],
            ['Security Awareness & PII Protection', 'Compliance', 'Internal IT', 2, 'internal', null, true],
            ['Effective Communication for Leaders', 'Leadership', 'LinkedIn Learning', 6, 'external', 'https://www.linkedin.com/learning/', false],
            ['TypeScript Fundamentals', 'Technical', 'Udemy', 12, 'external', 'https://www.udemy.com/', false],
            ['BPJS Ketenagakerjaan Compliance 2026', 'Compliance', 'BPJS Webinar', 3, 'external', null, true],
        ];
        foreach ($courses as [$title, $cat, $provider, $hours, $type, $url, $mandatory]) {
            Course::query()->firstOrCreate(
                ['company_id' => $companyId, 'title' => $title],
                ['category' => $cat, 'provider' => $provider, 'hours' => $hours, 'type' => $type, 'url' => $url, 'mandatory' => $mandatory],
            );
        }
    }
}

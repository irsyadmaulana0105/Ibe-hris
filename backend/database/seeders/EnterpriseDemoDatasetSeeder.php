<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Bom;
use App\Models\BomComponent;
use App\Models\BusinessTrip;
use App\Models\Candidate;
use App\Models\ClientVisit;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Complaint;
use App\Models\Contract;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Goal;
use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use App\Models\JobPosting;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\PerformanceReview;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Models\SalaryAdvance;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Task;
use App\Models\TreasuryBankAccount;
use App\Models\TreasuryCashTransaction;
use App\Models\TreasuryPayment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Versioned, deterministic, and idempotent synthetic dataset for the isolated
 * enterprise demo tenant. Never run this seeder in local/staging/production.
 */
class EnterpriseDemoDatasetSeeder extends Seeder
{
    private Company $company;

    private User $superadmin;

    private User $hr;

    private User $finance;

    private User $manager;

    private User $production;

    private User $projectManager;

    private string $password;

    public function run(): void
    {
        if (app()->environment() !== 'demo') {
            throw new RuntimeException('EnterpriseDemoDatasetSeeder may only run with APP_ENV=demo.');
        }

        $this->password = (string) env('DEMO_SEED_PASSWORD');
        if (strlen($this->password) < 14 || str_starts_with($this->password, 'CHANGE_ME')) {
            throw new RuntimeException('A non-placeholder DEMO_SEED_PASSWORD of at least 14 characters is required.');
        }

        $this->company = Company::query()->where('slug', 'demo-enterprise')->firstOrFail();
        $this->superadmin = $this->persona('superadmin');
        $this->hr = $this->persona('hr');
        $this->finance = $this->persona('finance');
        $this->manager = $this->persona('manager');
        $this->production = $this->persona('production');
        $this->projectManager = $this->persona('project');

        DB::transaction(function (): void {
            $employees = $this->seedWorkforce();
            $this->seedPeopleTransactions($employees);
            $projects = $this->seedProjects($employees);
            $products = $this->seedSupplyChain($projects);
            $this->seedCommercial($products);
            $this->seedTreasury();
            $this->seedSupportModules($employees);
        });

        $this->command?->info('Enterprise demo dataset loaded: workforce, HR flows, projects/RAB, CRM, finance, procurement, production, warehouse, and distribution.');
    }

    private function persona(string $key): User
    {
        return User::query()->where('company_id', $this->company->id)
            ->where('email', "enterprise.{$key}@demo.ibereality.com")
            ->firstOrFail();
    }

    /** @return array<int, User> */
    private function seedWorkforce(): array
    {
        $divisionIds = DB::table('divisions')->orderBy('id')->pluck('id')->values();
        $jobIds = DB::table('job_titles')->orderBy('id')->pluck('id')->values();
        $shiftId = DB::table('shifts')->orderBy('id')->value('id');
        $names = [
            ['Aditya Pranata', 'male', 'Sales Executive'], ['Nabila Maharani', 'female', 'Marketing Specialist'],
            ['Rizky Ramadhan', 'male', 'Procurement Officer'], ['Siti Rahmawati', 'female', 'Finance Analyst'],
            ['Dimas Saputro', 'male', 'Warehouse Supervisor'], ['Aulia Putri', 'female', 'HR Generalist'],
            ['Fajar Nugroho', 'male', 'Production Planner'], ['Citra Lestari', 'female', 'Quality Engineer'],
            ['Bagus Haryanto', 'male', 'Electrical Engineer'], ['Nadia Kusuma', 'female', 'Project Controller'],
            ['Yusuf Setiawan', 'male', 'Field Technician'], ['Maya Anggraini', 'female', 'Document Controller'],
            ['Arief Wibowo', 'male', 'Account Executive'], ['Dewi Kartika', 'female', 'Customer Success'],
            ['Galih Prasetyo', 'male', 'Logistics Coordinator'], ['Intan Permata', 'female', 'Payroll Officer'],
            ['Hendra Wijaya', 'male', 'Mechanical Engineer'], ['Larasati Ayu', 'female', 'Business Analyst'],
            ['Kevin Santoso', 'male', 'Software Engineer'], ['Nurul Hidayah', 'female', 'Recruitment Officer'],
            ['Bima Arya', 'male', 'Inventory Staff'], ['Putri Amelia', 'female', 'Sales Admin'],
            ['Raka Mahendra', 'male', 'Production Operator'], ['Selvi Oktavia', 'female', 'Intern'],
        ];

        $employees = [];
        foreach ($names as $i => [$name, $gender, $title]) {
            $seq = $i + 20;
            $employees[] = User::query()->updateOrCreate(
                ['email' => sprintf('enterprise.employee%02d@demo.ibereality.com', $seq)],
                [
                    'company_id' => $this->company->id,
                    'nip' => sprintf('DEMO-ENT-%03d', $seq),
                    'employee_number' => sprintf('DMENT%03d', $seq),
                    'name' => $name,
                    'password' => Hash::make($this->password),
                    'group' => $title === 'Intern' ? 'user' : ($i === 3 || $i === 15 ? 'finance' : 'user'),
                    'phone' => sprintf('08129010%04d', $seq),
                    'gender' => $gender,
                    'marital_status' => $i % 3 === 0 ? 'married' : 'single',
                    'birth_date' => CarbonImmutable::create(1992 + ($i % 9), ($i % 12) + 1, ($i % 24) + 1),
                    'birth_place' => ['Surabaya', 'Sidoarjo', 'Malang', 'Gresik'][$i % 4],
                    'address' => 'Alamat sintetis karyawan demo '.($i + 1),
                    'city' => 'Sidoarjo',
                    'division_id' => $divisionIds->isNotEmpty() ? $divisionIds[$i % $divisionIds->count()] : null,
                    'job_title_id' => $jobIds->isNotEmpty() ? $jobIds[$i % $jobIds->count()] : null,
                    'default_shift_id' => $shiftId,
                    'attendance_location_mode' => in_array($title, ['Sales Executive', 'Account Executive'], true)
                        ? 'mobile_field'
                        : 'office_geofence',
                    'employment_status' => $title === 'Intern' ? 'intern_campus' : ($i % 6 === 0 ? 'probation' : 'permanent'),
                    'salary_monthly' => 5_500_000 + (($i % 8) * 750_000),
                    'daily_rate' => 250_000 + (($i % 5) * 25_000),
                    'meal_transport_rate' => 50_000,
                    'overtime_rate' => 45_000,
                    'attendance_allowance' => 300_000,
                    'late_deduction' => 25_000,
                    'absent_deduction' => 200_000,
                    'leave_deduction' => 0,
                    'bonus_rate' => 250_000,
                    'annual_leave_balance' => max(0, 12 - ($i % 5)),
                    'bank_account' => sprintf('88002026%06d', $seq),
                    'join_date' => CarbonImmutable::create(2021 + ($i % 5), ($i % 12) + 1, 1),
                    'email_verified_at' => now(),
                ],
            );
        }

        return $employees;
    }

    /** @param array<int, User> $employees */
    private function seedPeopleTransactions(array $employees): void
    {
        $today = CarbonImmutable::today();
        $shiftId = DB::table('shifts')->orderBy('id')->value('id');
        $barcodeId = DB::table('barcodes')->orderBy('id')->value('id');

        foreach ($employees as $idx => $employee) {
            for ($offset = 29; $offset >= 0; $offset--) {
                $date = $today->subDays($offset);
                if ($date->isWeekend()) {
                    continue;
                }
                $late = (($idx + $offset) % 11 === 0) ? 18 : 0;
                Attendance::query()->updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $date->toDateString()],
                    [
                        'barcode_id' => $barcodeId,
                        'shift_id' => $shiftId,
                        'time_in' => $date->setTime(8, $late)->format('H:i:s'),
                        'time_out' => $date->setTime(17, ($idx + $offset) % 20)->format('H:i:s'),
                        'latitude' => '-7.4478000', 'longitude' => '112.7183000',
                        'status' => $late ? 'late' : 'present', 'late_minutes' => $late,
                        'note' => 'Kehadiran sintetis untuk demo',
                    ],
                );
            }

            if ($idx < 12) {
                $status = ['pending', 'approved', 'rejected'][$idx % 3];
                Leave::query()->updateOrCreate(
                    ['company_id' => $this->company->id, 'user_id' => $employee->id, 'start_date' => $today->addDays(7 + $idx)->toDateString()],
                    ['leave_type' => ['annual', 'sick', 'personal'][$idx % 3], 'end_date' => $today->addDays(7 + $idx)->toDateString(), 'days_count' => 1, 'reason' => 'Keperluan sintetis demonstrasi', 'status' => $status, 'notes' => $status === 'rejected' ? 'Jadwal tim perlu disesuaikan' : null, 'approved_by' => $status === 'pending' ? null : $this->hr->id, 'approved_at' => $status === 'pending' ? null : now()->subDays(1)],
                );
            }

            if ($idx < 10) {
                $status = ['pending', 'approved', 'approved', 'rejected'][$idx % 4];
                Overtime::query()->updateOrCreate(
                    ['company_id' => $this->company->id, 'user_id' => $employee->id, 'date' => $today->subDays($idx + 2)->toDateString()],
                    ['check_in_time' => '17:15:00', 'check_out_time' => '20:15:00', 'total_hours' => 3, 'reason' => 'Penyelesaian milestone demo', 'status' => $status, 'approved_by' => $status === 'pending' ? null : $this->manager->id, 'approved_at' => $status === 'pending' ? null : now()->subDay()],
                );
            }
        }

        $categories = [
            ['Transportasi', 1_000_000], ['Akomodasi', 3_000_000], ['Konsumsi', 500_000], ['Peralatan Kerja', 5_000_000],
        ];
        $categoryModels = [];
        foreach ($categories as [$name, $limit]) {
            $categoryModels[] = ReimbursementCategory::query()->firstOrCreate(['company_id' => $this->company->id, 'name' => $name], ['limit_amount' => $limit, 'is_active' => true]);
        }

        foreach (array_slice($employees, 0, 12) as $idx => $employee) {
            $status = ['pending', 'hr_approved', 'approved', 'settled'][$idx % 4];
            $amount = 125_000 + ($idx * 75_000);
            Reimbursement::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'user_id' => $employee->id, 'event' => 'Biaya operasional demo #'.($idx + 1)],
                ['category_id' => $categoryModels[$idx % count($categoryModels)]->id, 'date' => $today->subDays($idx + 1), 'unit_price' => $amount, 'quantity' => 1, 'total_amount' => $amount, 'approved_amount' => in_array($status, ['approved', 'settled'], true) ? $amount : null, 'status' => $status, 'bank_name' => 'Bank Demo Indonesia', 'bank_account_number' => $employee->bank_account, 'bank_account_holder' => $employee->name, 'hr_approved_by' => $status === 'pending' ? null : $this->hr->id, 'hr_approved_at' => $status === 'pending' ? null : now()->subDay(), 'approved_by' => in_array($status, ['approved', 'settled'], true) ? $this->finance->id : null, 'approved_at' => in_array($status, ['approved', 'settled'], true) ? now()->subHours(12) : null, 'settled_by' => $status === 'settled' ? $this->finance->id : null, 'settled_at' => $status === 'settled' ? now()->subHours(4) : null, 'payment_reference' => $status === 'settled' ? sprintf('TRX-DEMO-%04d', $idx + 1) : null],
            );
        }

        foreach (array_slice($employees, 3, 8) as $idx => $employee) {
            $status = ['pending', 'approved', 'approved', 'paid'][$idx % 4];
            SalaryAdvance::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'user_id' => $employee->id, 'date' => $today->subDays($idx + 3)],
                ['amount' => 1_000_000 + $idx * 500_000, 'purpose' => 'Kasbon sintetis kebutuhan karyawan', 'status' => $status, 'hr_approved_by' => $status === 'pending' ? null : $this->hr->id, 'hr_approved_at' => $status === 'pending' ? null : now()->subDay(), 'approved_by' => in_array($status, ['approved', 'paid'], true) ? $this->finance->id : null, 'approved_at' => in_array($status, ['approved', 'paid'], true) ? now()->subHours(10) : null, 'settled_by' => $status === 'paid' ? $this->finance->id : null, 'settled_at' => $status === 'paid' ? now()->subHours(2) : null, 'payment_reference' => $status === 'paid' ? sprintf('ADV-DEMO-%04d', $idx + 1) : null],
            );
        }

        foreach ($employees as $idx => $employee) {
            $base = (int) $employee->salary_monthly;
            $overtime = ($idx % 4) * 135_000;
            $gross = $base + 1_100_000 + $overtime;
            $deductions = 175_000 + (($idx % 3) * 25_000);
            Payroll::query()->updateOrCreate(
                ['user_id' => $employee->id, 'month' => $today->month, 'year' => $today->year],
                ['company_id' => $this->company->id, 'payroll_number' => sprintf('PAY-DEMO-%04d-%03d', $today->format('Ym'), $idx + 1), 'period_start' => $today->startOfMonth(), 'period_end' => $today->endOfMonth(), 'attendance_percentage' => 95 + ($idx % 5), 'base_salary' => $base, 'meal_transport_total' => 800_000, 'overtime_count' => $idx % 4, 'overtime_rate' => 45_000, 'overtime_total' => $overtime, 'attendance_count' => 20, 'attendance_rate' => 15_000, 'attendance_total' => 300_000, 'late_count' => $idx % 3, 'late_rate' => 25_000, 'late_total' => ($idx % 3) * 25_000, 'gross_total' => $gross, 'deduction_total' => $deductions, 'net_total' => $gross - $deductions, 'status' => ['draft', 'submitted', 'approved', 'paid'][$idx % 4], 'generated_by' => $this->finance->id],
            );
        }
    }

    /** @param array<int, User> $employees @return array<int, Project> */
    private function seedProjects(array $employees): array
    {
        $definitions = [
            ['Implementasi Smart Warehouse', 'active', -20, 75, 1_250_000_000],
            ['Instalasi Monitoring Energi Pabrik', 'active', -45, 45, 875_000_000],
            ['Digitalisasi Lini Produksi', 'planning', 10, 120, 2_100_000_000],
        ];
        $projects = [];
        foreach ($definitions as $pIndex => [$name, $status, $startOffset, $endOffset, $budgetValue]) {
            $project = Project::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'name' => $name],
                ['description' => 'Project sintetis industri untuk demonstrasi alur terpadu.', 'status' => $status, 'start_date' => today()->addDays($startOffset), 'end_date' => today()->addDays($endOffset), 'created_by' => $this->projectManager->id],
            );
            $projects[] = $project;
            if (Schema::hasTable('project_members')) {
                foreach ([$this->projectManager, $this->finance, $employees[$pIndex * 3], $employees[$pIndex * 3 + 1]] as $mIndex => $member) {
                    DB::table('project_members')->updateOrInsert(['project_id' => $project->id, 'user_id' => $member->id], ['role' => $mIndex === 0 ? 'owner' : 'member', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $taskNames = ['Survey dan kebutuhan', 'Desain solusi', 'Procurement material', 'Produksi dan konfigurasi', 'Instalasi lapangan', 'Quality assurance', 'UAT pelanggan', 'Serah terima'];
            foreach ($taskNames as $idx => $taskName) {
                Task::query()->updateOrCreate(
                    ['project_id' => $project->id, 'title' => $taskName],
                    ['description' => 'Aktivitas sintetis untuk demonstrasi project control.', 'type' => 'task', 'status' => ['completed', 'completed', 'in_progress', 'in_progress', 'todo', 'todo', 'todo', 'todo'][$idx], 'priority' => $idx < 3 ? 'high' : 'medium', 'story_points' => [3, 5, 8, 13, 8, 5, 5, 3][$idx], 'assigned_to' => $employees[($pIndex * 5 + $idx) % count($employees)]->id, 'start_date' => today()->addDays($startOffset + $idx * 12), 'due_date' => today()->addDays($startOffset + $idx * 12 + 10), 'created_by' => $this->projectManager->id],
                );
            }

            $budget = ProjectBudget::query()->updateOrCreate(
                ['project_id' => $project->id],
                ['company_id' => $this->company->id, 'currency' => 'IDR', 'tax_percent' => 11, 'contingency_percent' => 5, 'status' => $pIndex === 2 ? 'pending_approval' : 'approved', 'current_version' => 1, 'notes' => 'RAB sintetis terstruktur untuk demo', 'approved_by' => $pIndex === 2 ? null : $this->finance->id, 'approved_at' => $pIndex === 2 ? null : now()->subDays(3)],
            );
            $items = [
                ['MAT-001', 'material', 'Sensor, controller, dan panel', 'lot', 1, $budgetValue * .35],
                ['LAB-001', 'labor', 'Engineering dan instalasi', 'manday', 60, $budgetValue * .003],
                ['EQP-001', 'equipment', 'Tools, alat ukur, dan sewa', 'lot', 1, $budgetValue * .12],
                ['SUB-001', 'subcontract', 'Pekerjaan vendor produksi', 'lot', 1, $budgetValue * .18],
                ['OVH-001', 'overhead', 'Mobilisasi dan manajemen project', 'lot', 1, $budgetValue * .10],
            ];
            foreach ($items as $i => [$code, $category, $desc, $unit, $volume, $price]) {
                $item = ProjectBudgetItem::query()->updateOrCreate(
                    ['project_budget_id' => $budget->id, 'code' => $code],
                    ['company_id' => $this->company->id, 'category' => $category, 'description' => $desc, 'unit' => $unit, 'volume' => $volume, 'unit_price' => round($price), 'planned_start' => today()->addDays($startOffset + $i * 15), 'planned_end' => today()->addDays($startOffset + $i * 15 + 35), 'sort_order' => ($i + 1) * 10],
                );
                foreach ([0, 15, 30, 45, 60] as $progressIndex => $day) {
                    $planned = min(100, ($progressIndex + 1) * 20);
                    $actual = max(0, min(100, $planned - (($pIndex + $i + $progressIndex) % 3) * 5));
                    DB::table('project_progress_entries')->updateOrInsert(
                        ['budget_item_id' => $item->id, 'entry_date' => today()->addDays($startOffset + $day)->toDateString()],
                        ['company_id' => $this->company->id, 'project_id' => $project->id, 'planned_percent' => $planned, 'actual_percent' => $actual, 'actual_cost' => round($price * $volume * $actual / 100), 'notes' => 'Progress sintetis Kurva S', 'created_by' => $this->projectManager->id, 'created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }

        return $projects;
    }

    /** @param array<int, Project> $projects @return array<string, Product> */
    private function seedSupplyChain(array $projects): array
    {
        $catalog = [
            'gateway' => ['FG-GW-200', 'Industrial IoT Gateway 200', 'finished_good', 8_500_000, 14_500_000],
            'panel' => ['FG-PNL-01', 'Smart Control Panel', 'finished_good', 12_000_000, 21_500_000],
            'sensor' => ['RM-SNS-TEMP', 'Industrial Temperature Sensor', 'raw_material', 650_000, 1_100_000],
            'controller' => ['RM-PLC-16', 'PLC Controller 16 I/O', 'raw_material', 3_250_000, 4_600_000],
            'enclosure' => ['RM-ENC-IP65', 'Enclosure Panel IP65', 'raw_material', 1_450_000, 2_000_000],
            'power' => ['RM-PSU-24', 'Power Supply 24VDC', 'raw_material', 475_000, 750_000],
            'cable' => ['RM-CBL-IND', 'Industrial Control Cable', 'raw_material', 38_000, 75_000],
            'service' => ['SV-INSTALL', 'Installation and Commissioning', 'service', 4_500_000, 7_500_000],
        ];
        $products = [];
        foreach ($catalog as $key => [$code, $name, $type, $cost, $price]) {
            $products[$key] = Product::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $code],
                ['name' => $name, 'category' => $type === 'finished_good' ? 'Finished Goods' : ($type === 'service' ? 'Service' : 'Component'), 'description' => 'Produk sintetis untuk demonstrasi industri', 'unit_of_measure' => $key === 'cable' ? 'meter' : 'unit', 'list_price' => $price, 'product_type' => $type, 'track_stock' => $type !== 'service', 'unit_cost' => $cost, 'reorder_point' => 10, 'safety_stock' => 5, 'total_stock_cached' => 0, 'is_active' => true],
            );
        }
        $main = InventoryWarehouse::query()->updateOrCreate(['company_id' => $this->company->id, 'code' => 'WH-MAIN'], ['name' => 'Gudang Utama Demo', 'type' => 'main', 'address' => 'Kawasan Industri Sidoarjo', 'city' => 'Sidoarjo', 'manager_id' => $this->production->id, 'is_default' => true, 'is_active' => true]);
        $projectWh = InventoryWarehouse::query()->updateOrCreate(['company_id' => $this->company->id, 'code' => 'WH-PROJECT'], ['name' => 'Gudang Project Demo', 'type' => 'workshop', 'address' => 'Area staging project', 'city' => 'Surabaya', 'manager_id' => $this->projectManager->id, 'is_default' => false, 'is_active' => true]);
        foreach (array_values($products) as $idx => $product) {
            if (! $product->track_stock) {
                continue;
            }
            foreach ([$main, $projectWh] as $wIdx => $warehouse) {
                $qty = max(2, 80 - $idx * 7 - $wIdx * 23);
                InventoryStock::query()->updateOrCreate(['company_id' => $this->company->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id], ['qty_on_hand' => $qty, 'qty_reserved' => min(8, $idx + $wIdx), 'last_movement_at' => now()->subDays($idx + $wIdx)]);
            }
            $product->update(['total_stock_cached' => InventoryStock::query()->where('company_id', $this->company->id)->where('product_id', $product->id)->sum('qty_on_hand')]);
        }

        $bom = Bom::query()->updateOrCreate(['company_id' => $this->company->id, 'product_id' => $products['panel']->id, 'version' => '1.0'], ['name' => 'BOM Smart Control Panel', 'is_active' => true, 'total_cost_cached' => 8_900_000, 'labor_cost' => 1_250_000, 'overhead_cost' => 750_000, 'notes' => 'BOM sintetis', 'created_by' => $this->production->id]);
        foreach ([['controller', 1], ['enclosure', 1], ['power', 1], ['cable', 12]] as $idx => [$key,$qty]) {
            BomComponent::query()->updateOrCreate(['company_id' => $this->company->id, 'bom_id' => $bom->id, 'component_product_id' => $products[$key]->id], ['qty_required' => $qty, 'unit_of_measure' => $key === 'cable' ? 'meter' : 'unit', 'unit_cost_snapshot' => $products[$key]->unit_cost, 'sort_order' => ($idx + 1) * 10]);
        }

        $supplierId = DB::table('fin_suppliers')->where('company_id', $this->company->id)->where('name', 'PT Komponen Industri Nusantara')->value('id');
        if (! $supplierId) {
            $supplierId = DB::table('fin_suppliers')->insertGetId(['company_id' => $this->company->id, 'code' => 'SUP-DEMO-001', 'name' => 'PT Komponen Industri Nusantara', 'email' => 'sales@komponen.example', 'phone' => '031-555-0101', 'address' => 'Alamat sintetis supplier', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['draft', 'sent', 'partially_received', 'received'] as $idx => $status) {
            $p = $products[['sensor', 'controller', 'enclosure', 'power'][$idx]];
            $qty = 10 + $idx * 5;
            $subtotal = $qty * (float) $p->unit_cost;
            $tax = $subtotal * .11;
            $po = PurchaseOrder::query()->updateOrCreate(['company_id' => $this->company->id, 'po_number' => sprintf('PO-DEMO-2026-%03d', $idx + 1)], ['supplier_id' => $supplierId, 'warehouse_id' => $main->id, 'date' => today()->subDays(10 - $idx * 2), 'expected_delivery_date' => today()->addDays($idx + 2), 'status' => $status, 'subtotal' => $subtotal, 'tax_rate' => 11, 'tax_amount' => $tax, 'total' => $subtotal + $tax, 'currency' => 'IDR', 'terms' => 'Pembayaran 30 hari setelah barang diterima', 'notes' => 'Purchase order sintetis demo', 'created_by' => $this->manager->id, 'approved_by' => $status === 'draft' ? null : $this->manager->id, 'approved_at' => $status === 'draft' ? null : now()->subDays(8)]);
            PurchaseOrderLine::query()->updateOrCreate(['purchase_order_id' => $po->id, 'product_id' => $p->id], ['qty_ordered' => $qty, 'qty_received' => $status === 'received' ? $qty : ($status === 'partially_received' ? floor($qty / 2) : 0), 'unit_of_measure' => $p->unit_of_measure, 'unit_cost' => $p->unit_cost, 'subtotal' => $subtotal, 'sort_order' => 10]);
        }

        foreach (['planned', 'in_progress', 'completed'] as $idx => $status) {
            $wo = WorkOrder::query()->updateOrCreate(['company_id' => $this->company->id, 'wo_number' => sprintf('WO-DEMO-2026-%03d', $idx + 1)], ['product_id' => $products['panel']->id, 'bom_id' => $bom->id, 'bom_version' => '1.0', 'qty_planned' => 5 + $idx * 5, 'qty_produced' => $status === 'completed' ? 5 + $idx * 5 : ($status === 'in_progress' ? 4 : 0), 'source_warehouse_id' => $main->id, 'output_warehouse_id' => $main->id, 'status' => $status, 'planned_start_date' => today()->subDays(5 - $idx * 3), 'planned_complete_date' => today()->addDays(7 + $idx * 4), 'released_at' => $status === 'planned' ? null : now()->subDays(3), 'completed_at' => $status === 'completed' ? now()->subDay() : null, 'material_cost_planned' => 8_900_000 * (5 + $idx * 5), 'labor_cost_planned' => 1_250_000 * (5 + $idx * 5), 'overhead_cost_planned' => 750_000 * (5 + $idx * 5), 'total_cost_planned' => 10_900_000 * (5 + $idx * 5), 'notes' => 'Work order sintetis', 'source_type' => 'demo_project', 'source_id' => (string) $projects[$idx]->id, 'created_by' => $this->production->id, 'released_by' => $status === 'planned' ? null : $this->production->id, 'completed_by' => $status === 'completed' ? $this->production->id : null]);
            foreach ($bom->components as $c) {
                WorkOrderComponent::query()->updateOrCreate(['work_order_id' => $wo->id, 'component_product_id' => $c->component_product_id], ['qty_per_unit' => $c->qty_required, 'qty_total_planned' => $c->qty_required * $wo->qty_planned, 'qty_consumed' => $status === 'completed' ? $c->qty_required * $wo->qty_planned : ($status === 'in_progress' ? $c->qty_required * 4 : 0), 'unit_cost_snapshot' => $c->unit_cost_snapshot]);
            }
        }

        $this->seedVendorTracking($projects);
        foreach (['packed', 'in_transit', 'delivered'] as $idx => $status) {
            $shipment = Shipment::query()->updateOrCreate(['company_id' => $this->company->id, 'do_number' => sprintf('DO-DEMO-2026-%03d', $idx + 1)], ['customer_name' => ['PT Surya Manufaktur', 'PT Delta Logistik', 'PT Nusantara Energi'][$idx], 'shipping_address' => 'Alamat sintetis pelanggan '.($idx + 1), 'source_warehouse_id' => $main->id, 'ship_date' => today()->subDays(2 - $idx), 'expected_delivery_date' => today()->addDays($idx + 1), 'actual_delivery_date' => $status === 'delivered' ? today() : null, 'carrier' => 'IBE Demo Logistics', 'tracking_number' => sprintf('IBEDEMO%06d', $idx + 1), 'shipping_cost' => 750_000 + $idx * 250_000, 'status' => $status, 'packed_by' => $this->production->id, 'shipped_by' => $status === 'packed' ? null : $this->production->id, 'received_by_name' => $status === 'delivered' ? 'Penerima Demo' : null, 'packed_at' => now()->subDays(2), 'shipped_at' => $status === 'packed' ? null : now()->subDay(), 'delivered_at' => $status === 'delivered' ? now()->subHours(2) : null, 'notes' => 'Pengiriman sintetis', 'created_by' => $this->production->id]);
            ShipmentItem::query()->updateOrCreate(['shipment_id' => $shipment->id, 'product_id' => $products['panel']->id], ['product_code' => $products['panel']->code, 'product_name' => $products['panel']->name, 'qty' => 2 + $idx, 'unit_of_measure' => 'unit']);
        }

        return $products;
    }

    /** @param array<int, Project> $projects */
    private function seedVendorTracking(array $projects): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }
        foreach ([['PT Panel Teknik Indonesia', 'Budi Santoso'], ['CV Presisi Metal Works', 'Rina Amelia']] as $idx => [$name,$pic]) {
            $vendorId = DB::table('vendors')->where('company_id', $this->company->id)->where('name', $name)->value('id') ?: DB::table('vendors')->insertGetId(['company_id' => $this->company->id, 'name' => $name, 'pic_name' => $pic, 'pic_email' => 'vendor'.($idx + 1).'@example.test', 'pic_phone' => '08130000000'.($idx + 1), 'address' => 'Alamat sintetis vendor', 'created_at' => now(), 'updated_at' => now()]);
            $ref = sprintf('VWO-DEMO-%03d', $idx + 1);
            $vwoId = DB::table('vendor_work_orders')->where('company_id', $this->company->id)->where('ref_no', $ref)->value('id');
            $payload = ['company_id' => $this->company->id, 'vendor_id' => $vendorId, 'project_id' => $projects[$idx]->id, 'ref_no' => $ref, 'title' => $idx === 0 ? 'Fabrikasi enclosure panel' : 'Assembly wiring panel', 'description' => 'Pekerjaan vendor sintetis', 'budget' => 125_000_000 + $idx * 75_000_000, 'scheduled_start' => today()->subDays(10), 'scheduled_end' => today()->addDays(20), 'actual_start' => today()->subDays(8), 'status' => 'in_progress', 'created_by_user_id' => $this->production->id, 'created_at' => now(), 'updated_at' => now()];
            if ($vwoId) {
                DB::table('vendor_work_orders')->where('id', $vwoId)->update(array_diff_key($payload, ['created_at' => true]));
            } else {
                $vwoId = DB::table('vendor_work_orders')->insertGetId($payload);
            }
            foreach ([15, 35, 55, 72] as $pIdx => $percent) {
                DB::table('work_order_progress')->updateOrInsert(['work_order_id' => $vwoId, 'progress_percent' => $percent], ['company_id' => $this->company->id, 'note' => 'Update progres sintetis tahap '.($pIdx + 1), 'photo_paths' => json_encode([]), 'submitted_by' => $pIdx % 2 ? 'internal' : 'vendor', 'submitted_by_user_id' => $pIdx % 2 ? $this->production->id : null, 'submitted_at' => now()->subDays(8 - $pIdx * 2), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** @param array<string, Product> $products */
    private function seedCommercial(array $products): void
    {
        if (! Schema::hasTable('crm_companies')) {
            return;
        }
        $pipelineId = DB::table('crm_pipelines')->where('company_id', $this->company->id)->where('is_default', true)->value('id');
        if (! $pipelineId) {
            $pipelineId = DB::table('crm_pipelines')->insertGetId(['company_id' => $this->company->id, 'name' => 'Enterprise Sales', 'description' => 'Pipeline sintetis industri', 'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $stages = [['Prospecting', '#64748b', 'open', 10, 10], ['Qualification', '#0ea5e9', 'open', 20, 30], ['Proposal', '#8b5cf6', 'open', 30, 55], ['Negotiation', '#f59e0b', 'open', 40, 75], ['Closed Won', '#22c55e', 'won', 50, 100], ['Closed Lost', '#ef4444', 'lost', 60, 0]];
        $stageIds = [];
        foreach ($stages as [$name,$color,$type,$sort,$prob]) {
            $id = DB::table('crm_stages')->where('company_id', $this->company->id)->where('pipeline_id', $pipelineId)->where('name', $name)->value('id');
            if (! $id) {
                $id = DB::table('crm_stages')->insertGetId(['company_id' => $this->company->id, 'pipeline_id' => $pipelineId, 'name' => $name, 'color' => $color, 'type' => $type, 'sort_order' => $sort, 'default_probability' => $prob, 'created_at' => now(), 'updated_at' => now()]);
            }$stageIds[] = $id;
        }
        $companies = [['PT Surya Manufaktur', 'Manufacturing', 850_000_000], ['PT Delta Logistik', 'Logistics', 425_000_000], ['PT Nusantara Energi', 'Energy', 1_350_000_000], ['PT Prima Food Processing', 'Food Manufacturing', 650_000_000], ['PT Arta Kemasan', 'Packaging', 310_000_000], ['PT Sembada Farmasi', 'Pharmaceutical', 980_000_000]];
        foreach ($companies as $idx => [$name,$industry,$amount]) {
            $companyId = DB::table('crm_companies')->where('company_id', $this->company->id)->where('name', $name)->value('id');
            if (! $companyId) {
                $companyId = DB::table('crm_companies')->insertGetId(['company_id' => $this->company->id, 'name' => $name, 'industry' => $industry, 'size' => $idx % 2 ? 'large' : 'enterprise', 'website' => 'https://customer'.($idx + 1).'.example', 'phone' => '021-555-'.sprintf('%04d', $idx + 1), 'email' => 'procurement'.($idx + 1).'@example.test', 'address' => 'Alamat sintetis prospek', 'city' => ['Surabaya', 'Jakarta', 'Gresik'][$idx % 3], 'province' => 'Jawa Timur', 'country' => 'Indonesia', 'owner_id' => $this->manager->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('crm_leads')->updateOrInsert(['company_id' => $this->company->id, 'email' => 'lead'.($idx + 1).'@example.test'], ['name' => 'PIC Demo '.($idx + 1), 'phone' => '0812555000'.($idx + 1), 'company_name' => $name, 'source' => ['website', 'referral', 'event'][$idx % 3], 'status' => ['new', 'contacted', 'qualified'][$idx % 3], 'notes' => 'Lead sintetis untuk demonstrasi', 'owner_id' => $this->manager->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('crm_deals')->updateOrInsert(['company_id' => $this->company->id, 'code' => sprintf('DEAL-DEMO-%04d', $idx + 1)], ['title' => 'Solusi Otomasi - '.$name, 'description' => 'Opportunity sintetis end-to-end', 'pipeline_id' => $pipelineId, 'stage_id' => $stageIds[$idx % count($stageIds)], 'crm_company_id' => $companyId, 'owner_id' => $this->manager->id, 'amount' => $amount, 'currency' => 'IDR', 'probability' => $stages[$idx % count($stages)][4], 'expected_close_date' => today()->addDays(15 + $idx * 10), 'closed_at' => $idx === 4 ? today()->subDays(2) : null, 'status' => $idx === 4 ? 'won' : ($idx === 5 ? 'lost' : 'open'), 'lost_reason' => $idx === 5 ? 'timing' : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['draft', 'sent', 'accepted'] as $idx => $status) {
            $subtotal = (2 + $idx) * $products['panel']->list_price;
            $tax = $subtotal * .11;
            $quote = Quotation::query()->updateOrCreate(['company_id' => $this->company->id, 'quote_number' => sprintf('QT-DEMO-2026-%03d', $idx + 1)], ['public_token' => bin2hex(random_bytes(16)), 'title' => 'Proposal Smart Control System', 'project_name' => 'Demo Industrial Automation '.($idx + 1), 'customer_name' => $companies[$idx][0], 'customer_address' => 'Alamat sintetis customer', 'customer_pic_name' => 'PIC Demo', 'quote_date' => today()->subDays(5 - $idx), 'valid_until' => today()->addDays(25 + $idx), 'subtotal' => $subtotal, 'discount' => 0, 'discount_pct' => 0, 'after_discount' => $subtotal, 'tax_rate' => 11, 'tax_amount' => $tax, 'total' => $subtotal + $tax, 'payment_terms' => '30% DP, 60% delivery, 10% commissioning', 'delivery_terms' => '8-10 minggu', 'warranty_terms' => '12 bulan', 'notes' => 'Quotation sintetis', 'status' => $status, 'prepared_by' => $this->manager->id, 'sent_at' => $status === 'draft' ? null : now()->subDays(2), 'accepted_at' => $status === 'accepted' ? now()->subDay() : null]);
            QuotationItem::query()->updateOrCreate(['quotation_id' => $quote->id, 'product_id' => $products['panel']->id], ['section_label' => 'System', 'sort_order' => 10, 'description' => $products['panel']->name, 'qty' => 2 + $idx, 'unit' => 'unit', 'unit_price' => $products['panel']->list_price, 'subtotal' => $subtotal]);
        }
    }

    private function seedTreasury(): void
    {
        $account = TreasuryBankAccount::query()->updateOrCreate(['company_id' => $this->company->id, 'name' => 'Bank Operasional Demo'], ['bank_name' => 'Bank Demo Indonesia', 'account_number' => '888800001234', 'currency' => 'IDR', 'opening_balance' => 750_000_000, 'current_balance' => 892_450_000, 'is_active' => true, 'created_by' => $this->finance->id]);
        foreach ([['out', 'PT Komponen Industri Nusantara', 125_000_000, 'executed'], ['in', 'PT Surya Manufaktur', 255_000_000, 'executed'], ['out', 'Payroll Agustus 2026', 142_500_000, 'approved'], ['out', 'Vendor Produksi Demo', 85_000_000, 'pending_approval']] as $idx => [$direction,$counterparty,$amount,$status]) {
            $payment = TreasuryPayment::query()->updateOrCreate(['company_id' => $this->company->id, 'reference' => sprintf('PAY-DEMO-%04d', $idx + 1)], ['bank_account_id' => $account->id, 'direction' => $direction, 'counterparty' => $counterparty, 'counterparty_account' => '99990000'.($idx + 1), 'amount' => $amount, 'currency' => 'IDR', 'scheduled_for' => today()->addDays($idx - 2), 'description' => 'Pembayaran sintetis demonstrasi', 'status' => $status, 'maker_id' => $this->finance->id, 'approved_by' => $status === 'pending_approval' ? null : $this->superadmin->id, 'approved_at' => $status === 'pending_approval' ? null : now()->subDay(), 'executed_by' => $status === 'executed' ? $this->finance->id : null, 'executed_at' => $status === 'executed' ? now()->subHours(4) : null, 'bank_reference' => $status === 'executed' ? 'BANK-DEMO-'.($idx + 1) : null]);
            if ($status === 'executed') {
                TreasuryCashTransaction::query()->updateOrCreate(['company_id' => $this->company->id, 'payment_id' => $payment->id], ['bank_account_id' => $account->id, 'type' => $direction, 'amount' => $amount, 'balance_after' => 892_450_000, 'transaction_date' => today()->addDays($idx - 2), 'reference' => $payment->reference, 'description' => $payment->description, 'posted_by' => $this->finance->id]);
            }
        }
    }

    /** @param array<int, User> $employees */
    private function seedSupportModules(array $employees): void
    {
        foreach (array_slice($employees, 0, 6) as $idx => $employee) {
            Contract::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'contract_number' => sprintf('CTR-DEMO-2026-%03d', $idx + 1)],
                ['user_id' => $employee->id, 'contract_type' => $idx % 3 === 0 ? 'pkwt' : 'permanent', 'issued_at' => today()->subMonths(2), 'start_date' => today()->subMonths(2), 'end_date' => $idx % 3 === 0 ? today()->addMonths(10) : null, 'monthly_amount' => $employee->salary_monthly, 'status' => ['draft', 'pending_karyawan', 'signed_karyawan', 'active', 'active', 'active'][$idx], 'placeholders' => ['employee_name' => $employee->name, 'position' => 'Synthetic Demo Role'], 'issued_by' => $this->hr->id],
            );
            PerformanceReview::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'user_id' => $employee->id, 'cycle' => '2026-H1'],
                ['stage' => ['draft', 'self_review', 'manager_review', 'completed'][$idx % 4], 'self_score' => 3.6 + (($idx % 3) * .2), 'manager_score' => 3.7 + (($idx % 3) * .15), 'final_score' => 3.8 + (($idx % 3) * .1), 'self_comments' => 'Evaluasi sintetis pencapaian semester.', 'manager_comments' => 'Performa baik; fokus pada kolaborasi lintas fungsi.', 'reviewer_id' => $this->manager->id],
            );
            BusinessTrip::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'user_id' => $employee->id, 'date' => today()->subDays($idx + 1)],
                ['destination' => ['Surabaya', 'Gresik', 'Malang'][$idx % 3], 'purpose' => 'Kunjungan project sintetis', 'status' => ['pending', 'approved', 'rejected'][$idx % 3], 'approved_by' => $idx % 3 === 0 ? null : $this->manager->id, 'approved_at' => $idx % 3 === 0 ? null : now()->subDay()],
            );
            ClientVisit::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'user_id' => $employee->id, 'date' => today()->subDays($idx + 3)],
                ['visit_in' => now()->subDays($idx + 3)->setTime(10, 0), 'visit_out' => now()->subDays($idx + 3)->setTime(12, 0), 'latitude_in' => '-7.257472', 'longitude_in' => '112.752090', 'latitude_out' => '-7.257472', 'longitude_out' => '112.752090', 'notes_in' => 'Demo kunjungan pelanggan', 'notes_out' => 'Kebutuhan dan tindak lanjut tercatat', 'status' => 'completed'],
            );
        }

        foreach ([['Senior Automation Engineer', 'Engineering'], ['Account Executive Industri', 'Sales'], ['Warehouse Officer', 'Supply Chain']] as $jIdx => [$title, $division]) {
            $job = JobPosting::query()->updateOrCreate(['company_id' => $this->company->id, 'title' => $title], ['division' => $division, 'location' => 'Sidoarjo', 'type' => 'fulltime', 'description' => 'Lowongan sintetis untuk demonstrasi ATS.', 'salary_range' => 'Rp 7.000.000 - Rp 15.000.000', 'status' => 'open', 'posted_at' => today()->subDays(14), 'created_by' => $this->hr->id]);
            foreach (['applied', 'screening', 'interview', 'offered'] as $cIdx => $stage) {
                Candidate::query()->updateOrCreate(['company_id' => $this->company->id, 'job_posting_id' => $job->id, 'email' => sprintf('candidate%d%d@example.test', $jIdx + 1, $cIdx + 1)], ['name' => 'Kandidat Demo '.($jIdx * 4 + $cIdx + 1), 'phone' => sprintf('08127700%04d', $jIdx * 4 + $cIdx + 1), 'source' => ['Website', 'Referral', 'LinkedIn', 'Job Fair'][$cIdx], 'stage' => $stage, 'notes' => 'Kandidat sintetis tanpa data pribadi nyata.', 'applied_at' => today()->subDays(12 - $cIdx)]);
            }
        }

        foreach ([['Keselamatan Kerja Industri', 'HSE', 8, true], ['Dasar Project Management', 'Project', 12, true], ['Cybersecurity Awareness', 'Security', 4, true], ['Enterprise Selling', 'Sales', 10, false]] as $courseIndex => [$title, $category, $hours, $mandatory]) {
            $course = Course::query()->updateOrCreate(['company_id' => $this->company->id, 'title' => $title], ['category' => $category, 'provider' => 'IBE Learning Demo', 'hours' => $hours, 'type' => 'internal', 'url' => 'https://demo-learning.example/course/'.($courseIndex + 1), 'mandatory' => $mandatory, 'description' => 'Materi pembelajaran sintetis.']);
            foreach (array_slice($employees, $courseIndex * 4, 8) as $eIndex => $employee) {
                Enrollment::query()->updateOrCreate(['course_id' => $course->id, 'user_id' => $employee->id], ['company_id' => $this->company->id, 'progress' => [0, 25, 60, 100][$eIndex % 4], 'status' => $eIndex % 4 === 3 ? 'completed' : 'in_progress', 'enrolled_at' => today()->subDays(20), 'completed_at' => $eIndex % 4 === 3 ? today()->subDays(2) : null]);
            }
        }

        foreach ([['Pertumbuhan Pendapatan Industri', 'objective', 68], ['Ketepatan Delivery Project', 'key_result', 82], ['Peningkatan Kompetensi Tim', 'key_result', 57]] as $gIdx => [$name, $type, $progress]) {
            Goal::query()->updateOrCreate(['company_id' => $this->company->id, 'name' => $name], ['description' => 'Goal sintetis untuk demonstrasi OKR.', 'status' => $progress >= 65 ? 'on_track' : 'at_risk', 'type' => $type, 'focus_area' => ['Growth', 'Delivery', 'People'][$gIdx], 'owner_id' => [$this->superadmin->id, $this->projectManager->id, $this->hr->id][$gIdx], 'progress_metric' => 'percentage', 'progress_value' => $progress, 'start_date' => today()->startOfYear(), 'end_date' => today()->endOfYear(), 'created_by' => $this->superadmin->id]);
        }

        foreach ([['SOP-OPS-001', 'SOP Pengadaan dan Penerimaan Barang', 'SOP'], ['POL-HR-001', 'Kebijakan Kehadiran dan Cuti', 'Policy'], ['WI-PRD-001', 'Instruksi Kerja Quality Inspection', 'Work Instruction'], ['FORM-FIN-001', 'Form Persetujuan Pembayaran', 'Form']] as $dIdx => [$code, $title, $category]) {
            CompanyDocument::query()->updateOrCreate(['company_id' => $this->company->id, 'doc_code' => $code], ['title' => $title, 'category' => $category, 'description' => 'Dokumen sintetis untuk demo DMS.', 'owner_dept' => ['Supply Chain', 'HR', 'Production', 'Finance'][$dIdx], 'owner_user_id' => [$this->manager->id, $this->hr->id, $this->production->id, $this->finance->id][$dIdx], 'tags' => ['demo', 'controlled'], 'current_version' => '1.0', 'effective_date' => today()->subMonth(), 'expiry_date' => today()->addYear(), 'alert_days_before' => 30, 'status' => 'active', 'created_by' => $this->superadmin->id]);
        }

        foreach (['open', 'assigned', 'in_progress', 'resolved', 'closed'] as $idx => $status) {
            Complaint::query()->updateOrCreate(['company_id' => $this->company->id, 'ticket_number' => sprintf('TKT-DEMO-2026-%03d', $idx + 1)], ['customer_name' => 'Pelanggan Demo '.($idx + 1), 'customer_email' => 'customer'.($idx + 1).'@example.test', 'customer_company' => ['PT Surya Manufaktur', 'PT Delta Logistik', 'PT Nusantara Energi'][$idx % 3], 'product_name' => 'Smart Control Panel', 'category' => ['technical', 'delivery', 'quality'][$idx % 3], 'subject' => 'Tiket layanan sintetis #'.($idx + 1), 'description' => 'Contoh keluhan untuk demonstrasi service workflow.', 'attachments' => [], 'source' => 'portal', 'priority' => ['low', 'medium', 'high', 'urgent', 'medium'][$idx], 'sla_response_minutes' => 120, 'sla_resolution_minutes' => 1440, 'target_response_at' => now()->addHours(2), 'target_resolution_at' => now()->addDay(), 'status' => $status, 'assigned_to_user_id' => $status === 'open' ? null : $employees[13]->id, 'resolution_notes' => in_array($status, ['resolved', 'closed'], true) ? 'Solusi sintetis telah dikonfirmasi.' : null, 'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now()->subHours(2) : null, 'resolved_by_user_id' => in_array($status, ['resolved', 'closed'], true) ? $employees[13]->id : null, 'reopen_count' => 0]);
        }
    }
}

<?php

/*
|--------------------------------------------------------------------------
| SaaS module route manifest
|--------------------------------------------------------------------------
|
| These patterns are matched against Laravel's normalized route URI (not the
| user-controlled URL). Keep every pattern tenant-authenticated. Public CRM
| forms deliberately remain outside this manifest because their tenant must be
| resolved from the form token before an entitlement can be evaluated.
|
| A route may belong to at most one module. ModuleRouteManifest throws when a
| pattern overlap is introduced so an ambiguous route can never fail open.
|
*/

return [
    'people' => [
        'api/v1/leaves*',
        'api/v1/overtimes*',
        'api/v1/attendances*',
        'api/v1/office-locations*',
        'api/v1/holidays*',
        'api/v1/payrolls*',
        'api/v1/salary-advances*',
        'api/v1/reimbursements*',
        'api/v1/business-trips*',
        'api/v1/client-visits*',
        'api/v1/me/contract*',
        'api/v1/contracts/*/preview',
        'api/v1/direktur/contracts*',
        'api/v1/me/work-certificate*',
        'api/v1/auth/face-setup',
        'api/v1/admin/contracts*',
        'api/v1/admin/contract-templates*',
        'api/v1/admin/employees*',
        'api/v1/admin/attendances*',
        'api/v1/admin/attendance-devices*',
        'api/v1/admin/users/*/attendances',
        'api/v1/admin/users/*/contracts',
        'api/v1/admin/users/*/issue-contract',
        'api/v1/admin/shift-schedules*',
        'api/v1/admin/leaves*',
        'api/v1/admin/overtimes*',
        'api/v1/admin/payrolls*',
        'api/v1/admin/payroll-formula*',
        'api/v1/admin/salary-advances*',
        'api/v1/admin/reimbursements*',
        'api/v1/admin/business-trips*',
        'api/v1/admin/client-visits*',
        'api/v1/admin/face-approvals*',
        'api/v1/admin/performance*',
        'api/v1/admin/recruitment*',
        'api/v1/admin/learning*',
        'api/v1/admin/leave-policy*',
        'api/v1/admin/work-certificates*',
        'api/v1/admin/master-data/divisions*',
        'api/v1/admin/master-data/job-titles*',
        'api/v1/admin/master-data/educations*',
        'api/v1/admin/master-data/shifts*',
        'api/v1/admin/master-data/inventories*',
        'api/v1/admin/master-data/office-locations*',
        'api/v1/admin/late-tiers*',
        'api/v1/admin/users/*/late-tiers*',
    ],

    'marketing' => [
        'api/v1/crm/pipelines*',
        'api/v1/crm/leads*',
        'api/v1/crm/companies*',
        'api/v1/crm/contacts*',
        'api/v1/crm/deals*',
        'api/v1/crm/activities*',
        'api/v1/crm/reports*',
        'api/v1/crm/forms*',
        'api/v1/crm/campaigns*',
        'api/v1/admin/customer-surveys*',
        'api/v1/admin/customer-360*',
    ],

    'finance' => [
        'api/v1/finance/*',
        'api/v1/admin/currencies*',
        'api/v1/admin/exchange-rates*',
        'api/v1/admin/fixed-assets*',
        'api/v1/admin/company-banks*',
    ],

    'production' => [
        'api/v1/admin/products*',
        'api/v1/admin/serial-numbers*',
        'api/v1/admin/boms*',
        'api/v1/admin/mrp*',
        'api/v1/admin/batches*',
        'api/v1/admin/work-orders*',
        'api/v1/admin/quality-inspections*',
        'api/v1/admin/production-calendar*',
        'api/v1/admin/complaints*',
        'api/v1/admin/maintenance-schedules*',
    ],

    'warehouse' => [
        'api/v1/admin/warehouses*',
        'api/v1/admin/inventory/*',
        'api/v1/admin/stock-counts*',
        'api/v1/admin/stock-count-lines*',
    ],

    // Existing commercial middleware remains in place. Including the legacy
    // surfaces here closes bypasses that pre-date the modular ERP workspace.
    'sales' => [
        'api/v1/admin/quotations*',
    ],
    'procurement' => [
        'api/v1/admin/purchase-orders*',
        'api/v1/admin/vendor-scorecards*',
    ],
    'distribution' => [
        'api/v1/admin/shipments*',
    ],
];

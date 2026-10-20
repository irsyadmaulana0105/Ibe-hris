<?php

return [
    'email_domain' => env('DEMO_ACCOUNT_DOMAIN', 'demo.ibereality.com'),

    'tenants' => [
        'enterprise' => [
            'name' => 'IBE Enterprise Demo',
            'short_name' => 'Enterprise Demo',
            'plan' => 'enterprise',
            'modules' => ['people', 'marketing', 'sales', 'finance', 'treasury', 'procurement', 'production', 'warehouse', 'distribution'],
            'landing_app' => 'erp',
            'personas' => ['employee', 'hr', 'finance', 'manager', 'production', 'project', 'director', 'superadmin', 'vendor'],
        ],
        'hris' => [
            'name' => 'IBE HRIS Demo',
            'short_name' => 'HRIS Demo',
            'plan' => 'starter',
            'modules' => ['people'],
            'landing_app' => 'attendance',
            'personas' => ['employee', 'hr', 'manager', 'director', 'superadmin'],
        ],
        'finance' => [
            'name' => 'IBE Finance Demo',
            'short_name' => 'Finance Demo',
            'plan' => 'enterprise',
            'modules' => ['finance', 'treasury'],
            'landing_app' => 'erp',
            'personas' => ['employee', 'finance', 'manager', 'director', 'superadmin'],
        ],
        'commercial' => [
            'name' => 'IBE CRM and Sales Demo',
            'short_name' => 'Commercial Demo',
            'plan' => 'professional',
            'modules' => ['marketing', 'sales', 'finance'],
            'landing_app' => 'crm',
            'personas' => ['employee', 'manager', 'finance', 'director', 'superadmin'],
        ],
        'manufacturing' => [
            'name' => 'IBE Manufacturing Demo',
            'short_name' => 'Manufacturing Demo',
            'plan' => 'manufacturing',
            'modules' => ['finance', 'treasury', 'procurement', 'production', 'warehouse', 'distribution'],
            'landing_app' => 'erp',
            'personas' => ['finance', 'manager', 'production', 'director', 'superadmin', 'vendor'],
        ],
        'projects' => [
            'name' => 'IBE Project Control Demo',
            'short_name' => 'Projects Demo',
            'plan' => 'enterprise',
            'modules' => ['people', 'finance', 'treasury', 'procurement'],
            'landing_app' => 'projects',
            'personas' => ['employee', 'finance', 'manager', 'project', 'director', 'superadmin', 'vendor'],
        ],
    ],

    'personas' => [
        'employee' => ['Karyawan Demo', 'user', 'employee'],
        'hr' => ['HR Manager Demo', 'admin', 'hr_manager'],
        'finance' => ['Finance Controller Demo', 'finance', 'finance_controller'],
        'manager' => ['Manager Operasional Demo', 'admin', 'procurement_manager'],
        'production' => ['Production Manager Demo', 'produksi', 'production_manager'],
        'project' => ['Project Manager Demo', 'admin', 'project_manager'],
        'director' => ['Direktur Demo', 'direktur', 'director'],
        'superadmin' => ['Superadmin Demo', 'superadmin', 'tenant_owner'],
        'vendor' => ['Vendor Produksi Demo', 'user', 'production_operator'],
    ],
];

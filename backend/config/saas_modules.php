<?php

return [
    'legacy_default_enabled' => true,

    'modules' => [
        'people' => ['name' => 'Manajemen SDM', 'depends_on' => []],
        'marketing' => ['name' => 'Manajemen Marketing', 'depends_on' => []],
        'sales' => ['name' => 'Manajemen Sales', 'depends_on' => ['marketing', 'finance']],
        'finance' => ['name' => 'Manajemen Keuangan', 'depends_on' => []],
        'treasury' => ['name' => 'Treasury & Cash Management', 'depends_on' => ['finance']],
        'production' => ['name' => 'Manajemen Produksi', 'depends_on' => ['warehouse']],
        'distribution' => ['name' => 'Manajemen Distribusi', 'depends_on' => ['warehouse']],
        'warehouse' => ['name' => 'Manajemen Warehouse', 'depends_on' => []],
        'procurement' => ['name' => 'Manajemen Procurement', 'depends_on' => ['warehouse', 'finance']],
    ],

    'plans' => [
        'starter' => ['name' => 'Starter', 'user_limit' => 25, 'monthly_price' => 1500000, 'yearly_price' => 15000000, 'currency' => 'IDR', 'modules' => ['people']],
        'professional' => ['name' => 'Professional', 'user_limit' => 100, 'monthly_price' => 4500000, 'yearly_price' => 45000000, 'currency' => 'IDR', 'modules' => ['people', 'marketing', 'sales', 'finance']],
        'manufacturing' => ['name' => 'Manufacturing', 'user_limit' => 250, 'monthly_price' => 8500000, 'yearly_price' => 85000000, 'currency' => 'IDR', 'modules' => ['people', 'finance', 'treasury', 'production', 'distribution', 'warehouse', 'procurement']],
        'enterprise' => ['name' => 'Enterprise', 'user_limit' => null, 'monthly_price' => null, 'yearly_price' => null, 'currency' => 'IDR', 'modules' => ['people', 'marketing', 'sales', 'finance', 'treasury', 'production', 'distribution', 'warehouse', 'procurement']],
    ],
];

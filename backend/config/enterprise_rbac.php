<?php

return [
    // legacy: existing User::capabilities only
    // shadow: legacy remains authoritative, target decision can be audited
    // enforce: target policy is authoritative (enable per controlled rollout)
    'mode' => env('ENTERPRISE_RBAC_MODE', 'shadow'),
    'record_decisions' => (bool) env('ENTERPRISE_RBAC_RECORD_DECISIONS', false),
    'record_matches' => (bool) env('ENTERPRISE_RBAC_RECORD_MATCHES', false),
    'default_assignment_days' => (int) env('ENTERPRISE_RBAC_DEFAULT_ASSIGNMENT_DAYS', 365),
    'break_glass_max_minutes' => (int) env('ENTERPRISE_RBAC_BREAK_GLASS_MAX_MINUTES', 60),
];

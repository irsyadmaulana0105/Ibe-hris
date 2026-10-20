<?php

/*
 * Feature flags per module. Default OFF untuk fitur baru pada tenant produksi
 * sampai smoke test/UAT lulus (per aturan hardening ERP komersial).
 *
 * Toggle via .env: FEATURE_VENDOR_TRACKING=true
 */

return [
    'vendor_tracking' => env('FEATURE_VENDOR_TRACKING', false),
    'project_budget'  => env('FEATURE_PROJECT_BUDGET', false),
    'company_branding' => env('FEATURE_COMPANY_BRANDING', true),
];

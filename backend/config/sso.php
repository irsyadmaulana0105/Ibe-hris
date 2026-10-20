<?php

return [
    'ttl_minutes' => env('SSO_TOKEN_TTL_MINUTES', 5),

    'platforms' => [
        'erp' => [
            'name' => 'ERP Backend',
            'description' => 'Pusat operasional HR & Finance: kelola karyawan, divisi, payroll, approval cuti/lembur/reimburse, audit log, RBAC, dan master data.',
            'base_url' => env('SSO_PLATFORM_ERP_BASE_URL', env('APP_URL', 'http://localhost')),
            'callback_path' => '/sso/callback',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_ERP_LOGO', '/images/logo-erp.png'),
        ],
        'attendance' => [
            'name' => 'Absen Karyawan',
            'description' => 'Aplikasi absensi karyawan dengan face recognition + validasi GPS. Ajukan cuti, lembur, reimburse, dan lihat slip gaji langsung dari HP.',
            'base_url' => env('SSO_PLATFORM_ATTENDANCE_BASE_URL', 'https://attendance.ibereality.com'),
            'callback_path' => '/sso/callback',
            'token_transport' => 'fragment',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_ATTENDANCE_LOGO', '/images/logo-attendance.png'),
        ],
        'projects' => [
            'name' => 'Manajemen Projek IBE',
            'description' => 'Roadmap project, sprint planning, kanban board task, time tracking, dan laporan kontribusi untuk bonus project 1%.',
            'base_url' => env('SSO_PLATFORM_PROJECTS_BASE_URL', 'https://app-project.ibereality.com'),
            'callback_path' => '/sso/callback',
            'token_transport' => 'fragment',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_PROJECTS_LOGO', '/images/logo-projects.png'),
        ],
        'crm' => [
            'name' => 'CRM Sales',
            'description' => 'Pipeline sales kanban, leads tracking, deals management. Closed-won auto-create Customer + draft Invoice di Finance.',
            'base_url' => env('SSO_PLATFORM_CRM_BASE_URL', 'https://crm.ibereality.com'),
            'callback_path' => '/sso/callback',
            'token_transport' => 'fragment',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_CRM_LOGO', '/images/logo.png'),
        ],
        'dokumen' => [
            'name' => 'Dokumen - Surat Menyurat',
            'description' => 'Sistem surat menyurat IBE Reality - Penawaran, Tugas, Pernyataan, Undangan, Invoice, dll. Auto-numbering, workflow approval, PDF + TTD digital.',
            'base_url' => env('SSO_PLATFORM_DOKUMEN_BASE_URL', 'https://dokumen.ibereality.com'),
            'callback_path' => '/sso/callback',
            'token_transport' => 'fragment',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_DOKUMEN_LOGO', '/images/logo.png'),
        ],
        'monitor' => [
            'name' => 'Cyber Command',
            'description' => 'Konsol keamanan internal untuk telemetry, firewall, CrowdSec, TLS, dan audit akses.',
            'base_url' => env('SSO_PLATFORM_MONITOR_BASE_URL', 'https://secure.ibereality.com'),
            'callback_path' => '/sso/callback',
            // Monitor masih memakai callback server-rendered GET. Jangan kirim
            // form POST sampai callback monitor mendukung POST + CSRF exclusion.
            'token_transport' => 'query',
            'form_post' => false,
            'enabled' => env('SSO_PLATFORM_MONITOR_ENABLED', true),
            'logo_url' => env('SSO_PLATFORM_MONITOR_LOGO', '/images/logo.png'),
        ],
        // "webmail" bukan target_app SSO standar (tidak punya callback). Portal
        // launcher render kartu ini secara khusus → klik = panggil endpoint
        // /api/v1/auth/webmail-ticket, hasilkan link 60s ke webmail.ibereality.com.
        'webmail' => [
            'name' => 'Email IBE Reality',
            'description' => 'Webmail @ibereality.com - inbox, kirim, dan kelola email perusahaan. Tersedia untuk karyawan yang sudah klaim email IBE.',
            'base_url' => env('STALWART_WEBMAIL_URL', 'https://webmail.ibereality.com'),
            'callback_path' => '',
            'token_transport' => 'fragment',
            'enabled' => true,
            'logo_url' => env('SSO_PLATFORM_WEBMAIL_LOGO', '/images/logo-attendance.png'),
        ],
    ],
];

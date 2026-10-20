<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#1a7a8c">

  @php
    // Coba ambil dari $header slot (yang berisi <h2>...</h2>), fallback ke $title
    $rawTitle  = isset($header) ? (string) $header : ($title ?? '');
    $pageTitle = trim(strip_tags($rawTitle));
    $appName   = 'IBE Reality';
    $fullTitle = $pageTitle ? "{$pageTitle} - {$appName}" : "{$appName} Admin";
  @endphp
  <title>{{ $fullTitle }}</title>

  {{-- Favicon --}}
  <link rel="icon" type="image/x-icon"  href="{{ asset('favicon.ico') }}">
  <link rel="icon" type="image/png"     href="{{ asset('asset/images/logo.png') }}" sizes="32x32">
  <link rel="apple-touch-icon"          href="{{ asset('asset/images/logo.png') }}">

  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

  @vite(['resources/css/app.css', 'resources/js/app.js'])
  @livewireStyles
  @stack('styles')

  <style>
    body { font-family: 'Inter', sans-serif; }

    /* ── Sidebar ─────────────────────────────────────────── */
    .ibe-sidebar {
      position: fixed; top: 0; left: 0; bottom: 0;
      width: 260px; z-index: 50;
      background: linear-gradient(180deg, #0d5a6a 0%, #1a7a8c 60%, #1e8fa4 100%);
      display: flex; flex-direction: column;
      transition: transform .25s cubic-bezier(.4,0,.2,1);
      box-shadow: 4px 0 24px rgba(0,0,0,.15);
    }
    .ibe-sidebar-logo {
      display: flex; align-items: center; gap: 10px;
      padding: 20px 20px 16px;
      border-bottom: 1px solid rgba(255,255,255,.12);
    }
    .ibe-sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; object-fit: contain; background: #fff; padding: 3px; }
    .ibe-sidebar-logo-text { color: #fff; font-size: 14px; font-weight: 700; line-height: 1.2; }
    .ibe-sidebar-logo-sub { color: rgba(255,255,255,.65); font-size: 10px; font-weight: 400; letter-spacing: .5px; text-transform: uppercase; }

    .ibe-sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px; }
    .ibe-sidebar-nav::-webkit-scrollbar { width: 4px; }
    .ibe-sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 4px; }

    .ibe-nav-section { font-size: 10px; font-weight: 600; color: rgba(255,255,255,.45);
      letter-spacing: 1px; text-transform: uppercase; padding: 10px 10px 4px; }
    .ibe-nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; border-radius: 10px; margin-bottom: 2px;
      color: rgba(255,255,255,.82); font-size: 13.5px; font-weight: 500;
      text-decoration: none; transition: all .15s;
    }
    .ibe-nav-item:hover { background: rgba(255,255,255,.12); color: #fff; text-decoration: none; }
    .ibe-nav-item.active { background: rgba(255,255,255,.2); color: #fff; font-weight: 600; }
    .ibe-nav-item svg { width: 17px; height: 17px; flex-shrink: 0; opacity: .85; }
    .ibe-nav-item.active svg { opacity: 1; }

    .ibe-sidebar-footer {
      padding: 14px 14px 18px;
      border-top: 1px solid rgba(255,255,255,.12);
    }
    .ibe-user-card {
      display: flex; align-items: center; gap: 10px;
      background: rgba(255,255,255,.1); border-radius: 10px;
      padding: 10px 12px;
    }
    .ibe-user-avatar {
      width: 34px; height: 34px; border-radius: 50%; object-fit: cover;
      border: 2px solid rgba(255,255,255,.35);
    }
    .ibe-user-name { color: #fff; font-size: 12.5px; font-weight: 600; line-height: 1.3; }
    .ibe-user-role { color: rgba(255,255,255,.55); font-size: 10.5px; }

    /* ── Overlay ─────────────────────────────────────────── */
    .ibe-overlay {
      display: none; position: fixed; inset: 0; z-index: 40;
      background: rgba(0,0,0,.45);
    }

    /* ── Main area ───────────────────────────────────────── */
    .ibe-main { margin-left: 260px; min-height: 100vh; background: #f4f6f8; display: flex; flex-direction: column; }
    .ibe-topbar {
      position: sticky; top: 0; z-index: 30;
      background: #fff; border-bottom: 1px solid #e8ecf0;
      padding: 0 24px; height: 60px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .ibe-topbar-left { display: flex; align-items: center; gap: 12px; }
    .ibe-topbar-title { font-size: 15px; font-weight: 600; color: #1a2332; }
    .ibe-topbar-title h1, .ibe-topbar-title h2, .ibe-topbar-title h3 {
      font-size: 15px !important; font-weight: 600 !important;
      color: #1a2332 !important; margin: 0 !important; line-height: 1.4 !important;
    }
    .ibe-topbar-right { display: flex; align-items: center; gap: 8px; }

    .ibe-hamburger {
      display: none; background: none; border: none; padding: 6px; cursor: pointer;
      border-radius: 8px; color: #555; line-height: 0;
    }
    .ibe-hamburger:hover { background: #f0f0f0; }

    .ibe-content { flex: 1; padding: 28px 28px 40px; }

    /* ── User dropdown ───────────────────────────────────── */
    .ibe-user-btn {
      display: flex; align-items: center; gap: 8px;
      background: none; border: 1px solid #e4e8ed; border-radius: 10px;
      padding: 5px 10px 5px 6px; cursor: pointer; transition: all .15s;
    }
    .ibe-user-btn:hover { background: #f5f7fa; border-color: #c8d0dc; }
    .ibe-user-btn img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
    .ibe-user-btn-name { font-size: 12.5px; font-weight: 600; color: #2d3748; }
    .ibe-user-btn-role { font-size: 10px; color: #8a9bb0; }

    .ibe-dropdown {
      position: absolute; right: 0; top: calc(100% + 8px);
      background: #fff; border-radius: 12px; min-width: 180px;
      box-shadow: 0 8px 32px rgba(0,0,0,.12); border: 1px solid #e8ecf0;
      z-index: 200; padding: 6px 0; display: none;
    }
    .ibe-dropdown.open { display: block; }
    .ibe-dropdown-item {
      display: flex; align-items: center; gap: 8px;
      padding: 9px 14px; font-size: 13px; color: #374151; text-decoration: none;
      transition: background .1s;
    }
    .ibe-dropdown-item:hover { background: #f5f7fa; text-decoration: none; color: #1a7a8c; }
    .ibe-dropdown-item.danger { color: #dc2626; }
    .ibe-dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
    .ibe-dropdown hr { margin: 4px 0; border-color: #f0f0f0; }

    /* ── Role badge ──────────────────────────────────────── */
    .ibe-role-badge {
      font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 20px;
      text-transform: uppercase; letter-spacing: .5px;
    }
    .ibe-role-badge.superadmin { background: #fef3c7; color: #b45309; }
    .ibe-role-badge.admin      { background: #dbeafe; color: #1d4ed8; }
    .ibe-role-badge.finance    { background: #d1fae5; color: #065f46; }

    @keyframes pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50%       { opacity: .5; transform: scale(1.3); }
    }

    /* ── Mobile ──────────────────────────────────────────── */
    @media (max-width: 767px) {
      .ibe-hamburger  { display: flex; align-items: center; justify-content: center; }
      .ibe-sidebar    { transform: translateX(-100%); }
      .ibe-sidebar.open { transform: translateX(0); }
      .ibe-overlay.open { display: block; }
      .ibe-main       { margin-left: 0; }
      .ibe-content    { padding: 16px 14px 32px; }
      .ibe-user-btn-name, .ibe-user-btn-role { display: none; }
    }
  </style>
</head>

<body class="font-sans antialiased">

@if (Auth::check() && Auth::user()->isAdmin)

  {{-- ════════════════════════════════════════════════════════════ --}}
  {{--  ADMIN PANEL LAYOUT                                         --}}
  {{-- ════════════════════════════════════════════════════════════ --}}

  @php
    $u     = Auth::user();
    $route = request()->route()?->getName() ?? '';

    $roleLabel = match($u->group) {
      'superadmin' => 'Super Admin',
      'admin'      => 'HR Admin',
      'finance'    => 'Finance',
      default      => ucfirst($u->group),
    };
    $roleClass = match($u->group) {
      'superadmin' => 'superadmin',
      'admin'      => 'admin',
      'finance'    => 'finance',
      default      => 'admin',
    };

    $navActive = fn(string $pattern): string => request()->routeIs($pattern) ? 'active' : '';
  @endphp

  <div id="ibe-sidebar" class="ibe-sidebar">
    {{-- Logo --}}
    <div class="ibe-sidebar-logo">
      <img src="{{ asset('asset/images/logo.png') }}" alt="IBE Reality">
      <div>
        <div class="ibe-sidebar-logo-text">IBE Reality</div>
        <div class="ibe-sidebar-logo-sub">Admin Panel</div>
      </div>
    </div>

    {{-- Navigation --}}
    <nav class="ibe-sidebar-nav">

      {{-- MAIN --}}
      <div class="ibe-nav-section">Utama</div>

      <a href="{{ route('admin.dashboard') }}" class="ibe-nav-item {{ $navActive('admin.dashboard') }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
        Dashboard
      </a>

      @if ($u->isHr || $u->isSuperadmin)
        <a href="{{ route('admin.attendances') }}" class="ibe-nav-item {{ $navActive('admin.attendances*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          Kehadiran
        </a>
        <a href="{{ route('admin.employees') }}" class="ibe-nav-item {{ $navActive('admin.employees*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.768-.231-1.48-.634-2.074M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.768.231-1.48.634-2.074M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          Karyawan
        </a>
      @endif

      @if ($u->isHr || $u->isSuperadmin)
        {{-- HR --}}
        <div class="ibe-nav-section" style="margin-top:6px">HR & Perizinan</div>
        <a href="{{ route('admin.leave') }}" class="ibe-nav-item {{ $navActive('admin.leave*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
          Cuti
        </a>
        <a href="{{ route('admin.overtime') }}" class="ibe-nav-item {{ $navActive('admin.overtime*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          Lembur
        </a>
        <a href="{{ route('admin.business-trip') }}" class="ibe-nav-item {{ $navActive('admin.business-trip*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064" /></svg>
          Perjalanan Dinas
        </a>
        <a href="{{ route('admin.client-visit') }}" class="ibe-nav-item {{ $navActive('admin.client-visit*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          Kunjungan Klien
        </a>
        <a href="{{ route('admin.salary-advance') }}" class="ibe-nav-item {{ $navActive('admin.salary-advance*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          Kasbon
        </a>
        <a href="{{ route('admin.reimbursement') }}" class="ibe-nav-item {{ $navActive('admin.reimbursement*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" /></svg>
          Reimbursement
        </a>
        <a href="{{ route('admin.face-approvals') }}" class="ibe-nav-item {{ $navActive('admin.face-approvals*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h.01M9 9h.01M8 13a4 4 0 008 0m2 8H6a2 2 0 01-2-2V7a5 5 0 015-5h6a5 5 0 015 5v12a2 2 0 01-2 2z" /></svg>
          Face Approval
        </a>
      @endif

      @if ($u->isFinance || $u->isSuperadmin)
        {{-- FINANCE --}}
        <div class="ibe-nav-section" style="margin-top:6px">Keuangan</div>
        <a href="{{ route('admin.payroll') }}" class="ibe-nav-item {{ $navActive('admin.payroll*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          Penggajian
        </a>
      @endif

      @if ($u->isHr || $u->isSuperadmin)
        {{-- MASTER DATA --}}
        <div class="ibe-nav-section" style="margin-top:6px">Master Data</div>
        <a href="{{ route('admin.masters.division') }}" class="ibe-nav-item {{ $navActive('admin.masters.division') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
          Divisi
        </a>
        <a href="{{ route('admin.masters.job-title') }}" class="ibe-nav-item {{ $navActive('admin.masters.job-title') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
          Jabatan
        </a>
        <a href="{{ route('admin.masters.education') }}" class="ibe-nav-item {{ $navActive('admin.masters.education') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path d="M12 14l9-5-9-5-9 5 9 5z" /><path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" /></svg>
          Pendidikan
        </a>
        <a href="{{ route('admin.masters.shift') }}" class="ibe-nav-item {{ $navActive('admin.masters.shift') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          Shift
        </a>
        <a href="{{ route('admin.shift-assignment') }}" class="ibe-nav-item {{ $navActive('admin.shift-assignment') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>
          Jadwal Shift
        </a>
        @if (\Illuminate\Support\Facades\Route::has('admin.masters.office-location'))
        <a href="{{ route('admin.masters.office-location') }}" class="ibe-nav-item {{ $navActive('admin.masters.office-location') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
          Lokasi Kantor
        </a>
        @endif
        <a href="{{ route('admin.import-export.users') }}" class="ibe-nav-item {{ $navActive('admin.import-export*') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" /></svg>
          Import & Export
        </a>
      @endif

      @if ($u->isSuperadmin)
        <div class="ibe-nav-section" style="margin-top:6px">Sistem</div>
        <a href="{{ route('admin.masters.admin') }}" class="ibe-nav-item {{ $navActive('admin.masters.admin') }}">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          Manajemen Admin
        </a>
      @endif

    </nav>

    {{-- User footer --}}
    <div class="ibe-sidebar-footer">
      <div class="ibe-user-card">
        <img src="{{ $u->profile_photo_url }}" alt="{{ $u->name }}" class="ibe-user-avatar">
        <div style="flex:1;min-width:0">
          <div class="ibe-user-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $u->name }}</div>
          <div class="ibe-user-role">{{ $roleLabel }}</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Overlay --}}
  <div id="ibe-overlay" class="ibe-overlay" onclick="closeSidebar()"></div>

  {{-- Main --}}
  <div class="ibe-main">

    {{-- Topbar --}}
    <header class="ibe-topbar">
      <div class="ibe-topbar-left">
        <button class="ibe-hamburger" onclick="toggleSidebar()" aria-label="Menu">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        @if (isset($header))
          <div class="ibe-topbar-title">{!! $header !!}</div>
        @endif
      </div>

      <div class="ibe-topbar-right">
        {{-- Language switcher --}}
        @php($locales = config('app.available_locales', []))
        @php($localeFlags = ['id' => '🇮🇩', 'en' => '🇬🇧'])
        @if(count($locales) > 1)
          <div style="position:relative" id="ibe-lang-dd-wrap">
            <button type="button" onclick="toggleLangDropdown(event)" style="background:none;border:1px solid #e4e8ed;border-radius:8px;padding:5px 10px;font-size:13px;cursor:pointer;">
              {{ $localeFlags[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}
            </button>
            <div id="ibe-lang-dd-menu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e8ecf0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:120px;z-index:200;padding:4px 0;">
              @foreach ($locales as $code => $label)
                <a href="{{ route('lang.switch', $code) }}" style="display:flex;align-items:center;gap:6px;padding:8px 14px;font-size:13px;color:#374151;text-decoration:none;" onmouseover="this.style.background='#f5f7fa'" onmouseout="this.style.background=''">
                  {{ $localeFlags[$code] ?? '' }} {{ $label }}
                </a>
              @endforeach
            </div>
          </div>
          <script>
            (function() {
              if (window.__langDdInit) return;
              window.__langDdInit = true;
              window.toggleLangDropdown = function(e) {
                e.stopPropagation();
                var menu = document.getElementById('ibe-lang-dd-menu');
                if (!menu) return;
                menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
              };
              document.addEventListener('click', function(e) {
                var wrap = document.getElementById('ibe-lang-dd-wrap');
                var menu = document.getElementById('ibe-lang-dd-menu');
                if (!wrap || !menu) return;
                if (!wrap.contains(e.target)) menu.style.display = 'none';
              });
              document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                  var menu = document.getElementById('ibe-lang-dd-menu');
                  if (menu) menu.style.display = 'none';
                }
              });
            })();
          </script>
        @endif

        {{-- User button --}}
        <div style="position:relative" id="ibe-user-dd-wrap">
          <button class="ibe-user-btn" onclick="toggleUserDropdown()">
            <img src="{{ $u->profile_photo_url }}" alt="{{ $u->name }}">
            <div>
              <div class="ibe-user-btn-name">{{ Str::words($u->name, 2, '') }}</div>
              <div class="ibe-user-btn-role">{{ $roleLabel }}</div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#8a9bb0"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </button>
          <div class="ibe-dropdown" id="ibe-user-dropdown">
            <div style="padding:10px 14px 8px;border-bottom:1px solid #f0f0f0;margin-bottom:4px">
              <div style="font-size:12.5px;font-weight:600;color:#1a2332">{{ $u->name }}</div>
              <div style="font-size:11px;color:#8a9bb0">{{ $u->email }}</div>
              <span class="ibe-role-badge {{ $roleClass }}" style="margin-top:4px;display:inline-block">{{ $roleLabel }}</span>
            </div>
            <a href="{{ route('profile.show') }}" class="ibe-dropdown-item">
              <svg width="15" height="15" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              Profil Saya
            </a>
            <hr>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="ibe-dropdown-item danger" style="width:100%;border:none;background:none;text-align:left;cursor:pointer">
                <svg width="15" height="15" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                Keluar
              </button>
            </form>
          </div>
        </div>
      </div>
    </header>

    {{-- Global modals --}}
    <div id="global-loading" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 backdrop-blur-sm">
      <div class="flex flex-col items-center gap-4 rounded-xl bg-white px-6 py-5 shadow-lg">
        <div class="relative h-20 w-20">
          <svg class="h-20 w-20 -rotate-90" viewBox="0 0 120 120">
            <circle cx="60" cy="60" r="52" class="fill-none stroke-slate-200" stroke-width="12" />
            <circle id="global-progress-ring" cx="60" cy="60" r="52" class="fill-none stroke-[#1a7a8c] transition-[stroke-dashoffset] duration-200" stroke-width="12" stroke-linecap="round" stroke-dasharray="327" stroke-dashoffset="327" />
          </svg>
          <div id="global-progress-text" class="absolute inset-0 flex items-center justify-center text-base font-semibold text-gray-800">0%</div>
        </div>
        <div class="text-sm font-medium text-gray-700">Memproses...</div>
      </div>
    </div>
    <div id="global-success-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30 backdrop-blur-[1px]">
      <div id="global-success-card" class="mx-4 flex w-full max-w-sm items-center gap-3 rounded-xl bg-white px-5 py-4 shadow-xl">
        <div id="global-success-icon" class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 text-green-700">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
        </div>
        <div class="flex-1">
          <div id="global-success-title" class="text-sm font-semibold text-gray-900">Berhasil</div>
          <div id="global-success-message" class="text-sm text-gray-600"></div>
        </div>
      </div>
    </div>

    {{-- Page content --}}
    <main class="ibe-content">
      {{ $slot }}
    </main>
  </div>

  <x-sigsegv-core-dumped />
  @stack('modals')
  @livewireScripts
  @stack('scripts')

  <script>
    function toggleSidebar() {
      document.getElementById('ibe-sidebar').classList.toggle('open');
      document.getElementById('ibe-overlay').classList.toggle('open');
    }
    function closeSidebar() {
      document.getElementById('ibe-sidebar').classList.remove('open');
      document.getElementById('ibe-overlay').classList.remove('open');
    }
    function toggleUserDropdown() {
      document.getElementById('ibe-user-dropdown').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
      var wrap = document.getElementById('ibe-user-dd-wrap');
      if (wrap && !wrap.contains(e.target)) {
        document.getElementById('ibe-user-dropdown')?.classList.remove('open');
      }
    });
  </script>

@else

  {{-- ════════════════════════════════════════════════════════════ --}}
  {{--  REGULAR USER LAYOUT (Jetstream-style)                      --}}
  {{-- ════════════════════════════════════════════════════════════ --}}

  <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
    @livewire('navigation-menu')

    @if (isset($header))
      <header class="bg-white shadow dark:bg-gray-800">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
          {{ $header }}
        </div>
      </header>
    @endif

    <div id="global-loading" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 backdrop-blur-sm">
      <div class="flex flex-col items-center gap-4 rounded-xl bg-white px-6 py-5 shadow-lg dark:bg-gray-800">
        <div class="relative h-20 w-20">
          <svg class="h-20 w-20 -rotate-90" viewBox="0 0 120 120">
            <circle cx="60" cy="60" r="52" class="fill-none stroke-slate-200" stroke-width="12" />
            <circle id="global-progress-ring" cx="60" cy="60" r="52" class="fill-none stroke-blue-600 transition-[stroke-dashoffset] duration-200" stroke-width="12" stroke-linecap="round" stroke-dasharray="327" stroke-dashoffset="327" />
          </svg>
          <div id="global-progress-text" class="absolute inset-0 flex items-center justify-center text-base font-semibold text-gray-800 dark:text-gray-100">0%</div>
        </div>
        <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Memproses...</div>
      </div>
    </div>
    <div id="global-success-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30 backdrop-blur-[1px]">
      <div id="global-success-card" class="mx-4 flex w-full max-w-sm items-center gap-3 rounded-xl bg-white px-5 py-4 shadow-xl dark:bg-gray-800">
        <div id="global-success-icon" class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 text-green-700">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
        </div>
        <div class="flex-1">
          <div id="global-success-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">Berhasil</div>
          <div id="global-success-message" class="text-sm text-gray-600 dark:text-gray-300"></div>
        </div>
      </div>
    </div>

    <main>{{ $slot }}</main>
  </div>

  <x-sigsegv-core-dumped />
  @stack('modals')
  @livewireScripts
  @stack('scripts')

@endif

</body>
</html>

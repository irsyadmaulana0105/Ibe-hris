# Attendance (`apps/attendance`)

Aplikasi PWA karyawan IBE Reality — absen face recognition, cuti, lembur, reimbursement, salary advance, payslip, dashboard.

Folder ini sebelumnya bernama `frontend/`, lalu `apps/workforce/`. Path canonical sekarang: `apps/attendance/`.

## Stack

- React 19 + Vite 6 + TypeScript 5.8
- Bootstrap 5.3 (styling) + Tailwind (utility mixed)
- `@tanstack/react-query` (server state)
- `@vladmandic/face-api` (client-side face recognition untuk absensi)
- `react-router-dom` v7

## Routes (dari `src/App.tsx`)

**Public/auth:**
- `/login` (via SSO redirect ke `apps/portal`)
- `/sso-callback` — landing dari portal ticket exchange
- `/invite/:token`, `/claim-email/:token` — onboarding
- `/contract-sign/:token` — TTD kontrak online

**Employee:**
- `/` — Dashboard
- `/attendance` — clock-in dengan face verification
- `/attendance/history` — riwayat absen
- `/leave`, `/overtime`, `/salary-advance`, `/reimbursement` — request
- `/business-trip`, `/client-visit` — perjalanan dinas
- `/payslip` — slip gaji
- `/id-card`, `/work-certificate` — dokumen HR
- `/face-setup` — enroll wajah untuk absensi
- `/profile/edit`

**Admin (HR):**
- `/admin/employees` — CRUD karyawan
- `/admin/attendance` — supervisi absen
- `/admin/shift-schedule` — jadwal shift
- `/admin/leave`, `/admin/overtime`, `/admin/salary-advance`, `/admin/reimbursement` — approve
- `/admin/payroll`, `/admin/late-deduction` — payroll & potongan
- `/admin/face` — kelola face descriptor karyawan
- `/admin/company`, `/admin/workspace` — setelan
- `/admin/audit-log`

## Environment

Copy `.env.example` → `.env.local`:

```env
VITE_API_BASE_URL=https://app-erp.ibereality.com
VITE_BUILD_OUT_DIR=dist
VITE_REQUIRE_SSO=true
VITE_SSO_PORTAL_URL=https://portal.ibereality.com/login
```

**Integrated deploy** ke Laravel `backend/public/app/`:
```env
VITE_BUILD_OUT_DIR=../../backend/public/app
```
(Note: path 2× `../` karena kita di `apps/attendance/`, backend di `../../backend/`)

## Commands

```bash
npm install
npm run dev            # http://localhost:5173
npm run build          # bundle production
npm run type-check     # tsc --noEmit
npm run lint
```

## Deploy target

`app-erp.ibereality.com/app`, `app-erp.ibereality.com/admin` (untuk role admin), dan `attendance.ibereality.com/`.
Ke-3 URL serve **build yang sama** — deploy pindahkan artifact ke:
- `/var/www/backend-erp/public/app/`
- `/var/www/backend-erp/public/admin/`
- `/var/www/attendance.ibereality.com/public/`

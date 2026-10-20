import { Link } from 'react-router-dom'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminSummaryCard from '@/components/admin/AdminSummaryCard'
import AdminTableCard from '@/components/admin/AdminTableCard'
import { usePageTitle } from '@/hooks/usePageTitle'

type MasterDataItem = {
  title: string
  description: string
  href: string
  mode: 'legacy' | 'react'
  status: 'active' | 'transition'
}

const CORE_DATA: MasterDataItem[] = [
  {
    title: 'Division',
    description: 'Struktur unit kerja yang dipakai lintas employee, approval, attendance, dan payroll.',
    href: '/admin/master-data/divisions',
    mode: 'react',
    status: 'active',
  },
  {
    title: 'Job Title',
    description: 'Posisi jabatan untuk struktur organisasi, payroll context, dan pelaporan.',
    href: '/admin/master-data/job-titles',
    mode: 'react',
    status: 'active',
  },
  {
    title: 'Education',
    description: 'Referensi pendidikan untuk profil karyawan dan filtering operasional.',
    href: '/admin/master-data/educations',
    mode: 'react',
    status: 'active',
  },
  {
    title: 'Shift',
    description: 'Master jam kerja yang menjadi fondasi absensi, keterlambatan, dan perhitungan payroll.',
    href: '/admin/master-data/shifts',
    mode: 'react',
    status: 'active',
  },
  {
    title: 'Inventory',
    description: 'Item operasional lintas lokasi dan jabatan untuk membuka pondasi modul ERP non-HR.',
    href: '/admin/master-data/inventories',
    mode: 'react',
    status: 'active',
  },
  {
    title: 'Admin Users',
    description: 'Manajemen akun admin legacy yang masih menopang area operasional tertentu.',
    href: '/admin/masterdata/admin',
    mode: 'legacy',
    status: 'transition',
  },
]

const GOVERNANCE_DATA: MasterDataItem[] = [
  {
    title: 'Company Settings',
    description: 'Identitas perusahaan, email keuangan, dan konfigurasi dasar tenant aktif.',
    href: '/admin/company',
    mode: 'react',
    status: 'active',
  },
]

function StatusBadge({ status, mode }: { status: MasterDataItem['status']; mode: MasterDataItem['mode'] }) {
  const label = status === 'active' ? 'Aktif' : 'Transisi'
  const background = status === 'active' ? '#dcfce7' : '#fef3c7'
  const color = status === 'active' ? '#166534' : '#92400e'
  const modeLabel = mode === 'react' ? 'React' : 'Legacy'

  return (
    <div className="d-flex align-items-center gap-2">
      <span className="badge rounded-pill" style={{ background, color, fontWeight: 700 }}>
        {label}
      </span>
      <span className="badge rounded-pill" style={{ background: '#eef2ff', color: '#3730a3', fontWeight: 700 }}>
        {modeLabel}
      </span>
    </div>
  )
}

export default function AdminMasterDataPage() {
  usePageTitle('Master Data')

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Master Data Workspace"
        title="ERP Reference Hub"
        subtitle="Boundary referensi inti untuk workforce, attendance, finance, payroll, dan reporting. Sebagian surface masih hybrid, tetapi source of truth-nya sudah diarahkan ke modul yang jelas."
        variant="finance"
      />

      <div className="row g-3 mb-4">
        <div className="col-md-4">
          <AdminSummaryCard
            label="Sasaran"
            value="Single Source of Truth"
            meta="Satu master data dipakai bersama oleh API, payroll, approval, attendance, dan laporan."
            variant="navy"
            compactValue
          />
        </div>
        <div className="col-md-4">
          <AdminSummaryCard
            label="Kondisi Saat Ini"
            value="Hybrid Legacy + React"
            meta="Company settings sudah React, sedangkan referensi organisasi inti masih berada di Livewire."
            variant="amber"
            compactValue
          />
        </div>
        <div className="col-md-4">
          <AdminSummaryCard
            label="Langkah Berikutnya"
            value="API + React Migration"
            meta="Setelah boundary stabil, tiap entitas dipindah ke service, API resource, dan halaman SPA penuh."
            variant="emerald"
            compactValue
          />
        </div>
      </div>

      <AdminTableCard
        title="Core Organizational Data"
        subtitle="Referensi utama yang dipakai lintas modul ERP."
        className="mb-4"
      >
          <div className="row g-3">
            {CORE_DATA.map((item) => (
              <div className="col-md-6" key={item.title}>
                <div className="border rounded-4 p-4 h-100" style={{ borderColor: '#e5e7eb' }}>
                  <div className="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                      <h5 className="mb-1" style={{ fontWeight: 800 }}>{item.title}</h5>
                      <p className="text-muted mb-0" style={{ fontSize: 13 }}>{item.description}</p>
                    </div>
                    <StatusBadge status={item.status} mode={item.mode} />
                  </div>
                  {item.mode === 'react' ? (
                    <Link to={item.href} className="btn btn-dark btn-sm">
                      Buka Data
                    </Link>
                  ) : (
                    <a href={item.href} className="btn btn-outline-dark btn-sm">
                      Buka Data
                    </a>
                  )}
                </div>
              </div>
            ))}
          </div>
      </AdminTableCard>

      <AdminTableCard
        title="Tenant Governance"
        subtitle="Area konfigurasi tenant dan boundary governance aktif."
        className="mb-4"
      >
          <div className="row g-3">
            {GOVERNANCE_DATA.map((item) => (
              <div className="col-md-6" key={item.title}>
                <div className="border rounded-4 p-4 h-100" style={{ borderColor: '#e5e7eb' }}>
                  <div className="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                      <h5 className="mb-1" style={{ fontWeight: 800 }}>{item.title}</h5>
                      <p className="text-muted mb-0" style={{ fontSize: 13 }}>{item.description}</p>
                    </div>
                    <StatusBadge status={item.status} mode={item.mode} />
                  </div>
                  <Link to={item.href} className="btn btn-dark btn-sm">
                    Buka Pengaturan
                  </Link>
                </div>
              </div>
            ))}
          </div>
      </AdminTableCard>

      <AdminTableCard
        title="Migration Note"
        subtitle="Boundary modul sudah dipisahkan, migrasi surface tinggal diratakan bertahap."
      >
          <div className="text-uppercase mb-2" style={{ fontSize: 11, letterSpacing: '0.08em', color: '#64748b' }}>
            Migration Note
          </div>
          <p className="mb-0" style={{ fontSize: 13, color: '#475569' }}>
            Halaman ini sengaja dibuat sebagai boundary modul terlebih dahulu. Target berikutnya adalah membuat API master data
            untuk entitas referensi berikutnya, lalu mengganti surface legacy yang tersisa tanpa memutus workflow berjalan.
          </p>
      </AdminTableCard>
    </div>
  )
}

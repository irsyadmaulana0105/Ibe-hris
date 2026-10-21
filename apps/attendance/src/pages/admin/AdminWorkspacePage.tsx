import { Link } from 'react-router-dom'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminTableCard from '@/components/admin/AdminTableCard'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'

interface ModuleCard {
  title: string
  description: string
  to: string
  capability: string
  accent: string
  items: string[]
}

const MODULES: ModuleCard[] = [
  {
    title: 'Workforce',
    description: 'Operasional SDM harian: absensi, cuti, lembur, dan approval wajah.',
    to: '/admin/attendances',
    capability: 'attendance.manage',
    accent: 'linear-gradient(135deg,#0f766e,#115e59)',
    items: ['Admin Absensi', 'Shift Scheduling', 'Employee Master', 'Approval Cuti', 'Approval Lembur', 'Approval Wajah'],
  },
  {
    title: 'Finance',
    description: 'Pengeluaran karyawan, kasbon, payroll, dan kontrol proses finansial.',
    to: '/admin/payrolls',
    capability: 'payroll.manage',
    accent: 'linear-gradient(135deg,#1d4ed8,#1e3a8a)',
    items: ['Approval Kasbon', 'Approval Reimburse', 'Payroll'],
  },
  {
    title: 'Compliance',
    description: 'Jejak audit, kontrol perubahan sensitif, dan pengawasan aktivitas admin.',
    to: '/admin/audit-logs',
    capability: 'audit_logs.view',
    accent: 'linear-gradient(135deg,#7c3aed,#581c87)',
    items: ['Audit Log', 'Review Aktivitas', 'Kontrol Jejak Sistem'],
  },
  {
    title: 'Master Data',
    description: 'Konfigurasi dasar perusahaan dan pondasi data untuk modul ERP berikutnya.',
    to: '/admin/master-data',
    capability: 'master_data.manage',
    accent: 'linear-gradient(135deg,#ea580c,#9a3412)',
    items: ['Division', 'Job Title', 'Education', 'Shift', 'Company Settings'],
  },
]

export default function AdminWorkspacePage() {
  usePageTitle('Admin Workspace')
  const { user } = useAuth()
  const capabilities = user?.permissions?.capabilities ?? []
  const visibleModules = MODULES.filter((module) => capabilities.includes(module.capability))

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="ERP Workspace"
        title="Area kerja admin berbasis modul"
        subtitle="Struktur ini dipakai untuk menggeser aplikasi dari portal absensi menjadi platform ERP bertahap. Setiap modul mewakili boundary kerja yang nanti bisa diperdalam ke service, API, dan reporting sendiri."
        variant="finance"
      />

      <AdminTableCard
        title="Modul Admin Aktif"
        subtitle="Modul yang tampil menyesuaikan capability akun admin."
      >
        <div className="row g-3">
        {visibleModules.map((module) => (
          <div className="col-md-6" key={module.title}>
            <div className="card border-0 h-100 shadow-sm" style={{ overflow: 'hidden' }}>
              <div className="card-body p-0">
                <div className="p-4" style={{ background: module.accent, color: '#fff' }}>
                  <div style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.08em', opacity: 0.8 }}>
                    Module
                  </div>
                  <h4 className="mb-2" style={{ fontWeight: 800 }}>{module.title}</h4>
                  <p className="mb-0" style={{ fontSize: 13, opacity: 0.9 }}>{module.description}</p>
                </div>

                <div className="p-4">
                  <div className="mb-3 text-muted" style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.06em' }}>
                    Cakupan Saat Ini
                  </div>
                  <div className="d-flex flex-wrap gap-2 mb-4">
                    {module.items.map((item) => (
                      <span
                        key={item}
                        className="badge rounded-pill"
                        style={{ background: '#f3f4f6', color: '#374151', fontWeight: 600, padding: '8px 12px' }}
                      >
                        {item}
                      </span>
                    ))}
                  </div>

                  <Link to={module.to} className="btn btn-dark btn-sm">
                    Buka Modul
                  </Link>
                </div>
              </div>
            </div>
          </div>
        ))}
        </div>
      </AdminTableCard>

      {visibleModules.length === 0 && (
        <div className="card mt-3">
          <div className="card-body text-center py-5">
            <h5 className="mb-2">Belum ada modul admin yang tersedia</h5>
            <p className="text-muted mb-0" style={{ fontSize: 13 }}>
              Akun ini belum memiliki capability admin yang dapat digunakan di workspace ERP.
            </p>
          </div>
        </div>
      )}
    </div>
  )
}

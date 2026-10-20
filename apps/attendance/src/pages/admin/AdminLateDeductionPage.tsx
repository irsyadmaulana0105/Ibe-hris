import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { listCompanyTiers, listUserTiers, saveCompanyTiers, saveUserTiers, clearUserTiers, type LateTier } from '@/api/lateTiers'
import client from '@/api/client'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import LateTierEditor from '@/components/admin/LateTierEditor'
import { usePageTitle } from '@/hooks/usePageTitle'

interface UserOption { id: string; name: string; email: string }

export default function AdminLateDeductionPage() {
  usePageTitle('Potongan Terlambat')
  const qc = useQueryClient()
  const [tab, setTab] = useState<'company' | 'user'>('company')
  const [selectedUserId, setSelectedUserId] = useState<string | ''>('')
  const [successMsg, setSuccessMsg] = useState<string | null>(null)
  const [errorMsg, setErrorMsg] = useState<string | null>(null)

  const companyQ = useQuery({ queryKey: ['late-tiers-company'], queryFn: listCompanyTiers })
  const userQ = useQuery({
    queryKey: ['late-tiers-user', selectedUserId],
    queryFn: () => listUserTiers(selectedUserId as string),
    enabled: tab === 'user' && !!selectedUserId,
  })
  const usersQ = useQuery({
    queryKey: ['users-light-for-tier'],
    queryFn: async () => {
      const { data } = await client.get<{ data: UserOption[] }>('/admin/users-light')
      return data.data ?? []
    },
    enabled: tab === 'user',
  })

  const flash = (s: string) => { setSuccessMsg(s); setErrorMsg(null); setTimeout(() => setSuccessMsg(null), 3000) }
  const fail  = (e: any) => { setErrorMsg(e?.response?.data?.message ?? 'Gagal menyimpan'); setSuccessMsg(null) }

  const saveCompanyMut = useMutation({
    mutationFn: saveCompanyTiers,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['late-tiers-company'] }); flash('Tier company-default tersimpan') },
    onError: fail,
  })

  const saveUserMut = useMutation({
    mutationFn: (tiers: LateTier[]) => saveUserTiers(selectedUserId as string, tiers),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['late-tiers-user', selectedUserId] }); flash('Override per-user tersimpan') },
    onError: fail,
  })

  const clearUserMut = useMutation({
    mutationFn: () => clearUserTiers(selectedUserId as string),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['late-tiers-user', selectedUserId] }); flash('Override dihapus - kembali ke company default') },
    onError: fail,
  })

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Payroll Workspace"
        title="Potongan Terlambat"
        subtitle="Atur skala potongan gaji berdasarkan berapa menit terlambat. Tier company-default berlaku untuk semua karyawan; bisa di-override per orang kalau perlu."
        variant="finance"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <ul className="nav nav-pills mb-3 gap-2">
        <li className="nav-item">
          <button className={`nav-link ${tab === 'company' ? 'active' : ''}`} onClick={() => setTab('company')}>
            Default Company
          </button>
        </li>
        <li className="nav-item">
          <button className={`nav-link ${tab === 'user' ? 'active' : ''}`} onClick={() => setTab('user')}>
            Override per Karyawan
          </button>
        </li>
      </ul>

      {tab === 'company' && (
        <AdminPanelCard
          title="Tier Default Company"
          subtitle="Berlaku untuk semua karyawan kecuali yang punya override sendiri. Sortir dari rentang menit terkecil ke terbesar."
        >
          {companyQ.isLoading ? (
            <div className="text-muted">Memuat…</div>
          ) : (
            <LateTierEditor
              initial={companyQ.data ?? []}
              onSave={(tiers) => saveCompanyMut.mutate(tiers)}
              saving={saveCompanyMut.isPending}
            />
          )}
        </AdminPanelCard>
      )}

      {tab === 'user' && (
        <AdminPanelCard
          title="Override per Karyawan"
          subtitle="Pilih karyawan untuk membuat skala potongan khusus (mis. probation potongan lebih kecil). Tidak ada override = ikut company default."
        >
          <div className="mb-3" style={{ maxWidth: 360 }}>
            <label className="form-label">Karyawan</label>
            <select
              className="form-select form-select-sm"
              value={selectedUserId}
              onChange={(e) => setSelectedUserId(e.target.value)}
            >
              <option value="">- Pilih karyawan -</option>
              {(usersQ.data ?? []).map((u) => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
          </div>

          {selectedUserId && (
            userQ.isLoading ? (
              <div className="text-muted">Memuat…</div>
            ) : (
              <>
                {(userQ.data ?? []).length === 0 && (
                  <div className="alert alert-info py-2 px-3" style={{ fontSize: 13 }}>
                    Karyawan ini <strong>belum punya override</strong> - masih ikut company default. Edit + simpan untuk membuat override.
                  </div>
                )}
                <LateTierEditor
                  initial={userQ.data ?? []}
                  onSave={(tiers) => saveUserMut.mutate(tiers)}
                  saving={saveUserMut.isPending}
                />
                {(userQ.data ?? []).length > 0 && (
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-danger mt-2 ms-2"
                    onClick={() => {
                      if (confirm('Hapus override untuk karyawan ini? Kembali ke company default.')) clearUserMut.mutate()
                    }}
                    disabled={clearUserMut.isPending}
                  >
                    {clearUserMut.isPending ? 'Menghapus…' : 'Hapus Override'}
                  </button>
                )}
              </>
            )
          )}
        </AdminPanelCard>
      )}
    </div>
  )
}

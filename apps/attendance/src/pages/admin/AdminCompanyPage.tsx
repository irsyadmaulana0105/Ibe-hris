import { useState, useRef, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getCompany, updateCompany } from '@/api/admin'
import AdminPageHero from '@/components/admin/AdminPageHero'
import AdminPanelCard from '@/components/admin/AdminPanelCard'
import FeedbackAlert from '@/components/FeedbackAlert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { getErrorMessage } from '@/utils/apiError'

export default function AdminCompanyPage() {
  usePageTitle('Pengaturan Perusahaan')
  const { refreshUser } = useAuth()
  const qc = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)

  const { data: company, isLoading } = useQuery({
    queryKey: ['admin', 'company'],
    queryFn: getCompany,
  })

  const [form, setForm] = useState({
    name: '', address: '', city: '', phone: '', email: '', finance_email: '',
  })
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const [logoFile, setLogoFile] = useState<File | null>(null)
  const [successMsg, setSuccessMsg] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  useEffect(() => {
    if (company) {
      setForm({
        name: company.name ?? '',
        address: company.address ?? '',
        city: company.city ?? '',
        phone: company.phone ?? '',
        email: company.email ?? '',
        finance_email: company.finance_email ?? '',
      })
      setLogoPreview(company.logo_url)
    }
  }, [company])

  const save = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => fd.append(k, v))
      if (logoFile) fd.append('logo', logoFile)
      return updateCompany(fd)
    },
    onSuccess: async () => {
      setSuccessMsg('Data perusahaan berhasil disimpan.')
      setLogoFile(null)
      await refreshUser()
      qc.invalidateQueries({ queryKey: ['admin', 'company'] })
    },
    onError: (err: unknown) => {
      setErrorMsg(getErrorMessage(err, 'Gagal menyimpan.'))
    },
  })

  const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    setLogoFile(file)
    setLogoPreview(URL.createObjectURL(file))
  }

  const field = (label: string, key: keyof typeof form, type = 'text', placeholder = '') => (
    <div className="mb-3">
      <label className="form-label">{label}</label>
      <input
        type={type}
        className="form-control"
        placeholder={placeholder || label}
        value={form[key]}
        onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))}
      />
    </div>
  )

  if (isLoading) return <div className="text-center py-5 text-muted">Memuat data...</div>

  return (
    <div className="admin-page-shell">
      <AdminPageHero
        eyebrow="Governance Workspace"
        title="Pengaturan Perusahaan"
        subtitle="Kelola identitas tenant aktif, kontak utama, email finance, dan branding dasar perusahaan."
        variant="finance"
      />

      <FeedbackAlert type="success" message={successMsg} />
      <FeedbackAlert type="danger" message={errorMsg} />

      <AdminPanelCard
        title="Logo Perusahaan"
        subtitle="Unggah identitas visual perusahaan yang akan dipakai pada area admin dan dokumen terkait."
        className="mb-3"
      >
        <div className="d-flex align-items-center gap-4">
          <div style={{
            width: 96, height: 96, borderRadius: 16, background: '#f5f5f5',
            border: '2px dashed #ddd', display: 'flex', alignItems: 'center',
            justifyContent: 'center', overflow: 'hidden', flexShrink: 0,
          }}>
            {logoPreview
              ? <img src={logoPreview} alt="logo" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
              : <span style={{ fontSize: 32 }}>🏢</span>}
          </div>
          <div>
            <button
              className="btn btn-primary btn-sm mb-2"
              onClick={() => fileRef.current?.click()}
            >
              📷 Ganti Logo
            </button>
            <input
              ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp"
              style={{ display: 'none' }} onChange={handleLogoChange}
            />
            <div className="text-muted" style={{ fontSize: 11 }}>
              PNG, JPG, atau WebP. Maks 2 MB.<br />
              Disarankan: 512×512px, background transparan.
            </div>
          </div>
        </div>
      </AdminPanelCard>

      <AdminPanelCard
        title="Informasi Perusahaan"
        subtitle="Data identitas utama yang akan muncul sebagai profil tenant aktif."
        className="mb-3"
      >
          {field('Nama Perusahaan', 'name', 'text', 'PT. Nama Perusahaan')}
          {field('Alamat', 'address', 'text', 'Jl. Contoh No. 1')}
          {field('Kota', 'city', 'text', 'Surabaya')}
          {field('No. Telepon', 'phone', 'tel', '031-xxxxxxx')}
      </AdminPanelCard>

      <AdminPanelCard
        title="Kontak Email"
        subtitle="Pisahkan email umum dan email finance untuk kebutuhan operasional serta distribusi slip payroll."
        className="mb-4"
      >
          {field('Email Utama', 'email', 'email', 'info@perusahaan.com')}
          {field('Email Keuangan', 'finance_email', 'email', 'finance@perusahaan.com')}
      </AdminPanelCard>

      <button
        className="btn btn-primary w-100 py-3"
        style={{ fontWeight: 700, fontSize: 15, borderRadius: 14 }}
        onClick={() => { setSuccessMsg(''); setErrorMsg(''); save.mutate() }}
        disabled={save.isPending}
      >
        {save.isPending
          ? <><span className="spinner-border spinner-border-sm me-2" />Menyimpan...</>
          : '💾 Simpan Perubahan'}
      </button>
    </div>
  )
}

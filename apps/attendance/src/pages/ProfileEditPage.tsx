import { useState, useRef, useEffect } from 'react'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import client from '@/api/client'
import { getValidationErrors } from '@/utils/apiError'

interface WilayahItem { id: string; name: string }

export default function ProfileEditPage() {
  usePageTitle('Edit Profil')
  const { user, refreshUser } = useAuth()

  const [form, setForm] = useState({
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    gender: user?.gender ?? '',
    birth_date: user?.birth_date ?? '',
    birth_place: (user as any)?.birth_place ?? '',
    marital_status: (user as any)?.marital_status ?? '',
    address: user?.address ?? '',
    city: user?.city ?? '',
    province: (user as any)?.province ?? '',
    regency: (user as any)?.regency ?? '',
    district: (user as any)?.district ?? '',
    village: (user as any)?.village ?? '',
    postal_code: (user as any)?.postal_code ?? '',
    nip: user?.nip ?? '',
  })

  // Cascading dropdown wilayah Indonesia (Provinsi → Kab → Kec → Kel)
  const [provinces, setProvinces] = useState<WilayahItem[]>([])
  const [regencies, setRegencies] = useState<WilayahItem[]>([])
  const [districts, setDistricts] = useState<WilayahItem[]>([])
  const [villages, setVillages] = useState<WilayahItem[]>([])
  const [provinceId, setProvinceId] = useState('')
  const [regencyId, setRegencyId] = useState('')
  const [districtId, setDistrictId] = useState('')
  const [villageId, setVillageId] = useState('')

  const loadWilayah = async (path: string): Promise<WilayahItem[]> => {
    try {
      const { data } = await client.get(`/wilayah/${path}`)
      return (data?.data ?? []) as WilayahItem[]
    } catch { return [] }
  }

  // Initial load provinces + reverse-lookup nama → id untuk restore state
  useEffect(() => { loadWilayah('provinces').then(setProvinces) }, [])
  useEffect(() => {
    if (provinces.length && form.province) {
      const f = provinces.find(p => p.name === form.province)
      if (f && f.id !== provinceId) setProvinceId(f.id)
    }
  }, [provinces, form.province])
  useEffect(() => {
    if (!provinceId) { setRegencies([]); return }
    loadWilayah(`regencies/${provinceId}`).then(setRegencies)
  }, [provinceId])
  useEffect(() => {
    if (regencies.length && form.regency) {
      const f = regencies.find(r => r.name === form.regency)
      if (f && f.id !== regencyId) setRegencyId(f.id)
    }
  }, [regencies, form.regency])
  useEffect(() => {
    if (!regencyId) { setDistricts([]); return }
    loadWilayah(`districts/${regencyId}`).then(setDistricts)
  }, [regencyId])
  useEffect(() => {
    if (districts.length && form.district) {
      const f = districts.find(d => d.name === form.district)
      if (f && f.id !== districtId) setDistrictId(f.id)
    }
  }, [districts, form.district])
  useEffect(() => {
    if (!districtId) { setVillages([]); return }
    loadWilayah(`villages/${districtId}`).then(setVillages)
  }, [districtId])
  useEffect(() => {
    if (villages.length && form.village) {
      const f = villages.find(v => v.name === form.village)
      if (f && f.id !== villageId) setVillageId(f.id)
    }
  }, [villages, form.village])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState(false)
  const [savedMsg, setSavedMsg] = useState('')
  const [photoBusy, setPhotoBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  // Password change section
  const [pwd, setPwd] = useState({ current: '', next: '', confirm: '' })
  const [pwdErr, setPwdErr] = useState<string | null>(null)
  const [pwdOk, setPwdOk] = useState<string | null>(null)
  const [pwdBusy, setPwdBusy] = useState(false)
  const [pwnedCheck, setPwnedCheck] = useState<{ checking: boolean; pwned: boolean; count: number } | null>(null)

  // Auto-check via Have I Been Pwned (k-anonymity, password tidak dikirim full)
  useEffect(() => {
    if (!pwd.next || pwd.next.length < 8) { setPwnedCheck(null); return }
    setPwnedCheck({ checking: true, pwned: false, count: 0 })
    const timer = setTimeout(async () => {
      try {
        const { data } = await client.post('/password-pwned', { password: pwd.next })
        setPwnedCheck({
          checking: false,
          pwned: !!data?.data?.pwned,
          count: data?.data?.count ?? 0,
        })
      } catch {
        setPwnedCheck(null)
      }
    }, 500)  // debounce 500ms supaya tidak hit API tiap keystroke
    return () => clearTimeout(timer)
  }, [pwd.next])

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setSavedMsg('')
    try {
      await client.put('/auth/me', form)
      await refreshUser?.()
      setSavedMsg('Profil tersimpan.')
    } catch (err) {
      setErrors(getValidationErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault()
    setPwdErr(null); setPwdOk(null)
    if (pwd.next !== pwd.confirm) { setPwdErr('Konfirmasi password tidak cocok.'); return }
    if (pwd.next.length < 8) { setPwdErr('Password baru minimal 8 karakter.'); return }
    setPwdBusy(true)
    try {
      const { data } = await client.post<{ data: { mailbox_synced: boolean }; message: string }>('/auth/me/password', {
        current_password: pwd.current,
        new_password: pwd.next,
        new_password_confirmation: pwd.confirm,
      })
      setPwdOk(data.message ?? 'Password berhasil diganti.')
      setPwd({ current: '', next: '', confirm: '' })
    } catch (err: any) {
      setPwdErr(err?.response?.data?.errors?.current_password?.[0] ?? err?.response?.data?.message ?? 'Gagal ganti password.')
    } finally {
      setPwdBusy(false)
    }
  }

  const uploadPhoto = async (file: File) => {
    if (file.size > 5 * 1024 * 1024) {
      alert('Foto maksimal 5 MB')
      return
    }
    setPhotoBusy(true)
    try {
      const fd = new FormData()
      fd.append('photo', file)
      await client.post('/auth/me/photo', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      await refreshUser?.()
      setSavedMsg('Foto profil tersimpan.')
    } catch {
      alert('Gagal upload foto.')
    } finally {
      setPhotoBusy(false)
    }
  }

  if (!user) return null

  return (
    <div style={{ maxWidth: 640, margin: '0 auto' }}>
      <h5 style={{ fontWeight: 700, fontSize: 18, marginBottom: 4 }}>Edit Profil</h5>
      <p style={{ fontSize: 13, color: '#64748b', marginBottom: 18 }}>
        Lengkapi data diri kamu - dipakai untuk kartu identitas, kontrak kerja, dan slip gaji.
      </p>

      {/* QR signature card - auto-generated dari user id, dipakai di kontrak PDF */}
      <div className="card mb-3" style={{ borderRadius: 12 }}>
        <div className="card-body">
          <h6 style={{ fontWeight: 700, marginBottom: 4, fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 }}>
            🪪 Tanda Tangan Digital (QR Code)
          </h6>
          <p className="text-muted" style={{ fontSize: 12, marginBottom: 12 }}>
            QR ini muncul di kontrak kerja kamu sebagai tanda tangan digital. Siapa pun scan akan verifikasi identitas dari sistem.
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
            <img
              src={`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/v1/qr?data=${encodeURIComponent('https://app-erp.ibereality.com/verify/' + user.id)}&size=180`}
              alt="QR Signature"
              style={{ width: 140, height: 140, background: '#fff', padding: 6, border: '1px solid #e2e8f0', borderRadius: 8 }}
            />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 11, fontWeight: 700, color: '#475569', textTransform: 'uppercase', letterSpacing: '.04em', marginBottom: 4 }}>
                Verifikasi URL
              </div>
              <code style={{ fontSize: 10, wordBreak: 'break-all', color: '#0f172a', background: '#f1f5f9', padding: '4px 8px', borderRadius: 6, display: 'inline-block' }}>
                app-erp.ibereality.com/verify/{user.id?.slice(0, 16)}...
              </code>
              <div style={{ fontSize: 11, color: '#64748b', marginTop: 8, lineHeight: 1.5 }}>
                ✓ Auto-embed di kontrak kerja (PDF)<br />
                ✓ Auto-print di kartu identitas<br />
                ✓ Bisa di-scan untuk validasi cepat
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Photo upload */}
      <div className="card mb-3" style={{ borderRadius: 12 }}>
        <div className="card-body d-flex align-items-center gap-3">
          <img
            src={user.avatar ?? undefined}
            alt={user.name}
            style={{ width: 88, height: 88, borderRadius: '50%', objectFit: 'cover', background: '#cbd5e1' }}
          />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 700 }}>{user.name}</div>
            <div style={{ fontSize: 12, color: '#64748b' }}>JPG/PNG · maks 5 MB</div>
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png"
              hidden
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) uploadPhoto(f)
              }}
            />
            <button
              type="button"
              className="btn btn-sm btn-outline-primary mt-2"
              onClick={() => fileRef.current?.click()}
              disabled={photoBusy}
            >
              {photoBusy ? 'Mengupload...' : '📷 Ganti Foto Profil'}
            </button>
          </div>
        </div>
      </div>

      {/* Profile fields */}
      <form onSubmit={submit} className="card" style={{ borderRadius: 12 }}>
        <div className="card-body">
          {savedMsg && (
            <div className="alert alert-success" style={{ fontSize: 13 }}>{savedMsg}</div>
          )}

          <Field label="Nama Lengkap" error={errors.name?.[0]}>
            <input
              type="text"
              className={`form-control ${errors.name ? 'is-invalid' : ''}`}
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              required
            />
          </Field>

          <Field label="NIK (16 digit KTP)" error={errors.nip?.[0]}>
            <input
              type="text"
              inputMode="numeric"
              maxLength={20}
              className={`form-control ${errors.nip ? 'is-invalid' : ''}`}
              value={form.nip}
              onChange={(e) => setForm({ ...form, nip: e.target.value.replace(/\D/g, '') })}
              placeholder="Contoh: 3578035107020001"
            />
            <div className="form-text" style={{ fontSize: 11 }}>
              Dipakai untuk kontrak kerja & dokumen formal. Pastikan sesuai KTP.
            </div>
          </Field>

          <Field label="Nomor HP / WhatsApp" error={errors.phone?.[0]}>
            <input
              type="tel"
              className={`form-control ${errors.phone ? 'is-invalid' : ''}`}
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
              placeholder="08xxxxxxxxxx"
            />
          </Field>

          <div className="row g-2">
            <div className="col-6">
              <Field label="Jenis Kelamin" error={errors.gender?.[0]}>
                <select
                  className={`form-select ${errors.gender ? 'is-invalid' : ''}`}
                  value={form.gender}
                  onChange={(e) => setForm({ ...form, gender: e.target.value })}
                >
                  <option value="">-</option>
                  <option value="male">Laki-laki</option>
                  <option value="female">Perempuan</option>
                </select>
              </Field>
            </div>
            <div className="col-6">
              <Field label="Status Pernikahan" error={errors.marital_status?.[0]}>
                <select
                  className={`form-select ${errors.marital_status ? 'is-invalid' : ''}`}
                  value={form.marital_status}
                  onChange={(e) => setForm({ ...form, marital_status: e.target.value })}
                >
                  <option value="">-</option>
                  <option value="single">Belum Menikah</option>
                  <option value="married">Menikah</option>
                  <option value="widowed">Janda/Duda</option>
                  <option value="divorced">Cerai</option>
                </select>
              </Field>
            </div>
          </div>

          <div className="row g-2">
            <div className="col-6">
              <Field label="Tanggal Lahir" error={errors.birth_date?.[0]}>
                <input
                  type="date"
                  className={`form-control ${errors.birth_date ? 'is-invalid' : ''}`}
                  value={form.birth_date ?? ''}
                  onChange={(e) => setForm({ ...form, birth_date: e.target.value })}
                />
              </Field>
            </div>
            <div className="col-6">
              <Field label="Tempat Lahir" error={errors.birth_place?.[0]}>
                <input
                  type="text"
                  className={`form-control ${errors.birth_place ? 'is-invalid' : ''}`}
                  value={form.birth_place}
                  onChange={(e) => setForm({ ...form, birth_place: e.target.value })}
                  placeholder="Sidoarjo"
                />
              </Field>
            </div>
          </div>

          <Field label="Alamat Jalan (No / RT-RW / Patokan)" error={errors.address?.[0]}>
            <textarea
              className={`form-control ${errors.address ? 'is-invalid' : ''}`}
              value={form.address}
              onChange={(e) => setForm({ ...form, address: e.target.value })}
              rows={2}
              placeholder="Jl. Veteran No. 12, RT 03 / RW 05"
            />
          </Field>

          <div style={{ background: '#f1f5f9', borderRadius: 10, padding: '12px 14px', marginBottom: 12 }}>
            <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 8, color: '#0f172a' }}>
              📍 Wilayah Sesuai KTP
            </div>

            <Field label="Provinsi">
              <select
                className="form-select"
                value={provinceId}
                onChange={(e) => {
                  const id = e.target.value
                  setProvinceId(id); setRegencyId(''); setDistrictId(''); setVillageId('')
                  const sel = provinces.find(p => p.id === id)
                  setForm({ ...form, province: sel?.name ?? '', regency: '', district: '', village: '' })
                }}
              >
                <option value="">- Pilih provinsi -</option>
                {provinces.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>

            <Field label="Kabupaten / Kota">
              <select
                className="form-select"
                disabled={!provinceId}
                value={regencyId}
                onChange={(e) => {
                  const id = e.target.value
                  setRegencyId(id); setDistrictId(''); setVillageId('')
                  const sel = regencies.find(r => r.id === id)
                  setForm({ ...form, regency: sel?.name ?? '', district: '', village: '' })
                }}
              >
                <option value="">{provinceId ? '- Pilih kabupaten/kota -' : 'Pilih provinsi dulu'}</option>
                {regencies.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </Field>

            <Field label="Kecamatan">
              <select
                className="form-select"
                disabled={!regencyId}
                value={districtId}
                onChange={(e) => {
                  const id = e.target.value
                  setDistrictId(id); setVillageId('')
                  const sel = districts.find(d => d.id === id)
                  setForm({ ...form, district: sel?.name ?? '', village: '' })
                }}
              >
                <option value="">{regencyId ? '- Pilih kecamatan -' : 'Pilih kab/kota dulu'}</option>
                {districts.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </Field>

            <Field label="Kelurahan / Desa">
              <select
                className="form-select"
                disabled={!districtId}
                value={villageId}
                onChange={async (e) => {
                  const id = e.target.value
                  setVillageId(id)
                  const sel = villages.find(v => v.id === id)
                  const villageName = sel?.name ?? ''
                  setForm({ ...form, village: villageName })
                  // Auto-fetch kode pos
                  if (villageName && form.regency && form.district) {
                    try {
                      const { data } = await client.get('/wilayah/kodepos', {
                        params: { village: villageName, district: form.district, regency: form.regency },
                      })
                      const hits = (data?.data ?? []) as Array<{ code: string }>
                      if (hits.length > 0 && hits[0].code) {
                        setForm(prev => ({ ...prev, village: villageName, postal_code: hits[0].code }))
                      }
                    } catch { /* silent */ }
                  }
                }}
              >
                <option value="">{districtId ? '- Pilih kelurahan -' : 'Pilih kecamatan dulu'}</option>
                {villages.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
              </select>
            </Field>

            <Field label={`Kode Pos${form.postal_code ? ' · auto' : ''}`}>
              <input
                type="text"
                className="form-control"
                inputMode="numeric"
                maxLength={10}
                value={form.postal_code}
                onChange={(e) => setForm({ ...form, postal_code: e.target.value.replace(/\D/g, '') })}
                placeholder="60286"
              />
            </Field>

            <div style={{ fontSize: 10, color: '#64748b', marginTop: 6 }}>
              Data resmi Kemendagri RI · cached server-side 7 hari
            </div>
          </div>

          <Field label="Kota / Kabupaten (tampilan singkat di kartu identitas)" error={errors.city?.[0]}>
            <input
              type="text"
              className={`form-control ${errors.city ? 'is-invalid' : ''}`}
              value={form.city}
              onChange={(e) => setForm({ ...form, city: e.target.value })}
              placeholder="Otomatis dari Kabupaten/Kota di atas, atau isi manual"
            />
          </Field>

          <div className="d-grid mt-4">
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Menyimpan...' : 'Simpan Profil'}
            </button>
          </div>

          <p className="text-muted mt-3" style={{ fontSize: 11, lineHeight: 1.5 }}>
            Data yang dikelola HR (divisi, jabatan, status kepegawaian, gaji) tidak bisa diubah dari sini.
            Hubungi HR jika ada perubahan.
          </p>
        </div>
      </form>

      {/* ── Password change section ──────────────────────────────────── */}
      <form onSubmit={submitPassword} className="card mt-3" style={{ borderRadius: 12 }}>
        <div className="card-body">
          <h6 style={{ fontWeight: 700, marginBottom: 4 }}>🔒 Ganti Password</h6>
          <p className="text-muted" style={{ fontSize: 12 }}>
            Password akan disinkronkan ke <strong>portal absen</strong> dan <strong>webmail</strong> sekaligus
            (kalau kamu sudah punya email <code>@ibereality.com</code>).
          </p>

          {pwdOk && <div className="alert alert-success" style={{ fontSize: 13 }}>{pwdOk}</div>}
          {pwdErr && <div className="alert alert-danger" style={{ fontSize: 13 }}>{pwdErr}</div>}

          <Field label="Password Saat Ini">
            <input
              type="password"
              className="form-control"
              value={pwd.current}
              onChange={(e) => setPwd({ ...pwd, current: e.target.value })}
              autoComplete="current-password"
              required
            />
          </Field>

          <div className="row g-2">
            <div className="col-6">
              <Field label="Password Baru">
                <input
                  type="password"
                  className="form-control"
                  value={pwd.next}
                  onChange={(e) => setPwd({ ...pwd, next: e.target.value })}
                  autoComplete="new-password"
                  minLength={8}
                  required
                />
              </Field>
            </div>
            <div className="col-6">
              <Field label="Ulangi Password Baru">
                <input
                  type="password"
                  className="form-control"
                  value={pwd.confirm}
                  onChange={(e) => setPwd({ ...pwd, confirm: e.target.value })}
                  autoComplete="new-password"
                  minLength={8}
                  required
                />
              </Field>
            </div>
          </div>

          {pwnedCheck && !pwnedCheck.checking && (
            pwnedCheck.pwned ? (
              <div className="alert alert-danger d-flex align-items-start gap-2" style={{ fontSize: 12, padding: '8px 12px', marginBottom: 12 }}>
                <span style={{ fontSize: 16 }}>⚠️</span>
                <div>
                  <strong>Password ini pernah bocor di {pwnedCheck.count.toLocaleString('id-ID')} kebocoran data.</strong> Pilih password yang lain.
                  <div style={{ fontSize: 10, opacity: 0.8, marginTop: 2 }}>Cek aman via Have I Been Pwned (password kamu tidak pernah dikirim utuh)</div>
                </div>
              </div>
            ) : (
              <div className="alert alert-success d-flex align-items-center gap-2" style={{ fontSize: 12, padding: '6px 12px', marginBottom: 12 }}>
                <span>✓</span> Password aman, tidak ditemukan di database breach.
              </div>
            )
          )}
          {pwnedCheck?.checking && (
            <div className="text-muted" style={{ fontSize: 11, marginBottom: 12 }}>Mengecek keamanan password...</div>
          )}

          <div className="form-text mb-3" style={{ fontSize: 11 }}>
            Minimal 8 karakter. Gunakan kombinasi huruf, angka, dan simbol agar lebih aman.
          </div>

          <button type="submit" className="btn btn-outline-primary w-100" disabled={pwdBusy || pwnedCheck?.pwned === true}>
            {pwdBusy ? 'Memproses...' : pwnedCheck?.pwned ? '🔒 Password Tidak Aman' : 'Ganti Password'}
          </button>
        </div>
      </form>
    </div>
  )
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="mb-3">
      <label className="form-label" style={{ fontSize: 12, fontWeight: 600, color: '#475569' }}>{label}</label>
      {children}
      {error && <div className="invalid-feedback d-block">{error}</div>}
    </div>
  )
}

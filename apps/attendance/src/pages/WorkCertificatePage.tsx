import { useEffect, useState } from 'react'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import client from '@/api/client'

const IconFile = () => <svg fill="currentColor" viewBox="0 0 16 16" width="16" height="16"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5z"/></svg>
const IconDownload = () => <svg fill="currentColor" viewBox="0 0 16 16" width="16" height="16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/></svg>
const IconSpin = () => <svg className="animate-spin" fill="none" viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" strokeOpacity="0.25"/><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
const IconAward = () => <svg fill="currentColor" viewBox="0 0 16 16" width="20" height="20"><path d="M9.669.864L8 0 6.331.864l-1.858.282-.842 1.68-1.337 1.32L2.6 6l-.306 1.854 1.337 1.32.842 1.68 1.858.282L8 12l1.669-.864 1.858-.282.842-1.68 1.337-1.32L13.4 6l.306-1.854-1.337-1.32-.842-1.68zM8 10.93a4.93 4.93 0 1 1 0-9.86 4.93 4.93 0 0 1 0 9.86m0-8.71a3.78 3.78 0 1 0 0 7.56 3.78 3.78 0 0 0 0-7.56M4.32 11.85 0 16l3-1 1 3 3.25-3.25a5.87 5.87 0 0 1-2.93-2.9m7.36 0a5.87 5.87 0 0 1-2.93 2.9L12 18l1-3 3-1z"/></svg>
const IconAlert = () => <svg fill="currentColor" viewBox="0 0 16 16" width="16" height="16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>

type Cert = {
  id: number
  cert_number: string
  issued_date: string
  purpose: string | null
  signer_name: string
  download_url: string
}

/**
 * Halaman Surat Keterangan Kerja untuk karyawan.
 * Karyawan bisa:
 *   1. Klik "Terbitkan" (dengan optional purpose) → API generate → download PDF
 *   2. Lihat list surat yang pernah diterbitkan → re-download PDF
 */
export default function WorkCertificatePage() {
  usePageTitle('Surat Keterangan Kerja')
  const { user } = useAuth()
  const [certs, setCerts] = useState<Cert[]>([])
  const [loading, setLoading] = useState(true)
  const [issuing, setIssuing] = useState(false)
  const [purpose, setPurpose] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [downloadingId, setDownloadingId] = useState<number | null>(null)

  const fetchList = () => {
    setLoading(true)
    client.get<{ data: { certificates: Cert[] } }>('/me/work-certificate')
      .then((r) => setCerts(r.data.data?.certificates ?? []))
      .catch(() => setCerts([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => { fetchList() }, [])

  const issue = async () => {
    setIssuing(true)
    setErr(null)
    try {
      const res = await client.post<{ data: { certificate: Cert }; message: string }>(
        '/me/work-certificate',
        { purpose: purpose.trim() || null },
      )
      const newCert = res.data.data?.certificate
      // Auto-download setelah issue
      if (newCert?.id) await download(newCert.id)
      setPurpose('')
      fetchList()
    } catch (e: any) {
      setErr(e?.response?.data?.message ?? 'Gagal menerbitkan surat.')
    } finally {
      setIssuing(false)
    }
  }

  const download = async (id: number) => {
    setDownloadingId(id)
    try {
      const res = await client.get(`/me/work-certificate/${id}/download`, { responseType: 'blob' })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `surat-keterangan-kerja-${(user?.name ?? 'karyawan').toLowerCase().replace(/\s+/g, '-')}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e: any) {
      setErr(e?.response?.data?.message ?? 'Gagal download.')
    } finally {
      setDownloadingId(null)
    }
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-6 sm:py-8">
      {/* Header */}
      <div className="mb-6">
        <div className="flex items-center gap-3">
          <div className="size-11 rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 grid place-items-center text-white shadow-md">
            <IconAward />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Surat Keterangan Kerja</h1>
            <p className="text-sm text-slate-500">
              Dokumen resmi yang menyatakan Anda karyawan aktif. Ditandatangani Direktur Utama.
            </p>
          </div>
        </div>
      </div>

      {/* Issue card */}
      <div className="bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 shadow-sm mb-6">
        <h2 className="font-semibold text-slate-900 mb-1">Terbitkan Surat Baru</h2>
        <p className="text-xs text-slate-500 mb-4">
          Isi keperluan (opsional) supaya tercantum di surat. Kalau kosong, akan pakai kalimat umum.
        </p>
        <div className="space-y-3">
          <div>
            <label htmlFor="purpose" className="block text-xs font-medium text-slate-600 mb-1.5">
              Keperluan (opsional)
            </label>
            <input
              id="purpose"
              type="text"
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              placeholder="Contoh: buka rekening bank, pengajuan visa, KPR"
              maxLength={500}
              disabled={issuing}
              className="w-full h-11 px-3.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 disabled:opacity-50"
            />
          </div>
          {err && (
            <div className="flex items-start gap-2 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
              <span className="mt-0.5 shrink-0"><IconAlert /></span>
              <span>{err}</span>
            </div>
          )}
          <button
            type="button"
            onClick={issue}
            disabled={issuing}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 h-11 px-5 rounded-full bg-teal-600 hover:bg-teal-700 active:bg-teal-800 text-white font-semibold text-sm shadow-md shadow-teal-500/30 disabled:opacity-50 transition-all"
          >
            {issuing ? (
              <>
                <IconSpin />
                Menerbitkan…
              </>
            ) : (
              <>
                <IconFile />
                Terbitkan & Download PDF
              </>
            )}
          </button>
        </div>
      </div>

      {/* History */}
      <div className="bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 shadow-sm">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-semibold text-slate-900">Riwayat Surat</h2>
          <span className="text-xs text-slate-500">{certs.length} surat</span>
        </div>
        {loading ? (
          <div className="flex items-center justify-center py-8 text-slate-400">
            <IconSpin />
          </div>
        ) : certs.length === 0 ? (
          <p className="text-sm text-slate-500 text-center py-6">
            Belum ada surat yang pernah diterbitkan.
          </p>
        ) : (
          <div className="divide-y divide-slate-100">
            {certs.map((c) => (
              <div key={c.id} className="flex items-center gap-3 py-3">
                <div className="flex-1 min-w-0">
                  <div className="font-medium text-sm text-slate-900 truncate">{c.cert_number}</div>
                  <div className="text-xs text-slate-500 mt-0.5">
                    {new Date(c.issued_date).toLocaleDateString('id-ID', {
                      day: 'numeric', month: 'long', year: 'numeric',
                    })}
                    {c.purpose && <span> · {c.purpose}</span>}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => download(c.id)}
                  disabled={downloadingId === c.id}
                  className="shrink-0 inline-flex items-center gap-1.5 h-9 px-3 rounded-full border border-slate-300 hover:bg-slate-50 text-sm font-medium text-slate-700 disabled:opacity-50"
                >
                  {downloadingId === c.id ? <IconSpin /> : <IconDownload />}
                  PDF
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

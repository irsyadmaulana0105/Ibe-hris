import { useEffect, useState } from 'react'
import type { LateTier } from '@/api/lateTiers'

interface Props {
  initial: LateTier[]
  onSave: (tiers: LateTier[]) => void
  saving?: boolean
}

const emptyTier = (): LateTier => ({ min_minutes: 0, max_minutes: null, deduction_amount: 0, label: '' })

interface Preset { name: string; description: string; tiers: LateTier[] }

const PRESETS: Preset[] = [
  {
    name: 'Sesuai Kontrak Permanent (IBE)',
    description: 'Telat 1–10 menit toleransi (tidak masuk tier). 10–30 menit sanksi ringan, 30–60 menit sedang, >60 menit berat. Matches Pasal 4 kontrak kerja.',
    tiers: [
      { min_minutes: 10, max_minutes: 30,   deduction_amount: 25000, label: 'Telat ringan' },
      { min_minutes: 30, max_minutes: 60,   deduction_amount: 50000, label: 'Telat sedang' },
      { min_minutes: 60, max_minutes: null, deduction_amount: 100000, label: 'Telat berat' },
    ],
  },
  {
    name: 'Standar',
    description: '15–30 menit Rp 10rb, 30–60 menit Rp 25rb, >60 menit Rp 50rb. Cocok untuk kebijakan toleransi 15 menit.',
    tiers: [
      { min_minutes: 15, max_minutes: 30,   deduction_amount: 10000, label: 'Telat ringan' },
      { min_minutes: 30, max_minutes: 60,   deduction_amount: 25000, label: 'Telat sedang' },
      { min_minutes: 60, max_minutes: null, deduction_amount: 50000, label: 'Telat berat' },
    ],
  },
]

export default function LateTierEditor({ initial, onSave, saving }: Props) {
  const [tiers, setTiers] = useState<LateTier[]>(initial.length ? initial : [emptyTier()])

  useEffect(() => {
    setTiers(initial.length ? initial : [emptyTier()])
  }, [initial])

  const update = (i: number, key: keyof LateTier, value: any) => {
    setTiers((prev) => prev.map((t, idx) => idx === i ? { ...t, [key]: value } : t))
  }

  const add = () => setTiers((prev) => [...prev, emptyTier()])
  const remove = (i: number) => setTiers((prev) => prev.filter((_, idx) => idx !== i))
  const applyPreset = (preset: LateTier[]) => setTiers(preset.map((t) => ({ ...t })))

  const errors: string[] = []
  // Validasi cepat: min < max, urutan rapi, no overlap
  for (let i = 0; i < tiers.length; i++) {
    const t = tiers[i]
    if (t.min_minutes < 1) errors.push(`Tier ${i + 1}: minimal harus ≥ 1 menit`)
    if (t.max_minutes !== null && t.max_minutes <= t.min_minutes) errors.push(`Tier ${i + 1}: max harus > min`)
    if (i > 0 && t.min_minutes < (tiers[i - 1].max_minutes ?? Infinity)) {
      errors.push(`Tier ${i + 1}: min (${t.min_minutes}) overlap dengan max tier sebelumnya (${tiers[i - 1].max_minutes})`)
    }
  }

  const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n)

  return (
    <div>
      <div className="d-flex flex-wrap gap-2 mb-3">
        {PRESETS.map((p) => (
          <button
            key={p.name}
            type="button"
            className="btn btn-sm btn-outline-secondary"
            onClick={() => applyPreset(p.tiers)}
            title={p.description}
          >
            {p.name}
          </button>
        ))}
      </div>

      <div className="table-responsive">
        <table className="table table-sm align-middle">
          <thead className="table-light">
            <tr>
              <th style={{ width: 30 }}>#</th>
              <th>Label</th>
              <th>Min menit</th>
              <th>Max menit (kosong = tanpa batas)</th>
              <th>Potongan (Rp)</th>
              <th style={{ width: 50 }}></th>
            </tr>
          </thead>
          <tbody>
            {tiers.map((t, i) => (
              <tr key={i}>
                <td>{i + 1}</td>
                <td>
                  <input
                    type="text"
                    className="form-control form-control-sm"
                    placeholder="Telat ringan"
                    value={t.label ?? ''}
                    onChange={(e) => update(i, 'label', e.target.value)}
                  />
                </td>
                <td>
                  <input
                    type="number"
                    min={1}
                    className="form-control form-control-sm"
                    value={t.min_minutes}
                    onChange={(e) => update(i, 'min_minutes', parseInt(e.target.value, 10) || 0)}
                  />
                </td>
                <td>
                  <input
                    type="number"
                    min={1}
                    className="form-control form-control-sm"
                    value={t.max_minutes ?? ''}
                    placeholder="kosong = unlimited"
                    onChange={(e) => update(i, 'max_minutes', e.target.value === '' ? null : (parseInt(e.target.value, 10) || 0))}
                  />
                </td>
                <td>
                  <input
                    type="number"
                    min={0}
                    step={1000}
                    className="form-control form-control-sm"
                    value={t.deduction_amount}
                    onChange={(e) => update(i, 'deduction_amount', parseInt(e.target.value, 10) || 0)}
                  />
                </td>
                <td>
                  <button
                    type="button"
                    className="btn btn-sm btn-link text-danger p-0"
                    onClick={() => remove(i)}
                    disabled={tiers.length === 1}
                    title={tiers.length === 1 ? 'Minimal 1 tier' : 'Hapus tier'}
                  >
                    ×
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <button type="button" className="btn btn-sm btn-outline-primary mb-3" onClick={add}>
        + Tambah Tier
      </button>

      {errors.length > 0 && (
        <div className="alert alert-warning py-2 px-3" style={{ fontSize: 13 }}>
          <strong>Validasi:</strong>
          <ul className="mb-0">
            {errors.map((e, i) => <li key={i}>{e}</li>)}
          </ul>
        </div>
      )}

      <div className="bg-light rounded p-2 mb-3" style={{ fontSize: 12 }}>
        <strong>Preview:</strong> Karyawan yang telat akan dipotong gaji sesuai tier:
        <ul className="mb-0 mt-1">
          {tiers.map((t, i) => (
            <li key={i}>
              {t.label || `Tier ${i + 1}`}: {t.min_minutes}–{t.max_minutes ?? '∞'} menit → potong <strong>Rp {fmt(t.deduction_amount)}</strong> per kejadian
            </li>
          ))}
        </ul>
      </div>

      <button
        type="button"
        className="btn btn-dark btn-sm"
        onClick={() => onSave(tiers)}
        disabled={saving || errors.length > 0}
      >
        {saving ? 'Menyimpan…' : 'Simpan Tier'}
      </button>
    </div>
  )
}

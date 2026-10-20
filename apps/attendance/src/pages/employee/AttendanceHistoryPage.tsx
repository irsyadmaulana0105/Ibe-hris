import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getAttendanceCalendar, getAttendanceDetail } from '@/api/attendance'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { AttendanceCalendarDay, AttendanceCalendarStatus } from '@/types'
import client from '@/api/client'

interface HolidayRow {
  id: number
  date: string
  name: string
  type: 'nasional' | 'cuti_bersama' | 'perusahaan'
}

function monthString() {
  return new Date().toISOString().slice(0, 7)
}

const STATUS: Record<AttendanceCalendarStatus, { bg: string; color: string; dot: string; label: string }> = {
  present:  { bg: '#dcfce7', color: '#166534', dot: '#22c55e', label: 'Hadir' },
  late:     { bg: '#fef3c7', color: '#92400e', dot: '#f59e0b', label: 'Terlambat' },
  absent:   { bg: '#fee2e2', color: '#991b1b', dot: '#ef4444', label: 'Absen' },
  sick:     { bg: '#dbeafe', color: '#1d4ed8', dot: '#3b82f6', label: 'Sakit' },
  excused:  { bg: '#e9d5ff', color: '#6b21a8', dot: '#a855f7', label: 'Izin' },
  holiday:  { bg: '#e0f2fe', color: '#075985', dot: '#0ea5e9', label: 'Libur' },
  weekend:  { bg: '#f3f4f6', color: '#4b5563', dot: '#9ca3af', label: 'Weekend' },
  no_record:{ bg: '#fff7ed', color: '#9a3412', dot: '#f97316', label: 'Kosong' },
}

const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']

function buildCalendar(month: string, records: AttendanceCalendarDay[]) {
  const date = new Date(`${month}-01T00:00:00`)
  const year = date.getFullYear()
  const monthIndex = date.getMonth()
  const firstDay = new Date(year, monthIndex, 1)
  const leadingEmpty = (firstDay.getDay() + 6) % 7
  const cells: Array<{ date: string | null; record?: AttendanceCalendarDay }> = []
  const byDate = new Map(records.map((r) => [r.date, r]))

  for (let i = 0; i < leadingEmpty; i++) cells.push({ date: null })
  for (const record of records) cells.push({ date: record.date, record: byDate.get(record.date) })
  return cells
}

// Singkat nama hari libur supaya muat di sel kalender (2 baris × ~10 char).
// Strip kata umum + tahun + Hijriyah tail, sisakan inti.
function abbreviateHoliday(name: string): string {
  const map: Record<string, string> = {
    'Tahun Baru Masehi 2026': 'Tahun Baru',
    'Tahun Baru Imlek 2577 Kongzili': 'Imlek',
    'Isra Mikraj Nabi Muhammad SAW': 'Isra Mikraj',
    'Hari Suci Nyepi Tahun Baru Saka 1948': 'Nyepi',
    'Idul Fitri 1447 H (Hari Ke-1)': 'Idul Fitri 1',
    'Idul Fitri 1447 H (Hari Ke-2)': 'Idul Fitri 2',
    'Cuti Bersama Idul Fitri': 'Cuti Bersama',
    'Cuti Bersama Nyepi/Idul Fitri': 'Cuti Bersama',
    'Wafat Yesus Kristus (Jumat Agung)': 'Jumat Agung',
    'Paskah': 'Paskah',
    'Hari Buruh Internasional': 'Hari Buruh',
    'Kenaikan Yesus Kristus': 'Kenaikan Isa',
    'Cuti Bersama Kenaikan Yesus Kristus': 'Cuti Bersama',
    'Hari Raya Waisak 2570 BE': 'Waisak',
    'Cuti Bersama Waisak': 'Cuti Bersama',
    'Hari Lahir Pancasila': 'Pancasila',
    'Idul Adha 1447 H': 'Idul Adha',
    'Tahun Baru Islam 1448 H': 'Tahun Baru Islam',
    'Hari Kemerdekaan RI': 'HUT RI',
    'Maulid Nabi Muhammad SAW': 'Maulid Nabi',
    'Hari Raya Natal': 'Natal',
    'Cuti Bersama Natal': 'Cuti Bersama',
  }
  if (map[name]) return map[name]
  return name.length > 12 ? name.slice(0, 11) + '…' : name
}

function buildSummary(records: AttendanceCalendarDay[]) {
  const counts: Record<string, number> = {}
  const todayKey = new Date().toISOString().slice(0, 10)
  for (const r of records) {
    // Tanggal masa depan tidak dihitung (belum terjadi).
    if (r.date > todayKey) continue
    // Hari kosong di masa lalu = belum absen - masuk ke counter.
    counts[r.status] = (counts[r.status] ?? 0) + 1
  }
  return counts
}

const SUMMARY_ITEMS: { key: AttendanceCalendarStatus; label: string }[] = [
  { key: 'present',  label: 'Hadir' },
  { key: 'late',     label: 'Terlambat' },
  { key: 'sick',     label: 'Sakit' },
  { key: 'excused',  label: 'Izin' },
  { key: 'absent',   label: 'Absen' },
  { key: 'holiday',  label: 'Libur' },
  { key: 'no_record',label: 'Kosong' },
]

export default function AttendanceHistoryPage() {
  usePageTitle('Riwayat Absensi')
  const [month, setMonth] = useState(monthString())
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [showDetail, setShowDetail] = useState(false)

  const calendar = useQuery({
    queryKey: ['attendance-calendar', month],
    queryFn: () => getAttendanceCalendar(month),
  })

  // Fetch holidays for the current year - used to overlay tanggal merah pada kalender.
  const year = month.slice(0, 4)
  const holidaysQuery = useQuery({
    queryKey: ['holidays', year],
    queryFn: async () => {
      const { data } = await client.get<{ data: { rows: HolidayRow[] } }>('/holidays', { params: { year } })
      return data.data.rows
    },
  })
  const holidayByDate = useMemo(() => {
    const map = new Map<string, HolidayRow>()
    for (const h of holidaysQuery.data ?? []) map.set(h.date, h)
    return map
  }, [holidaysQuery.data])

  const detail = useQuery({
    queryKey: ['attendance-detail', selectedId],
    queryFn: () => getAttendanceDetail(selectedId as number),
    enabled: selectedId !== null,
  })

  const cells   = useMemo(() => buildCalendar(month, calendar.data ?? []), [calendar.data, month])
  const summary = useMemo(() => buildSummary(calendar.data ?? []), [calendar.data])

  function handleDayClick(cell: typeof cells[0]) {
    if (!cell.record?.id) return
    setSelectedId(cell.record.id)
    setShowDetail(true)
  }

  const monthLabel = new Date(`${month}-01`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })

  return (
    <>
      {/* ── Header ──────────────────────────────────────────────────── */}
      <div className="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h5 className="mb-0 fw-bold">Riwayat Absensi</h5>
          <p className="text-muted mb-0" style={{ fontSize: 12 }}>Kalender kehadiran bulanan</p>
        </div>
        <button className="btn btn-outline-secondary btn-sm print-hide" onClick={() => window.print()}>
          Print
        </button>
      </div>

      {/* ── Summary chips ────────────────────────────────────────────── */}
      <div className="d-flex flex-wrap gap-2 mb-3">
        {SUMMARY_ITEMS.map(({ key, label }) => (
          <div
            key={key}
            className="d-flex align-items-center gap-1 rounded-pill px-2 py-1"
            style={{ background: STATUS[key].bg, color: STATUS[key].color, fontSize: 12, fontWeight: 600 }}
          >
            <span
              style={{
                display: 'inline-block', width: 8, height: 8,
                borderRadius: '50%', background: STATUS[key].dot, flexShrink: 0,
              }}
            />
            {label}
            <span style={{ fontWeight: 800, marginLeft: 2 }}>{summary[key] ?? 0}</span>
          </div>
        ))}
      </div>

      {/* ── Calendar card ────────────────────────────────────────────── */}
      <div className="card mb-3">
        <div className="card-header d-flex align-items-center justify-content-between gap-2">
          <span className="fw-semibold" style={{ fontSize: 14 }}>{monthLabel}</span>
          <input
            className="form-control form-control-sm"
            style={{ maxWidth: 140 }}
            type="month"
            value={month}
            onChange={(e) => { setMonth(e.target.value); setSelectedId(null); setShowDetail(false) }}
          />
        </div>

        <div className="card-body p-2 p-md-3">
          {calendar.isLoading ? (
            <div className="d-flex justify-content-center py-5">
              <div className="spinner-border text-primary spinner-border-sm" />
            </div>
          ) : (
            <>
              {/* Day headers */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 3, marginBottom: 3 }}>
                {DAYS.map((d) => (
                  <div
                    key={d}
                    style={{
                      textAlign: 'center', fontSize: 11, fontWeight: 700,
                      color: '#6b7280', paddingBottom: 4,
                    }}
                  >
                    {d}
                  </div>
                ))}
              </div>

              {/* Calendar cells */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 4 }}>
                {cells.map((cell, index) => {
                  const holiday = cell.date ? holidayByDate.get(cell.date) : null
                  // Tanggal masa depan (di luar hari ini) tidak boleh menampilkan dot/status "Kosong" - belum terjadi.
                  const isFuture = cell.date ? new Date(cell.date + 'T00:00:00') > new Date(new Date().toDateString()) : false
                  const isPureKosong = cell.record?.status === 'no_record' && !cell.record?.is_recorded
                  const showBaseStatus = cell.record && !(isFuture && isPureKosong)
                  const baseStatus = showBaseStatus ? STATUS[cell.record!.status] : null
                  // Holiday overrides background ke merah, tapi tap tetap buka detail kalau ada record
                  const s = holiday
                    ? { bg: holiday.type === 'cuti_bersama' ? '#fed7aa' : '#fecaca', color: '#991b1b', dot: '#dc2626', label: holiday.name }
                    : baseStatus
                  const isSelected = cell.record?.id !== undefined && cell.record.id === selectedId
                  const clickable = !!cell.record?.is_recorded || !!holiday

                  return (
                    <button
                      key={cell.date ?? `empty-${index}`}
                      onClick={() => {
                        if (cell.record?.is_recorded) handleDayClick(cell)
                      }}
                      disabled={!clickable}
                      title={holiday ? `${holiday.name} (libur)` : undefined}
                      style={{
                        border: isSelected ? `2px solid #1a7a8c` : '1px solid #e5e7eb',
                        borderRadius: 10,
                        padding: '6px 4px',
                        background: s ? s.bg : cell.date ? '#f8fafc' : 'transparent',
                        color: s ? s.color : '#9ca3af',
                        cursor: clickable ? 'pointer' : 'default',
                        minHeight: 64,
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'flex-start',
                        gap: 3,
                        opacity: cell.date ? 1 : 0,
                        transition: 'transform .12s, box-shadow .12s',
                        position: 'relative',
                      }}
                      onMouseOver={(e) => {
                        if (clickable) {
                          e.currentTarget.style.transform = 'translateY(-1px)'
                          e.currentTarget.style.boxShadow = '0 4px 12px rgba(0,0,0,.08)'
                        }
                      }}
                      onMouseOut={(e) => {
                        e.currentTarget.style.transform = 'none'
                        e.currentTarget.style.boxShadow = 'none'
                      }}
                    >
                      {/* Date number */}
                      <span style={{
                        fontWeight: 800, fontSize: 14, lineHeight: 1,
                        color: holiday ? '#991b1b' : 'inherit',
                      }}>
                        {cell.date ? Number(cell.date.slice(-2)) : ''}
                      </span>

                      {/* Holiday badge - kompak, tidak terpotong jelek */}
                      {holiday && (
                        <span style={{
                          fontSize: 8, fontWeight: 700, lineHeight: 1.1,
                          textAlign: 'center', padding: '2px 4px',
                          background: 'rgba(255,255,255,.55)',
                          color: '#991b1b', borderRadius: 6,
                          maxWidth: '100%',
                          display: '-webkit-box',
                          WebkitLineClamp: 2,
                          WebkitBoxOrient: 'vertical',
                          overflow: 'hidden',
                        }}>
                          {abbreviateHoliday(holiday.name)}
                        </span>
                      )}

                      {/* Status dot kalau bukan libur tapi ada record */}
                      {!holiday && baseStatus && (
                        <span style={{
                          display: 'block', width: 6, height: 6,
                          borderRadius: '50%', background: baseStatus.dot,
                        }} />
                      )}

                      {/* Jam masuk (tampil di tablet+) */}
                      {!holiday && cell.record?.is_recorded && cell.record.time_in && (
                        <span
                          className="d-none d-sm-block"
                          style={{ fontSize: 10, lineHeight: 1, opacity: 0.85, fontWeight: 600 }}
                        >
                          {cell.record.time_in.slice(0, 5)}
                        </span>
                      )}

                      {/* Mobile: jam masuk kecil di bawah */}
                      {!holiday && cell.record?.is_recorded && cell.record.time_in && (
                        <span
                          className="d-block d-sm-none"
                          style={{ fontSize: 8, lineHeight: 1, opacity: 0.7, fontWeight: 700 }}
                        >
                          {cell.record.time_in.slice(0, 5)}
                        </span>
                      )}
                    </button>
                  )
                })}
              </div>

              {/* Legend */}
              <div className="d-flex flex-wrap gap-x-3 gap-2 mt-3 pt-2 border-top">
                {SUMMARY_ITEMS.map(({ key, label }) => (
                  <div key={key} className="d-flex align-items-center gap-1" style={{ fontSize: 11, color: '#6b7280' }}>
                    <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: 2, background: STATUS[key].dot }} />
                    {label}
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </div>

      {/* ── Detail bottom sheet (overlay full-width pada mobile, card pada desktop) ─── */}
      {showDetail && selectedId && (
        <div
          onClick={(e) => { if (e.target === e.currentTarget) { setShowDetail(false); setSelectedId(null) } }}
          style={{
            position: 'fixed', inset: 0, zIndex: 1400,
            background: 'rgba(15,23,42,.55)', backdropFilter: 'blur(2px)',
            display: 'flex', alignItems: 'flex-end', justifyContent: 'center',
          }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              background: '#fff', width: '100%', maxWidth: 640,
              borderRadius: '20px 20px 0 0', padding: '12px 16px 24px',
              maxHeight: '85vh', overflowY: 'auto',
              boxShadow: '0 -10px 40px rgba(0,0,0,.18)',
              animation: 'attsheet .22s ease-out',
            }}
          >
            <div style={{
              width: 40, height: 4, background: '#cbd5e1', borderRadius: 2,
              margin: '4px auto 14px',
            }} />

            {detail.isLoading ? (
              <div className="d-flex justify-content-center py-4">
                <div className="spinner-border text-primary spinner-border-sm" />
              </div>
            ) : detail.data ? (
              <>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
                  <div>
                    <div style={{ fontSize: 12, color: '#64748b', fontWeight: 600 }}>
                      {new Date(detail.data.date).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                    </div>
                    <span className="badge mt-1" style={{
                      background: STATUS[detail.data.status].bg,
                      color: STATUS[detail.data.status].color,
                      fontSize: 12, fontWeight: 700,
                    }}>
                      {STATUS[detail.data.status].label}
                    </span>
                  </div>
                  <button
                    className="btn-close"
                    onClick={() => { setShowDetail(false); setSelectedId(null) }}
                  />
                </div>

                {/* Selfie thumbnails */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                  <SelfieCard label="Masuk" time={detail.data.time_in} url={detail.data.selfie_in_url ?? null} address={detail.data.selfie_in_address ?? null} />
                  <SelfieCard label="Pulang" time={detail.data.time_out} url={detail.data.selfie_out_url ?? null} address={detail.data.selfie_out_address ?? null} />
                </div>

                {/* Info grid */}
                <div className="row g-2">
                  <InfoCell label="Shift" value={detail.data.shift ?? '-'} />
                  <InfoCell label="Catatan" value={detail.data.note ?? '-'} full />
                </div>
              </>
            ) : (
              <div className="text-danger text-center py-3">Gagal memuat detail.</div>
            )}
          </div>

          <style>{`@keyframes attsheet { from { transform: translateY(100%); } to { transform: translateY(0); } }`}</style>
        </div>
      )}

      <style>{`
        @media print {
          .print-hide { display: none !important; }
          .card { box-shadow: none !important; border: 1px solid #d1d5db !important; }
          body { background: #fff !important; }
        }
      `}</style>
    </>
  )
}

function SelfieCard({ label, time, url, address }: { label: string; time: string | null; url: string | null; address: string | null }) {
  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden', background: '#f8fafc' }}>
      <div style={{
        padding: '6px 10px', display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        background: '#fff', borderBottom: '1px solid #e2e8f0',
      }}>
        <span style={{ fontSize: 11, fontWeight: 700, color: '#475569' }}>{label}</span>
        <span style={{ fontSize: 12, fontWeight: 700, fontFamily: 'monospace' }}>{time ?? '-'}</span>
      </div>
      <div style={{ aspectRatio: '4/3', background: '#e2e8f0', display: 'grid', placeItems: 'center' }}>
        {url ? (
          <img src={url} alt={`Selfie ${label}`} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        ) : (
          <span style={{ fontSize: 11, color: '#94a3b8' }}>Belum ada foto</span>
        )}
      </div>
      {address && (
        <div style={{ padding: '6px 10px', fontSize: 10, color: '#64748b', lineHeight: 1.4 }}>
          📍 {address}
        </div>
      )}
    </div>
  )
}

function InfoCell({ label, value, full }: { label: string; value: string; full?: boolean }) {
  return (
    <div className={full ? 'col-12' : 'col-6'}>
      <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, padding: '8px 12px' }}>
        <div style={{ fontSize: 10, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '.06em' }}>{label}</div>
        <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 2 }}>{value}</div>
      </div>
    </div>
  )
}

type EmptyStateType = 'default' | 'leave' | 'overtime' | 'attendance' | 'salary' | 'reimburse' | 'face' | 'search'

interface Props {
  type?: EmptyStateType
  title?: string
  subtitle?: string
  action?: React.ReactNode
}

const illustrations: Record<EmptyStateType, React.ReactNode> = {
  default: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <rect x="20" y="15" width="80" height="65" rx="8" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <rect x="33" y="30" width="54" height="6" rx="3" fill="#1a7a8c" opacity="0.3"/>
      <rect x="33" y="42" width="38" height="6" rx="3" fill="#1a7a8c" opacity="0.2"/>
      <rect x="33" y="54" width="46" height="6" rx="3" fill="#1a7a8c" opacity="0.2"/>
      <circle cx="88" cy="78" r="16" fill="#fff" stroke="#1a7a8c" strokeWidth="1.5"/>
      <line x1="84" y1="74" x2="92" y2="82" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round"/>
      <line x1="92" y1="74" x2="84" y2="82" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  ),
  leave: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <rect x="18" y="20" width="84" height="68" rx="8" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <rect x="18" y="20" width="84" height="22" rx="8" fill="#1a7a8c" opacity="0.15"/>
      <rect x="18" y="33" width="84" height="9" fill="#1a7a8c" opacity="0.15"/>
      <rect x="35" y="14" width="8" height="14" rx="4" fill="#1a7a8c"/>
      <rect x="77" y="14" width="8" height="14" rx="4" fill="#1a7a8c"/>
      <rect x="30" y="52" width="14" height="12" rx="3" fill="#1a7a8c" opacity="0.2"/>
      <rect x="53" y="52" width="14" height="12" rx="3" fill="#1a7a8c" opacity="0.2"/>
      <rect x="76" y="52" width="14" height="12" rx="3" fill="#1a7a8c" opacity="0.2"/>
      <rect x="30" y="69" width="14" height="12" rx="3" fill="#1a7a8c" opacity="0.2"/>
    </svg>
  ),
  overtime: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <circle cx="60" cy="50" r="34" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <circle cx="60" cy="50" r="28" fill="none" stroke="#1a7a8c" strokeWidth="1" opacity="0.3"/>
      <line x1="60" y1="26" x2="60" y2="50" stroke="#1a7a8c" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="60" y1="50" x2="76" y2="58" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round" opacity="0.5"/>
      <circle cx="60" cy="50" r="3" fill="#1a7a8c"/>
      <rect x="42" y="12" width="36" height="8" rx="4" fill="#1a7a8c" opacity="0.15"/>
    </svg>
  ),
  attendance: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <circle cx="60" cy="32" r="18" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <circle cx="60" cy="32" r="10" fill="#1a7a8c" opacity="0.2"/>
      <path d="M26 85c0-18.8 15.2-34 34-34s34 15.2 34 34" stroke="#1a7a8c" strokeWidth="1.5" fill="#e8f4f6" strokeLinecap="round"/>
      <path d="M26 85c0-18.8 15.2-34 34-34s34 15.2 34 34" fill="#e8f4f6"/>
      <circle cx="88" cy="76" r="14" fill="#fff" stroke="#1a7a8c" strokeWidth="1.5"/>
      <line x1="84" y1="72" x2="92" y2="80" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round"/>
      <line x1="92" y1="72" x2="84" y2="80" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  ),
  salary: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <rect x="16" y="28" width="88" height="52" rx="8" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <rect x="16" y="38" width="88" height="14" fill="#1a7a8c" opacity="0.12"/>
      <circle cx="60" cy="62" r="12" fill="none" stroke="#1a7a8c" strokeWidth="1.5" opacity="0.4"/>
      <text x="60" y="67" textAnchor="middle" fontSize="13" fontWeight="700" fill="#1a7a8c" opacity="0.4" fontFamily="Arial">Rp</text>
    </svg>
  ),
  reimburse: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <rect x="28" y="12" width="64" height="82" rx="6" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <path d="M28 24 L38 12" stroke="#1a7a8c" strokeWidth="1.5"/>
      <rect x="38" y="12" width="10" height="12" rx="2" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <rect x="38" y="36" width="44" height="5" rx="2.5" fill="#1a7a8c" opacity="0.25"/>
      <rect x="38" y="46" width="32" height="5" rx="2.5" fill="#1a7a8c" opacity="0.2"/>
      <rect x="38" y="56" width="38" height="5" rx="2.5" fill="#1a7a8c" opacity="0.2"/>
      <rect x="38" y="70" width="44" height="14" rx="4" fill="#1a7a8c" opacity="0.12"/>
    </svg>
  ),
  face: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <circle cx="60" cy="45" r="32" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5" strokeDasharray="6 3"/>
      <circle cx="60" cy="42" r="16" fill="none" stroke="#1a7a8c" strokeWidth="1.5" opacity="0.4"/>
      <circle cx="52" cy="39" r="3" fill="#1a7a8c" opacity="0.4"/>
      <circle cx="68" cy="39" r="3" fill="#1a7a8c" opacity="0.4"/>
      <path d="M52 50 Q60 56 68 50" stroke="#1a7a8c" strokeWidth="2" fill="none" strokeLinecap="round" opacity="0.4"/>
    </svg>
  ),
  search: (
    <svg width="120" height="100" viewBox="0 0 120 100" fill="none">
      <circle cx="52" cy="46" r="28" fill="#e8f4f6" stroke="#1a7a8c" strokeWidth="1.5"/>
      <line x1="72" y1="66" x2="96" y2="90" stroke="#1a7a8c" strokeWidth="3" strokeLinecap="round"/>
      <line x1="42" y1="38" x2="62" y2="38" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round" opacity="0.35"/>
      <line x1="42" y1="46" x2="55" y2="46" stroke="#1a7a8c" strokeWidth="2" strokeLinecap="round" opacity="0.25"/>
    </svg>
  ),
}

const defaultLabels: Record<EmptyStateType, { title: string; subtitle: string }> = {
  default:    { title: 'Belum ada data',          subtitle: 'Data akan muncul di sini' },
  leave:      { title: 'Belum ada pengajuan cuti', subtitle: 'Ajukan cuti baru dengan tombol di atas' },
  overtime:   { title: 'Belum ada lembur',        subtitle: 'Ajukan lembur baru dengan tombol di atas' },
  attendance: { title: 'Belum ada absensi',        subtitle: 'Riwayat absen akan tampil di sini' },
  salary:     { title: 'Belum ada kasbon',         subtitle: 'Ajukan kasbon baru dengan tombol di atas' },
  reimburse:  { title: 'Belum ada reimburse',      subtitle: 'Ajukan reimburse dengan tombol di atas' },
  face:       { title: 'Tidak ada pendaftaran',    subtitle: 'Semua wajah sudah disetujui' },
  search:     { title: 'Tidak ditemukan',          subtitle: 'Coba ubah kata kunci pencarian' },
}

export default function EmptyState({ type = 'default', title, subtitle, action }: Props) {
  const labels = defaultLabels[type]
  return (
    <div className="empty-state">
      <div className="empty-state__visual">
        {illustrations[type]}
      </div>
      <div className="empty-state__title">
        {title ?? labels.title}
      </div>
      <div className="empty-state__subtitle">
        {subtitle ?? labels.subtitle}
      </div>
      {action && <div className="empty-state__action">{action}</div>}
    </div>
  )
}

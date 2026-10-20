// Generic status badge - supports approval workflows + reimburse 2-stage flow.
// Unknown statuses fall back to a neutral gray badge with the raw value.
type Status =
  | 'pending'
  | 'approved'
  | 'rejected'
  | 'hr_approved'       // reimburse: HR/Manager approved, waiting Finance
  | 'settled'           // reimburse: Finance transferred + receipt issued
  | 'paid'              // salary advance: Finance disbursed + journal posted
  | 'cancelled'
  | string

const styles: Record<string, string> = {
  pending:      'bg-warning text-dark',
  approved:     'bg-success',
  rejected:     'bg-danger',
  hr_approved:  'bg-info text-dark',
  settled:      'bg-success',
  paid:         'bg-success',
  cancelled:    'bg-secondary',
}

const labels: Record<string, string> = {
  pending:      'Menunggu',
  approved:     'Disetujui',
  rejected:     'Ditolak',
  hr_approved:  'Tunggu Pencairan',
  settled:      'Sudah Dicairkan',
  paid:         'Sudah Dicairkan',
  cancelled:    'Dibatalkan',
}

export default function StatusBadge({ status }: { status: Status }) {
  const style = styles[status] ?? 'bg-secondary'
  const label = labels[status] ?? status
  return <span className={`badge ${style}`}>{label}</span>
}

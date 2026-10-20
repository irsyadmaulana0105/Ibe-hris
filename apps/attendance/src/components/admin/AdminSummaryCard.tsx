type AdminSummaryCardProps = {
  label: string
  value: string
  meta: string
  variant: 'navy' | 'rose' | 'emerald' | 'amber'
  compactValue?: boolean
}

export default function AdminSummaryCard({
  label,
  value,
  meta,
  variant,
  compactValue = false,
}: AdminSummaryCardProps) {
  return (
    <div className={`admin-summary-card admin-summary-card--${variant} h-100`}>
      <div className="admin-summary-card__label">{label}</div>
      <div className={`admin-summary-card__value${compactValue ? ' admin-summary-card__value--compact' : ''}`}>
        {value}
      </div>
      <div className="admin-summary-card__meta">{meta}</div>
    </div>
  )
}

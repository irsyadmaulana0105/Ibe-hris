import type { ReactNode } from 'react'

type AdminPanelCardProps = {
  title: string
  subtitle?: string
  children: ReactNode
  className?: string
}

export default function AdminPanelCard({
  title,
  subtitle,
  children,
  className = '',
}: AdminPanelCardProps) {
  return (
    <div className={`admin-panel-card ${className}`.trim()}>
      <div className="admin-panel-card__header">
        <div>
          <div className="admin-panel-card__title">{title}</div>
          {subtitle ? <div className="admin-panel-card__subtitle">{subtitle}</div> : null}
        </div>
      </div>
      <div className="admin-panel-card__body">{children}</div>
    </div>
  )
}

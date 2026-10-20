import type { ReactNode } from 'react'

type AdminTableCardProps = {
  title: string
  subtitle?: string
  children: ReactNode
  className?: string
  bodyClassName?: string
}

export default function AdminTableCard({
  title,
  subtitle,
  children,
  className = '',
  bodyClassName = '',
}: AdminTableCardProps) {
  return (
    <div className={`admin-table-card ${className}`.trim()}>
      <div className="admin-table-card__header">
        <div>
          <div className="admin-panel-card__title">{title}</div>
          {subtitle ? <div className="admin-panel-card__subtitle">{subtitle}</div> : null}
        </div>
      </div>
      <div className={`admin-table-card__body ${bodyClassName}`.trim()}>
        {children}
      </div>
    </div>
  )
}

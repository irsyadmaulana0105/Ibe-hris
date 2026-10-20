import type { ReactNode } from 'react'

type AdminPageHeroProps = {
  eyebrow: string
  title: string
  subtitle: string
  variant?: 'finance' | 'workforce' | 'employee'
  action?: ReactNode
}

export default function AdminPageHero({
  eyebrow,
  title,
  subtitle,
  variant = 'workforce',
  action,
}: AdminPageHeroProps) {
  return (
    <section className={`admin-page-hero admin-page-hero--${variant}`}>
      <div>
        <span className="admin-page-eyebrow">{eyebrow}</span>
        <h1 className="admin-page-title">{title}</h1>
        <p className="admin-page-subtitle">{subtitle}</p>
      </div>
      {action ? <div className="admin-page-hero__action">{action}</div> : null}
    </section>
  )
}

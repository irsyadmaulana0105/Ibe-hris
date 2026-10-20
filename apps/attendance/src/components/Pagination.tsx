import type { Meta } from '@/types'

interface Props {
  meta: Meta
  onPageChange: (page: number) => void
}

export default function Pagination({ meta, onPageChange }: Props) {
  if (meta.last_page <= 1) return null

  return (
    <div className="d-flex align-items-center justify-content-between pt-3" style={{ fontSize: 13 }}>
      <span className="text-muted">
        Halaman {meta.current_page} dari {meta.last_page} ({meta.total} data)
      </span>
      <nav>
        <ul className="pagination pagination-sm mb-0">
          <li className={`page-item ${meta.current_page === 1 ? 'disabled' : ''}`}>
            <button className="page-link" onClick={() => onPageChange(meta.current_page - 1)}>
              &laquo;
            </button>
          </li>
          <li className={`page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}`}>
            <button className="page-link" onClick={() => onPageChange(meta.current_page + 1)}>
              &raquo;
            </button>
          </li>
        </ul>
      </nav>
    </div>
  )
}

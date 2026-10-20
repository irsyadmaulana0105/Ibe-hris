import { useEffect } from 'react'

interface Props {
  title: string
  onClose: () => void
  children: React.ReactNode
}

export default function Modal({ title, onClose, children }: Props) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="modal d-block"
      style={{ background: 'rgba(0,0,0,0.45)' }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="modal-dialog modal-dialog-centered">
        <div className="modal-content" style={{ borderRadius: 12 }}>
          <div className="modal-header" style={{ borderBottom: '1px solid #f1f1f1' }}>
            <h5 className="modal-title" style={{ fontSize: 15, fontWeight: 600 }}>{title}</h5>
            <button type="button" className="btn-close" onClick={onClose} />
          </div>
          <div className="modal-body p-4">
            {children}
          </div>
        </div>
      </div>
    </div>
  )
}

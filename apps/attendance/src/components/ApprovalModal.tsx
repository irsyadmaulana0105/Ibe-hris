import { useState } from 'react'
import Modal from './Modal'

interface Props {
  title: string
  action: 'approve' | 'reject'
  onConfirm: (notes: string) => void
  onClose: () => void
  isPending: boolean
  extraFields?: React.ReactNode
}

export default function ApprovalModal({ title, action, onConfirm, onClose, isPending, extraFields }: Props) {
  const [notes, setNotes] = useState('')

  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={(e) => { e.preventDefault(); onConfirm(notes) }}>
        {extraFields}
        <div className="mb-4">
          <label className="form-label">
            Catatan {action === 'approve' ? <span className="text-muted">(opsional)</span> : <span className="text-danger">*</span>}
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            required={action === 'reject'}
            rows={3}
            className="form-control"
          />
        </div>
        <div className="d-flex justify-content-end gap-2">
          <button type="button" className="btn btn-light" onClick={onClose}>Batal</button>
          <button
            type="submit"
            disabled={isPending}
            className={`btn ${action === 'approve' ? 'btn-success' : 'btn-danger'}`}
          >
            {isPending
              ? <><span className="spinner-border spinner-border-sm me-1" />Menyimpan...</>
              : action === 'approve' ? 'Setujui' : 'Tolak'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

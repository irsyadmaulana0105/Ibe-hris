type FeedbackAlertProps = {
  type: 'success' | 'danger'
  message?: string | null
  className?: string
}

export default function FeedbackAlert({
  type,
  message,
  className = '',
}: FeedbackAlertProps) {
  if (!message) return null

  return (
    <div className={`alert alert-${type} feedback-alert ${className}`.trim()}>
      {message}
    </div>
  )
}

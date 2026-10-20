import { useEffect, useRef, useState, useCallback } from 'react'

interface Options {
  idleMinutes?: number    // default 30
  warningMinutes?: number // default 2 (warning before logout)
  onLogout: () => void
}

export function useIdleTimeout({ idleMinutes = 30, warningMinutes = 2, onLogout }: Options) {
  const [showWarning, setShowWarning] = useState(false)
  const [secondsLeft, setSecondsLeft] = useState(warningMinutes * 60)

  const idleTimer   = useRef<ReturnType<typeof setTimeout> | null>(null)
  const warnTimer   = useRef<ReturnType<typeof setInterval> | null>(null)

  const clearAll = useCallback(() => {
    if (idleTimer.current)  { clearTimeout(idleTimer.current);  idleTimer.current  = null }
    if (warnTimer.current)  { clearInterval(warnTimer.current); warnTimer.current  = null }
  }, [])

  const startCountdown = useCallback(() => {
    let secs = warningMinutes * 60
    setSecondsLeft(secs)
    setShowWarning(true)
    warnTimer.current = setInterval(() => {
      secs--
      setSecondsLeft(secs)
      if (secs <= 0) {
        clearAll()
        setShowWarning(false)
        onLogout()
      }
    }, 1_000)
  }, [warningMinutes, onLogout, clearAll])

  const resetIdle = useCallback(() => {
    if (showWarning) return // user idle, jangan reset saat warning tampil
    clearAll()
    idleTimer.current = setTimeout(() => {
      startCountdown()
    }, (idleMinutes - warningMinutes) * 60 * 1_000)
  }, [showWarning, clearAll, idleTimer, idleMinutes, warningMinutes, startCountdown])

  const stayActive = useCallback(() => {
    clearAll()
    setShowWarning(false)
    resetIdle()
  }, [clearAll, resetIdle])

  useEffect(() => {
    const events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click']
    events.forEach(e => window.addEventListener(e, resetIdle, { passive: true }))
    resetIdle() // mulai timer pertama kali

    return () => {
      clearAll()
      events.forEach(e => window.removeEventListener(e, resetIdle))
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return { showWarning, secondsLeft, stayActive }
}

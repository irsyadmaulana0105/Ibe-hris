import { useRef, useState, useCallback, useEffect } from 'react'

const THRESHOLD = 72   // px to pull before triggering
const MAX_PULL  = 96   // max visual pull distance

export function usePullToRefresh(onRefresh: () => Promise<void> | void) {
  const [pullDistance, setPullDistance] = useState(0)
  const [refreshing, setRefreshing]     = useState(false)
  const startY   = useRef(0)
  const pulling  = useRef(false)
  const triggered = useRef(false)

  const onTouchStart = useCallback((e: TouchEvent) => {
    // Only activate when scrolled to the very top
    if (window.scrollY > 0) return
    startY.current  = e.touches[0].clientY
    pulling.current = true
    triggered.current = false
  }, [])

  const onTouchMove = useCallback((e: TouchEvent) => {
    if (!pulling.current || refreshing) return
    const delta = e.touches[0].clientY - startY.current
    if (delta <= 0) { pulling.current = false; return }
    // Dampen the pull (rubber-band feel)
    const dist = Math.min(delta * 0.5, MAX_PULL)
    setPullDistance(dist)
    if (dist >= THRESHOLD && !triggered.current) {
      triggered.current = true
    }
  }, [refreshing])

  const onTouchEnd = useCallback(async () => {
    if (!pulling.current) return
    pulling.current = false
    if (triggered.current) {
      setRefreshing(true)
      setPullDistance(48) // hold spinner visible
      try { await onRefresh() } finally {
        setRefreshing(false)
        setPullDistance(0)
      }
    } else {
      setPullDistance(0)
    }
    triggered.current = false
  }, [onRefresh])

  useEffect(() => {
    document.addEventListener('touchstart', onTouchStart, { passive: true })
    document.addEventListener('touchmove',  onTouchMove,  { passive: true })
    document.addEventListener('touchend',   onTouchEnd,   { passive: true })
    return () => {
      document.removeEventListener('touchstart', onTouchStart)
      document.removeEventListener('touchmove',  onTouchMove)
      document.removeEventListener('touchend',   onTouchEnd)
    }
  }, [onTouchStart, onTouchMove, onTouchEnd])

  return { pullDistance, refreshing, triggered: triggered.current }
}

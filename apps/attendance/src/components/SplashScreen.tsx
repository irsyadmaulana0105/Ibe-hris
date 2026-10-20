import { useEffect, useState } from 'react'

interface Props { authDone?: boolean }

export default function SplashScreen({ authDone }: Props) {
  const [hiding, setHiding] = useState(false)

  useEffect(() => {
    if (authDone) { setHiding(true); return }
    // Minimum display time 1.4s even if auth resolves fast
    const t = setTimeout(() => setHiding(true), 1400)
    return () => clearTimeout(t)
  }, [authDone])

  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 9999,
      background: 'linear-gradient(160deg, #1a7a8c 0%, #0d5a6a 100%)',
      display: 'flex', flexDirection: 'column',
      alignItems: 'center', justifyContent: 'center', gap: 24,
      opacity: hiding ? 0 : 1,
      transition: 'opacity 0.45s ease',
      pointerEvents: 'none',
    }}>
      {/* Logo */}
      <div style={{
        width: 96, height: 96, borderRadius: 24, background: '#fff',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        boxShadow: '0 16px 48px rgba(0,0,0,0.2)', padding: 12,
        animation: 'splashPop 0.5s cubic-bezier(0.34,1.56,0.64,1) both',
      }}>
        <img src="/logo.png" alt="IBE Reality" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
      </div>

      {/* Brand name */}
      <div style={{
        textAlign: 'center',
        animation: 'splashFadeUp 0.5s 0.2s ease both',
      }}>
        <div style={{ fontSize: 24, fontWeight: 800, color: '#fff', letterSpacing: -0.5 }}>
          IBE Reality
        </div>
        <div style={{ fontSize: 13, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>
          Portal Karyawan
        </div>
      </div>

      {/* Loading dots */}
      <div style={{
        display: 'flex', gap: 8,
        animation: 'splashFadeUp 0.5s 0.4s ease both',
      }}>
        {[0, 1, 2].map(i => (
          <div key={i} style={{
            width: 8, height: 8, borderRadius: '50%',
            background: 'rgba(255,255,255,0.6)',
            animation: `splashDot 1s ${i * 0.18}s ease-in-out infinite`,
          }} />
        ))}
      </div>

      <style>{`
        @keyframes splashPop {
          from { transform: scale(0.6); opacity: 0; }
          to   { transform: scale(1);   opacity: 1; }
        }
        @keyframes splashFadeUp {
          from { transform: translateY(12px); opacity: 0; }
          to   { transform: translateY(0);    opacity: 1; }
        }
        @keyframes splashDot {
          0%, 100% { transform: translateY(0);   opacity: 0.4; }
          50%       { transform: translateY(-6px); opacity: 1;   }
        }
      `}</style>
    </div>
  )
}

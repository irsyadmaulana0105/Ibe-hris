import { usePageTitle } from '@/hooks/usePageTitle'
import { useRef, useState, useCallback, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { setupFace } from '@/api/auth'
import FeedbackAlert from '@/components/FeedbackAlert'
import { MAX_CAPTURE_IMAGE_SIDE, MAX_FACE_CROP_SIDE, cropDetectedFaceToCanvas, drawVideoFrameToCanvas } from '@/utils/capture'
import { getErrorMessage } from '@/utils/apiError'
import { prepareFaceApiBackend } from '@/utils/faceApiBackend'

const MODEL_URL = '/models'

type Phase = 'idle' | 'loading' | 'camera' | 'saving'

export default function FaceSetupPage() {
  usePageTitle('Daftar Wajah')
  const { user, refreshUser } = useAuth()
  const navigate = useNavigate()

  const videoRef = useRef<HTMLVideoElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const faceapiRef = useRef<typeof import('@vladmandic/face-api') | null>(null)

  const [phase, setPhase] = useState<Phase>('idle')
  const [faceDetected, setFaceDetected] = useState(false)
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')
  const [justApproved, setJustApproved] = useState(false)

  const hasDescriptor = !!user?.has_face_registered
  const hasPending    = !!user?.has_face_pending_approval

  // ── Auto-poll + BroadcastChannel saat status pending ──────────
  useEffect(() => {
    if (!hasPending) {
      if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null }
      return
    }

    // Poll tiap 5 detik selama pending
    pollRef.current = setInterval(() => { refreshUser() }, 5_000)

    // Langsung poll saat tab kembali aktif (user buka tab lagi)
    const onVisible = () => {
      if (document.visibilityState === 'visible') refreshUser()
    }
    document.addEventListener('visibilitychange', onVisible)

    // BroadcastChannel: instant update jika admin & karyawan pakai browser yang sama
    let bc: BroadcastChannel | null = null
    if (typeof BroadcastChannel !== 'undefined') {
      bc = new BroadcastChannel('face_approval')
      bc.onmessage = (e) => {
        if (e.data?.type === 'approved') refreshUser()
      }
    }

    return () => {
      if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null }
      document.removeEventListener('visibilitychange', onVisible)
      bc?.close()
    }
  }, [hasPending, refreshUser])

  // Deteksi transisi pending → approved
  const prevPendingRef = useRef(hasPending)
  useEffect(() => {
    if (prevPendingRef.current && !hasPending && hasDescriptor) {
      // Baru saja diapprove admin!
      setJustApproved(true)
      setSuccessMsg('🎉 Wajah Anda telah disetujui admin! Sekarang Anda bisa absen.')
    }
    prevPendingRef.current = hasPending
  }, [hasPending, hasDescriptor])

  const stopCamera = useCallback(() => {
    if (intervalRef.current) { clearInterval(intervalRef.current); intervalRef.current = null }
    if (streamRef.current) { streamRef.current.getTracks().forEach(t => t.stop()); streamRef.current = null }
  }, [])

  useEffect(() => () => {
    stopCamera()
    if (pollRef.current) clearInterval(pollRef.current)
  }, [stopCamera])

  const startCamera = async () => {
    setPhase('loading')
    setError('')
    setFaceDetected(false)
    setJustApproved(false)

    try {
      let faceapi = faceapiRef.current
      if (!faceapi) {
        faceapi = await import('@vladmandic/face-api')
        faceapiRef.current = faceapi
        await prepareFaceApiBackend(faceapi)
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL)
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        audio: false,
      })
      streamRef.current = stream

      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }

      setPhase('camera')

      intervalRef.current = setInterval(async () => {
        const video = videoRef.current
        if (!video || video.paused || video.videoWidth === 0) return
        const det = await faceapi!
          .detectSingleFace(video, new faceapi!.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))
        setFaceDetected(!!det)
      }, 400)
    } catch (err: unknown) {
      setError('Tidak dapat mengakses kamera: ' + (err instanceof Error ? err.message : ''))
      setPhase('idle')
    }
  }

  const captureAndSave = async () => {
    const faceapi = faceapiRef.current
    const video   = videoRef.current
    const canvas  = canvasRef.current
    if (!faceapi || !video || !canvas) return

    setPhase('saving')
    try {
      drawVideoFrameToCanvas(video, canvas, MAX_CAPTURE_IMAGE_SIDE)

      const detection = await faceapi
        .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))

      if (!detection) {
        setError('Wajah tidak terdeteksi, coba lagi.')
        setPhase('camera')
        return
      }

      // Gagal potong = wajah terlalu kecil/jauh. Foto acuan harus jelas, jangan
      // kirim frame penuh diam-diam.
      if (!cropDetectedFaceToCanvas(canvas, canvas, detection.box, MAX_FACE_CROP_SIDE)) {
        setError('Wajah terlalu jauh dari kamera. Dekatkan wajah ke kamera, lalu coba lagi.')
        setPhase('camera')
        return
      }
      const selfieBase64 = canvas.toDataURL('image/jpeg', 0.78)
      await setupFace(selfieBase64)
      await refreshUser()

      stopCamera()
      setSuccessMsg('Wajah berhasil dikirim! Menunggu persetujuan admin.')
      setPhase('idle')
    } catch (err: unknown) {
      setError(getErrorMessage(err, 'Gagal menyimpan wajah.'))
      setPhase('camera')
    }
  }

  const removeFace = async () => {
    if (!confirm('Hapus data wajah? Face recognition akan dinonaktifkan.')) return
    setPhase('saving')
    setJustApproved(false)
    try {
      await setupFace(null)
      await refreshUser()
      setSuccessMsg('Data wajah berhasil dihapus.')
    } catch (err: unknown) {
      setError(getErrorMessage(err, 'Gagal menghapus wajah.'))
    } finally {
      setPhase('idle')
    }
  }

  // ── Camera view ───────────────────────────────────────────────
  if (phase === 'camera' || phase === 'loading') {
    return (
      <div className="camera-page">
        <video ref={videoRef} className="camera-video" autoPlay playsInline muted />
        <canvas ref={canvasRef} style={{ display: 'none' }} />
        <div className="camera-overlay">
          <div className="d-flex w-100 justify-content-between" style={{ zIndex: 10 }}>
            <button
              onClick={() => { stopCamera(); setPhase('idle') }}
              style={{ background: 'rgba(0,0,0,0.5)', border: 'none', borderRadius: 8, color: 'white', padding: '8px 14px', fontSize: 13 }}
            >
              ✕ Batal
            </button>
            <span style={{ background: 'rgba(0,0,0,0.5)', color: 'white', padding: '8px 14px', borderRadius: 8, fontSize: 13 }}>
              Daftar Wajah
            </span>
          </div>

          <div className={`camera-face-box ${faceDetected ? 'detected' : ''}`} />

          <div className="d-flex flex-column align-items-center gap-3" style={{ zIndex: 10 }}>
            <div style={{ background: 'rgba(0,0,0,0.55)', color: 'white', padding: '8px 18px', borderRadius: 20, fontSize: 13 }}>
              {phase === 'loading' ? '⏳ Memuat model AI...' : faceDetected ? 'Wajah terdeteksi ✓ - Tekan untuk daftar' : 'Posisikan wajah di dalam oval'}
            </div>
            {phase === 'camera' && (
              <button
                className="camera-capture-btn"
                onClick={captureAndSave}
                disabled={!faceDetected}
                style={{ opacity: faceDetected ? 1 : 0.35 }}
              />
            )}
          </div>
        </div>
      </div>
    )
  }

  // ── Main view ─────────────────────────────────────────────────
  return (
    <>
      {/* Header */}
      <div className="d-flex align-items-center gap-2 mb-4">
        <button onClick={() => navigate(-1)} className="btn btn-sm btn-light">←</button>
        <h5 className="mb-0" style={{ fontWeight: 600 }}>Daftar Wajah (Face Recognition)</h5>
      </div>

      {/* Status card */}
      <div className="card mb-3">
        <div className="card-body">
          <div className="d-flex align-items-center gap-3 mb-3">
            <div style={{
              width: 56, height: 56, borderRadius: '50%',
              background: hasDescriptor ? '#f0fdf4' : hasPending ? '#e8f4fd' : '#fff8e1',
              display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, flexShrink: 0,
            }}>
              {hasDescriptor ? '✅' : hasPending ? '⏳' : '⚠️'}
            </div>
            <div>
              <div style={{ fontWeight: 600 }}>
                {hasDescriptor ? 'Wajah Sudah Terdaftar' : hasPending ? 'Menunggu Persetujuan Admin' : 'Wajah Belum Terdaftar'}
              </div>
              <div className="text-muted" style={{ fontSize: 13 }}>
                {hasDescriptor
                  ? 'Verifikasi wajah akan dihitung di server saat absen'
                  : hasPending
                  ? 'Halaman ini otomatis memperbarui status setiap 5 detik'
                  : 'Daftar wajah agar absen lebih aman'}
              </div>
            </div>
          </div>

          {/* Pending info */}
          {hasPending && !justApproved && (
            <div style={{ background: '#e8f4fd', borderRadius: 10, padding: '12px 14px', fontSize: 13, color: '#0d6efd', marginBottom: 8 }}>
              <div style={{ fontWeight: 600, marginBottom: 4 }}>⏳ Menunggu persetujuan admin</div>
              Wajah Anda sudah terkirim. Halaman ini akan otomatis memperbarui saat admin menyetujuinya - tidak perlu refresh manual.
            </div>
          )}

          {/* How it works */}
          {!hasPending && !hasDescriptor && (
            <div style={{ background: '#f8f9fa', borderRadius: 10, padding: '12px 14px', fontSize: 13 }}>
              <strong>Cara kerja:</strong>
              <ol style={{ marginTop: 6, marginBottom: 0, paddingLeft: 18 }}>
                <li>Klik "Daftar Wajah" → izinkan akses kamera</li>
                <li>Posisikan wajah di tengah oval</li>
                <li>Admin menyetujui pendaftaran Anda</li>
                <li>Saat absen, foto diverifikasi oleh server</li>
              </ol>
            </div>
          )}
        </div>
      </div>

      <FeedbackAlert type="danger"   message={error} />
      <FeedbackAlert type="success"  message={successMsg} />

      {/* CTA setelah approved - tombol besar ke halaman absen */}
      {(justApproved || (hasDescriptor && !hasPending)) && (
        <button
          onClick={() => navigate('/attendance')}
          className="btn w-100 py-3 mb-3"
          style={{
            fontSize: 16, fontWeight: 700, borderRadius: 14,
            background: 'linear-gradient(135deg,#1a7a8c,#0d5a6a)',
            color: 'white', border: 'none', boxShadow: '0 4px 14px rgba(26,122,140,.35)',
          }}
        >
          📍 Mulai Absen Sekarang →
        </button>
      )}

      {/* Daftar / Update */}
      <button
        onClick={startCamera}
        disabled={phase === 'saving'}
        className="btn btn-primary w-100 py-3 mb-3"
        style={{ fontSize: 15, fontWeight: 600, borderRadius: 12 }}
      >
        📷 {hasDescriptor ? 'Perbarui Data Wajah' : hasPending ? 'Kirim Ulang Wajah' : 'Daftar Wajah Sekarang'}
      </button>

      {(hasDescriptor || hasPending) && (
        <button
          onClick={removeFace}
          disabled={phase === 'saving'}
          className="btn btn-outline-danger w-100 py-2"
          style={{ fontSize: 14, borderRadius: 12 }}
        >
          🗑️ {hasPending ? 'Batalkan Pendaftaran Wajah' : 'Hapus Data Wajah'}
        </button>
      )}
    </>
  )
}

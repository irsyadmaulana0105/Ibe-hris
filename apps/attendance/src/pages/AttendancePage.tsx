import { usePageTitle } from '@/hooks/usePageTitle'
import { useRef, useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import { clockIn, clockOut, getTodayAttendance, getOfficeLocations } from '@/api/attendance'
import FeedbackAlert from '@/components/FeedbackAlert'
import { MAX_CAPTURE_IMAGE_SIDE, MAX_FACE_CROP_SIDE, cropDetectedFaceToCanvas, drawVideoFrameToCanvas } from '@/utils/capture'
import { getErrorMessage } from '@/utils/apiError'
import { prepareFaceApiBackend } from '@/utils/faceApiBackend'

function haversineMeters(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const R = 6371000
  const dLat = (lat2 - lat1) * Math.PI / 180
  const dLng = (lng2 - lng1) * Math.PI / 180
  const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a))
}

const MODEL_URL = '/models'

type Phase = 'idle' | 'loading-models' | 'camera' | 'submitting' | 'done'
type FaceState = 'none' | 'detected'

export default function AttendancePage() {
  usePageTitle('Absensi')
  const { user } = useAuth()
  const isMobileField = user?.attendance_location_mode === 'mobile_field'
  const navigate = useNavigate()
  const qc = useQueryClient()

  const videoRef = useRef<HTMLVideoElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const faceapiRef = useRef<typeof import('@vladmandic/face-api') | null>(null)

  const [phase, setPhase] = useState<Phase>('idle')
  const [faceState, setFaceState] = useState<FaceState>('none')
  const [capturedImage, setCapturedImage] = useState<string | null>(null)
  const [gps, setGps] = useState<{ lat: number; lng: number; accuracy: number; recordedAt: string; address?: string } | null>(null)
  const [gpsError, setGpsError] = useState('')
  const [error, setError] = useState('')
  const [successMsg, setSuccessMsg] = useState('')
  const [modelsLoaded, setModelsLoaded] = useState(false)

  const { data: officeLocations } = useQuery({
    queryKey: ['office-locations'],
    queryFn: getOfficeLocations,
    staleTime: 5 * 60_000,
  })

  // Geofence status: nearest location + distance
  const geoStatus = useCallback(() => {
    if (isMobileField || !gps || !officeLocations?.length) return null
    let nearest = officeLocations[0]
    let minDist = haversineMeters(gps.lat, gps.lng, nearest.latitude, nearest.longitude)
    for (const loc of officeLocations) {
      const d = haversineMeters(gps.lat, gps.lng, loc.latitude, loc.longitude)
      if (d < minDist) { minDist = d; nearest = loc }
    }
    return { location: nearest, distance: Math.round(minDist), inside: minDist <= nearest.radius_meters }
  }, [gps, officeLocations, isMobileField])()

  const hasRegisteredFace = !!user?.has_face_registered
  const hasPendingFaceApproval = !!user?.has_face_pending_approval

  const attendanceBlockReason = !hasRegisteredFace
    ? (
      hasPendingFaceApproval
        ? 'Pendaftaran wajah Anda masih menunggu persetujuan admin. Anda belum bisa absen sampai wajah disetujui.'
        : 'Wajah Anda belum terdaftar. Silakan daftar wajah terlebih dulu sebelum absen.'
    )
    : ''

  const canOpenAttendanceCamera = !attendanceBlockReason

  const { data: todayAttendance, isLoading: attendanceLoading } = useQuery({
    queryKey: ['attendance-today'],
    queryFn: getTodayAttendance,
  })

  const canClockIn  = !attendanceLoading && !todayAttendance
  const canClockOut = !attendanceLoading && todayAttendance && !todayAttendance.time_out

  const refreshGps = useCallback(() => {
    setGpsError('')
    if (!navigator.geolocation) {
      setGpsError('Perangkat tidak mendukung lokasi')
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => setGps({
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
        accuracy: pos.coords.accuracy,
        recordedAt: new Date(pos.timestamp).toISOString(),
      }),
      () => setGpsError('Lokasi presisi tidak tersedia. Izinkan GPS lalu coba lagi.'),
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
    )
  }, [])

  useEffect(() => refreshGps(), [refreshGps])

  const deviceId = (() => {
    const key = 'ibe_attendance_device_id'
    const existing = localStorage.getItem(key)
    if (existing) return existing
    const generated = crypto.randomUUID()
    localStorage.setItem(key, generated)
    return generated
  })()

  const stopCamera = useCallback(() => {
    if (intervalRef.current) { clearInterval(intervalRef.current); intervalRef.current = null }
    if (streamRef.current) { streamRef.current.getTracks().forEach(t => t.stop()); streamRef.current = null }
  }, [])

  useEffect(() => () => stopCamera(), [stopCamera])

  const loadModels = async () => {
    if (faceapiRef.current && modelsLoaded) return faceapiRef.current

    const faceapi = faceapiRef.current ?? await import('@vladmandic/face-api')
    faceapiRef.current = faceapi

    if (!modelsLoaded) {
      await prepareFaceApiBackend(faceapi)
      await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL)
      setModelsLoaded(true)
    }

    return faceapi
  }

  const startCamera = async () => {
    if (!canOpenAttendanceCamera) {
      setError(attendanceBlockReason)
      return
    }

    setPhase('loading-models')
    setError('')
    setFaceState('none')
    setCapturedImage(null)

    try {
      const faceapi = await loadModels()
      if (!faceapi) throw new Error('Failed to load models')

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
      startDetection(faceapi)
    } catch (err: unknown) {
      setError('Tidak dapat mengakses kamera: ' + (err instanceof Error ? err.message : 'Unknown error'))
      setPhase('idle')
    }
  }

  const startDetection = (faceapi: typeof import('@vladmandic/face-api')) => {
    if (intervalRef.current) clearInterval(intervalRef.current)

    intervalRef.current = setInterval(async () => {
      const video = videoRef.current
      if (!video || video.paused || video.ended || video.videoWidth === 0) return

      try {
        const detection = await faceapi
          .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))

        setFaceState(detection ? 'detected' : 'none')
      } catch {
        // ignore detection errors during loop
      }
    }, 400)
  }

  const capturePhoto = async () => {
    const video = videoRef.current
    const canvas = canvasRef.current
    const faceapi = faceapiRef.current
    if (!video || !canvas || !faceapi) return

    setError('')
    drawVideoFrameToCanvas(video, canvas, MAX_CAPTURE_IMAGE_SIDE)

    try {
      const detection = await faceapi
        .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))

      if (!detection) {
        stopCamera()
        setCapturedImage(null)
        setPhase('idle')
        setError('Wajah tidak terdeteksi pada foto. Silakan ambil ulang.')
        return
      }

      // Gagal potong = wajah terlalu kecil/jauh. Jangan kirim frame penuh diam-diam:
      // pencocokan pada frame penuh lebih lemah dan bisa memuat wajah lain.
      if (!cropDetectedFaceToCanvas(canvas, canvas, detection.box, MAX_FACE_CROP_SIDE)) {
        stopCamera()
        setCapturedImage(null)
        setPhase('idle')
        setError('Wajah terlalu jauh dari kamera. Dekatkan wajah ke kamera, lalu ambil ulang.')
        return
      }
      const dataUrl = canvas.toDataURL('image/jpeg', 0.78)
      setCapturedImage(dataUrl)
      stopCamera()
      setPhase('idle')
    } catch {
      stopCamera()
      setCapturedImage(null)
      setPhase('idle')
      setError('Gagal memproses foto wajah. Silakan coba lagi.')
    }
  }

  const submitAttendance = async (type: 'in' | 'out') => {
    if (!canOpenAttendanceCamera) {
      setError(attendanceBlockReason)
      setCapturedImage(null)
      setPhase('idle')
      return
    }

    if (!capturedImage) {
      setError('Selfie absensi belum tersedia. Silakan ambil foto lagi.')
      setPhase('idle')
      return
    }

    setError('')
    setPhase('submitting')
    try {
      const payload = {
        selfie_base64: capturedImage,
        latitude: gps?.lat,
        longitude: gps?.lng,
        address: gps?.address,
        location_accuracy: gps?.accuracy,
        location_recorded_at: gps?.recordedAt,
        device_id: deviceId,
      }

      if (type === 'in') {
        await clockIn(payload)
        setSuccessMsg('Absen masuk berhasil! ✅')
      } else {
        await clockOut(payload)
        setSuccessMsg('Absen pulang berhasil! ✅')
      }

      qc.invalidateQueries({ queryKey: ['attendance-today'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
      setCapturedImage(null)
      setPhase('done')
    } catch (err: unknown) {
      setError(getErrorMessage(err, 'Gagal menyimpan absen.'))
      setPhase('idle')
    }
  }

  const faceOkToCapture = faceState === 'detected'

  const faceBoxClass = {
    none: '',
    detected: 'detected',
  }[faceState]

  const faceHint = {
    none: 'Arahkan wajah ke kamera',
    detected: 'Wajah terdeteksi ✓ - Tekan tombol untuk foto',
  }[faceState]

  if (phase === 'camera' || phase === 'loading-models') {
    return (
      <div className="camera-page">
        <video ref={videoRef} className="camera-video" autoPlay playsInline muted />
        <canvas ref={canvasRef} style={{ display: 'none' }} />

        <div className="camera-overlay">
          {/* Top bar: cancel + label */}
          <div className="d-flex w-100 justify-content-between align-items-center" style={{ zIndex: 10 }}>
            <button
              onClick={() => { stopCamera(); setPhase('idle') }}
              style={{ background: 'rgba(0,0,0,0.5)', border: 'none', borderRadius: 8, color: 'white', padding: '8px 14px', fontSize: 13 }}
            >
              ✕ Batal
            </button>
            <span style={{ color: 'white', fontSize: 13, background: 'rgba(0,0,0,0.5)', padding: '6px 12px', borderRadius: 8 }}>
              {phase === 'loading-models' ? '⏳ Memuat AI...' : (todayAttendance ? 'Absen Pulang' : 'Absen Masuk')}
            </span>
          </div>

          {/* Geofence status badge - shown below top bar */}
          {geoStatus && (
            <div style={{
              zIndex: 10,
              background: geoStatus.inside ? 'rgba(34,197,94,0.75)' : 'rgba(239,68,68,0.75)',
              backdropFilter: 'blur(6px)',
              color: 'white',
              fontSize: 12,
              fontWeight: 700,
              padding: '5px 16px',
              borderRadius: 20,
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              boxShadow: '0 2px 8px rgba(0,0,0,0.25)',
            }}>
              {geoStatus.inside
                ? <>✅ {geoStatus.location.name} · {geoStatus.distance} m</>
                : <>⚠️ Di luar area · {geoStatus.distance} m dari {geoStatus.location.name}</>
              }
            </div>
          )}
          {!geoStatus && gpsError && (
            <div style={{
              zIndex: 10, background: 'rgba(0,0,0,0.45)', color: 'rgba(255,255,255,0.7)',
              fontSize: 11, padding: '4px 12px', borderRadius: 16,
            }}>
              📍 GPS tidak tersedia
            </div>
          )}

          <div className={`camera-face-box ${faceBoxClass}`} />

          <div className="d-flex flex-column align-items-center gap-3" style={{ zIndex: 10 }}>
            <div style={{
              background: 'rgba(0,0,0,0.55)', color: 'white', padding: '8px 18px',
              borderRadius: 20, fontSize: 13, textAlign: 'center'
            }}>
              {phase === 'loading-models' ? 'Memuat model AI...' : faceHint}
            </div>
            {phase === 'camera' && (
              <button
                className="camera-capture-btn"
                onClick={capturePhoto}
                disabled={!faceOkToCapture}
                style={{ opacity: faceOkToCapture ? 1 : 0.35 }}
              />
            )}
          </div>
        </div>
      </div>
    )
  }

  const now = new Date()
  const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
  const dateStr = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

  return (
    <>
      <div className="card mb-3" style={{ background: 'linear-gradient(135deg,#1a7a8c,#0d5a6a)', color: 'white', textAlign: 'center', padding: '28px 16px' }}>
        <div style={{ fontSize: 48, fontWeight: 700, lineHeight: 1 }}>{timeStr}</div>
        <div style={{ fontSize: 13, opacity: 0.85, marginTop: 6 }}>{dateStr}</div>
        {gpsError && <div style={{ fontSize: 11, opacity: 0.7, marginTop: 4 }}>📍 {gpsError}</div>}
        {gps && !geoStatus && <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>📍 GPS aktif · akurasi ±{Math.round(gps.accuracy)} m</div>}
        {isMobileField && (
          <div style={{ marginTop: 8, fontSize: 12, fontWeight: 600, background: 'rgba(255,255,255,0.16)', borderRadius: 20, padding: '5px 14px', display: 'inline-block' }}>
            🚗 Sales Mobile · boleh absen di lokasi kerja mana pun
          </div>
        )}
        {geoStatus && (
          <div style={{
            marginTop: 8, fontSize: 12, fontWeight: 600,
            background: geoStatus.inside ? 'rgba(34,197,94,0.2)' : 'rgba(239,68,68,0.2)',
            borderRadius: 20, padding: '4px 14px', display: 'inline-block',
            color: geoStatus.inside ? '#d1fae5' : '#fecaca',
          }}>
            {geoStatus.inside
              ? `✅ Di dalam area ${geoStatus.location.name} (${geoStatus.distance} m)`
              : `⚠️ ${geoStatus.distance} m dari ${geoStatus.location.name} - di luar area`}
          </div>
        )}
      </div>

      {isMobileField && (
        <div className="card mb-3">
          <div className="card-body py-2 px-3 d-flex align-items-center gap-2">
            <span style={{ fontSize: 18 }}>🛡️</span>
            <div className="flex-grow-1">
              <div style={{ fontSize: 13, fontWeight: 700 }}>Pelaporan lokasi lapangan aktif</div>
              <div style={{ fontSize: 11, color: 'var(--bs-secondary-color)' }}>Lokasi tidak dibatasi kantor, tetapi GPS presisi, waktu lokasi, perangkat, dan wajah diverifikasi.</div>
            </div>
            <button type="button" className="btn btn-sm btn-outline-primary" onClick={refreshGps}>Perbarui GPS</button>
          </div>
        </div>
      )}

      {hasRegisteredFace && (
        <div className="card mb-3" style={{ background: '#f0edff', border: '1px solid #d6d0ff' }}>
          <div className="card-body py-2 px-3 d-flex align-items-center gap-2">
            <span style={{ fontSize: 18 }}>🔒</span>
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, color: '#1a7a8c' }}>Face Recognition Aktif</div>
              <div style={{ fontSize: 11, color: '#6c757d' }}>Foto Anda akan diverifikasi oleh server saat absen</div>
            </div>
            <a href="/face-setup" className="ms-auto btn btn-sm" style={{ background: '#1a7a8c', color: 'white', fontSize: 11 }}>
              Ubah
            </a>
          </div>
        </div>
      )}

      {hasPendingFaceApproval && (
        <div className="card mb-3" style={{ background: '#e8f4fd', border: '1px solid #b6dcff' }}>
          <div className="card-body py-2 px-3 d-flex align-items-center gap-2">
            <span style={{ fontSize: 18 }}>⏳</span>
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, color: '#0d6efd' }}>Wajah Menunggu Persetujuan</div>
              <div style={{ fontSize: 11, color: '#6c757d' }}>Absensi dikunci sampai admin menyetujui pendaftaran wajah Anda</div>
            </div>
            <a href="/face-setup" className="ms-auto btn btn-sm" style={{ background: '#0d6efd', color: 'white', fontSize: 11 }}>
              Cek Status
            </a>
          </div>
        </div>
      )}

      {!hasRegisteredFace && !hasPendingFaceApproval && (
        <div className="card mb-3" style={{ background: '#fff8e1', border: '1px solid #ffe082' }}>
          <div className="card-body py-2 px-3 d-flex align-items-center gap-2">
            <span style={{ fontSize: 18 }}>⚠️</span>
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, color: '#f57c00' }}>Wajah Belum Terdaftar</div>
              <div style={{ fontSize: 11, color: '#6c757d' }}>Daftar wajah dulu. Absensi belum bisa dilakukan sebelum data wajah aktif.</div>
            </div>
            <a href="/face-setup" className="ms-auto btn btn-sm" style={{ background: '#f57c00', color: 'white', fontSize: 11 }}>
              Daftar
            </a>
          </div>
        </div>
      )}

      {attendanceLoading ? (
        <div className="card"><div className="card-body text-center py-4"><div className="spinner-border text-primary spinner-border-sm" /></div></div>
      ) : (
        <div className="card mb-3">
          <div className="card-header">Status Kehadiran Hari Ini</div>
          <div className="card-body">
            {!todayAttendance ? (
              <div className="text-center py-2">
                <div style={{ fontSize: 14, color: '#6c757d', marginBottom: 12 }}>Belum absen hari ini</div>
              </div>
            ) : (
              <div className="row g-2">
                <div className="col-6">
                  <div style={{ background: '#f0fdf4', borderRadius: 10, padding: '12px 14px', textAlign: 'center' }}>
                    <div style={{ fontSize: 11, color: '#6c757d', marginBottom: 2 }}>Masuk</div>
                    <div style={{ fontSize: 20, fontWeight: 700, color: '#22c55e' }}>
                      {todayAttendance.time_in?.slice(0, 5) ?? '-'}
                    </div>
                  </div>
                </div>
                <div className="col-6">
                  <div style={{ background: '#fef2f2', borderRadius: 10, padding: '12px 14px', textAlign: 'center' }}>
                    <div style={{ fontSize: 11, color: '#6c757d', marginBottom: 2 }}>Pulang</div>
                    <div style={{ fontSize: 20, fontWeight: 700, color: todayAttendance.time_out ? '#ef4444' : '#d1d5db' }}>
                      {todayAttendance.time_out?.slice(0, 5) ?? '-'}
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {capturedImage && (
        <div className="card mb-3">
          <div className="card-body text-center p-2">
            {/* Dicerminkan agar sama dengan pratinjau kamera (.camera-video). Hanya tampilan:
                capturedImage yang dikirim ke server tetap tidak dicerminkan. */}
            <img src={capturedImage} alt="Selfie" style={{ width: '100%', maxWidth: 240, borderRadius: 12, objectFit: 'cover', transform: 'scaleX(-1)' }} />
            <div className="d-flex gap-2 mt-2 justify-content-center">
              <button onClick={() => setCapturedImage(null)} className="btn btn-sm btn-light">Ulangi Foto</button>
              {canClockIn && (
                <button
                  onClick={() => submitAttendance('in')}
                  disabled={phase === 'submitting'}
                  className="btn btn-sm btn-primary"
                >
                  {phase === 'submitting' ? <><span className="spinner-border spinner-border-sm me-1" />Menyimpan...</> : '✓ Absen Masuk'}
                </button>
              )}
              {canClockOut && (
                <button
                  onClick={() => submitAttendance('out')}
                  disabled={phase === 'submitting'}
                  className="btn btn-sm btn-danger"
                >
                  {phase === 'submitting' ? <><span className="spinner-border spinner-border-sm me-1" />Menyimpan...</> : '✓ Absen Pulang'}
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      <FeedbackAlert type="danger" message={error} />
      {successMsg && (
        <div className="alert alert-success feedback-alert py-3 text-center">
          <div style={{ fontSize: 28, marginBottom: 4 }}>🎉</div>
          {successMsg}
          <div className="mt-2">
            <button onClick={() => { setSuccessMsg(''); navigate('/dashboard') }} className="btn btn-sm btn-success">
              Ke Dashboard
            </button>
          </div>
        </div>
      )}

      {!capturedImage && !successMsg && (
        <>
          {(canClockIn || canClockOut) && !canOpenAttendanceCamera && (
            <button
              onClick={() => navigate('/face-setup')}
              className={`btn w-100 py-3 mb-3 ${hasPendingFaceApproval ? 'btn-outline-primary' : 'btn-warning'}`}
              style={{ fontSize: 16, fontWeight: 600, borderRadius: 12 }}
            >
              {hasPendingFaceApproval ? '⏳ Lihat Status Pendaftaran Wajah' : '📝 Daftar Wajah Dulu'}
            </button>
          )}
          {canClockIn && canOpenAttendanceCamera && (
            <button
              onClick={startCamera}
              className="btn btn-primary w-100 py-3 mb-3"
              style={{ fontSize: 16, fontWeight: 600, borderRadius: 12 }}
            >
              📷 Absen Masuk
            </button>
          )}
          {canClockOut && canOpenAttendanceCamera && (
            <button
              onClick={startCamera}
              className="btn btn-danger w-100 py-3 mb-3"
              style={{ fontSize: 16, fontWeight: 600, borderRadius: 12 }}
            >
              📷 Absen Pulang
            </button>
          )}
          {todayAttendance?.time_out && (
            <div className="text-center text-muted" style={{ fontSize: 13 }}>
              Kehadiran hari ini sudah lengkap ✓
            </div>
          )}
        </>
      )}

      <canvas ref={canvasRef} style={{ display: 'none' }} />
    </>
  )
}


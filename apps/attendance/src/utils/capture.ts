export type FaceBoxLike = {
  x: number
  y: number
  width: number
  height: number
}

export const MAX_CAPTURE_IMAGE_SIDE = 320
export const MAX_FACE_CROP_SIDE = 256

const FACE_CROP_SCALE = 1.85
const FACE_CROP_VERTICAL_OFFSET_RATIO = 0.08
const MIN_FACE_BOX_SIDE = 48
const MIN_FACE_CROP_SIDE = 96

function getCanvasContext(canvas: HTMLCanvasElement) {
  const context = canvas.getContext('2d')
  if (!context) {
    throw new Error('Canvas tidak tersedia.')
  }

  return context
}

function clamp(value: number, min: number, max: number) {
  return Math.min(Math.max(value, min), max)
}

function hasValidFaceBox(box: FaceBoxLike) {
  return [box.x, box.y, box.width, box.height].every(Number.isFinite)
    && box.width > 0
    && box.height > 0
}

export function drawVideoFrameToCanvas(
  video: HTMLVideoElement,
  canvas: HTMLCanvasElement,
  maxSide = MAX_CAPTURE_IMAGE_SIDE,
) {
  const sourceWidth = video.videoWidth
  const sourceHeight = video.videoHeight

  if (!sourceWidth || !sourceHeight) {
    throw new Error('Video belum siap diproses.')
  }

  const longestSide = Math.max(sourceWidth, sourceHeight)
  const scale = longestSide > maxSide ? maxSide / longestSide : 1
  const targetWidth = Math.max(1, Math.round(sourceWidth * scale))
  const targetHeight = Math.max(1, Math.round(sourceHeight * scale))

  canvas.width = targetWidth
  canvas.height = targetHeight

  getCanvasContext(canvas).drawImage(video, 0, 0, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight)

  return {
    width: targetWidth,
    height: targetHeight,
  }
}

export function cropDetectedFaceToCanvas(
  sourceCanvas: HTMLCanvasElement,
  targetCanvas: HTMLCanvasElement,
  box: FaceBoxLike,
  maxSide = MAX_FACE_CROP_SIDE,
) {
  try {
    const sourceWidth = sourceCanvas.width
    const sourceHeight = sourceCanvas.height

    if (!sourceWidth || !sourceHeight || !hasValidFaceBox(box)) {
      return null
    }

    if (Math.max(box.width, box.height) < MIN_FACE_BOX_SIDE) {
      return null
    }

    const cropSide = Math.min(
      Math.max(box.width, box.height) * FACE_CROP_SCALE,
      sourceWidth,
      sourceHeight,
    )

    if (!Number.isFinite(cropSide) || cropSide < MIN_FACE_CROP_SIDE) {
      return null
    }

    const tempCanvas = document.createElement('canvas')
    tempCanvas.width = sourceWidth
    tempCanvas.height = sourceHeight
    getCanvasContext(tempCanvas).drawImage(sourceCanvas, 0, 0)

    const centerX = box.x + box.width / 2
    const centerY = box.y + box.height / 2 - box.height * FACE_CROP_VERTICAL_OFFSET_RATIO
    const cropX = clamp(centerX - cropSide / 2, 0, Math.max(0, sourceWidth - cropSide))
    const cropY = clamp(centerY - cropSide / 2, 0, Math.max(0, sourceHeight - cropSide))
    const targetSide = Math.max(1, Math.min(maxSide, Math.round(cropSide)))

    targetCanvas.width = targetSide
    targetCanvas.height = targetSide

    getCanvasContext(targetCanvas).drawImage(
      tempCanvas,
      cropX,
      cropY,
      cropSide,
      cropSide,
      0,
      0,
      targetSide,
      targetSide,
    )

    return {
      width: targetSide,
      height: targetSide,
      cropped: true,
    }
  } catch {
    return null
  }
}

export type CameraErrorKind =
  'unsupported' | 'denied' | 'no-device' | 'unavailable' | 'capture-failed'

const ERROR_MESSAGES: Record<CameraErrorKind, string> = {
  unsupported:
    'This browser does not support camera access. Upload an image instead.',
  denied:
    'Camera access was denied. Allow camera permission in your browser settings.',
  'no-device': 'No camera was found on this device. Upload an image instead.',
  unavailable:
    'The camera is unavailable right now. Close other camera apps and try again.',
  'capture-failed': 'We could not capture that frame. Please try again.',
}

export class CameraAccessError extends Error {
  readonly kind: CameraErrorKind

  constructor(kind: CameraErrorKind, message = ERROR_MESSAGES[kind]) {
    super(message)
    this.name = 'CameraAccessError'
    this.kind = kind
  }
}

export interface CameraRequest {
  deviceId?: string
  facingMode?: 'user' | 'environment'
}

type TorchCapabilities = MediaTrackCapabilities & { torch?: boolean }
type TorchConstraintSet = MediaTrackConstraintSet & { torch?: boolean }

function getMediaDevices(): MediaDevices | undefined {
  if (typeof navigator === 'undefined') {
    return undefined
  }
  return navigator.mediaDevices
}

export function isCameraSupported() {
  return Boolean(getMediaDevices()?.getUserMedia)
}

function toCameraError(error: unknown): CameraAccessError {
  const name =
    typeof error === 'object' && error !== null && 'name' in error
      ? String((error as { name: unknown }).name)
      : ''

  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return new CameraAccessError('denied')
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
    return new CameraAccessError('no-device')
  }
  return new CameraAccessError('unavailable')
}

export async function listVideoDevices(): Promise<MediaDeviceInfo[]> {
  const mediaDevices = getMediaDevices()
  if (!mediaDevices?.enumerateDevices) {
    return []
  }
  try {
    const devices = await mediaDevices.enumerateDevices()
    return devices.filter((device) => device.kind === 'videoinput')
  } catch {
    return []
  }
}

export async function requestCameraStream(
  request: CameraRequest = {},
): Promise<MediaStream> {
  if (!isCameraSupported()) {
    throw new CameraAccessError('unsupported')
  }

  const video: MediaTrackConstraints = request.deviceId
    ? { deviceId: { exact: request.deviceId } }
    : { facingMode: request.facingMode ?? 'environment' }

  try {
    return await getMediaDevices()!.getUserMedia({ audio: false, video })
  } catch (error) {
    throw toCameraError(error)
  }
}

export function getVideoTrack(stream: MediaStream | null) {
  return stream?.getVideoTracks()[0] ?? null
}

export function hasTorchSupport(track: MediaStreamTrack | null) {
  if (!track || typeof track.getCapabilities !== 'function') {
    return false
  }
  try {
    const capabilities = track.getCapabilities() as TorchCapabilities
    return capabilities.torch === true
  } catch {
    return false
  }
}

export async function setTorch(
  track: MediaStreamTrack | null,
  enabled: boolean,
) {
  if (
    !hasTorchSupport(track) ||
    typeof track?.applyConstraints !== 'function'
  ) {
    throw new CameraAccessError('unavailable')
  }

  try {
    await track.applyConstraints({
      advanced: [{ torch: enabled } as TorchConstraintSet],
    })
  } catch (error) {
    throw toCameraError(error)
  }
}

export function stopStream(stream: MediaStream | null) {
  stream?.getTracks().forEach((track) => track.stop())
}

export function captureFrame(video: HTMLVideoElement | null): string | null {
  if (!video || !video.videoWidth || !video.videoHeight) {
    return null
  }

  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')
  if (!context) {
    return null
  }

  try {
    context.drawImage(video, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/jpeg', 0.9)
  } catch {
    return null
  }
}

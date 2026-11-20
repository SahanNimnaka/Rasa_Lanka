import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type ChangeEvent,
} from 'react'
import {
  Camera,
  Images,
  RotateCcw,
  SwitchCamera,
  X,
  Zap,
  ZapOff,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { Button } from '../../components/ui/Button'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  CameraAccessError,
  type CameraRequest,
  captureFrame,
  getVideoTrack,
  hasTorchSupport,
  isCameraSupported,
  listVideoDevices,
  requestCameraStream,
  setTorch,
  stopStream,
} from '../../services/cameraService'
import { readImageFile, validateImageFile } from '../../services/imageService'
import { rememberRecentImage } from '../../services/recentImages'
import type { ImagePreviewData } from '../../types/ingredients'

type CameraStatus = 'idle' | 'requesting' | 'ready' | 'error'

export function CameraScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const galleryInputRef = useRef<HTMLInputElement | null>(null)
  const requestIdRef = useRef(0)

  const [status, setStatus] = useState<CameraStatus>('idle')
  const [error, setError] = useState<CameraAccessError | null>(null)
  const [stream, setStream] = useState<MediaStream | null>(null)
  const [devices, setDevices] = useState<MediaDeviceInfo[]>([])
  const [torchAvailable, setTorchAvailable] = useState(false)
  const [torchOn, setTorchOn] = useState(false)
  const [notice, setNotice] = useState('')
  const [mode, setMode] = useState<'photo' | 'gallery'>('photo')

  const stopCurrentStream = useCallback(() => {
    stopStream(streamRef.current)
    streamRef.current = null
    setStream(null)
    setTorchOn(false)
    setTorchAvailable(false)
  }, [])

  const startCamera = useCallback(
    async (request: CameraRequest = {}) => {
      const requestId = ++requestIdRef.current
      setStatus('requesting')
      setError(null)
      setNotice('')
      stopCurrentStream()

      try {
        const nextStream = await requestCameraStream(request)

        if (requestId !== requestIdRef.current) {
          stopStream(nextStream)
          return
        }

        streamRef.current = nextStream
        setStream(nextStream)
        setStatus('ready')
        setTorchAvailable(hasTorchSupport(getVideoTrack(nextStream)))

        const videoDevices = await listVideoDevices()
        if (requestId === requestIdRef.current) {
          setDevices(videoDevices)
        }
      } catch (caughtError) {
        if (requestId !== requestIdRef.current) {
          return
        }
        setError(
          caughtError instanceof CameraAccessError
            ? caughtError
            : new CameraAccessError('unavailable'),
        )
        setStatus('error')
      }
    },
    [stopCurrentStream],
  )

  useEffect(() => {
    let cancelled = false

    void Promise.resolve().then(() => {
      if (!cancelled) {
        void startCamera({ facingMode: 'environment' })
      }
    })

    return () => {
      cancelled = true
      requestIdRef.current += 1
      stopStream(streamRef.current)
      streamRef.current = null
    }
  }, [startCamera])

  useEffect(() => {
    const video = videoRef.current
    if (!video || !stream || status !== 'ready') {
      return
    }
    video.srcObject = stream
    const playback = video.play()
    if (playback && typeof playback.catch === 'function') {
      void playback.catch(() => undefined)
    }
    return () => {
      video.srcObject = null
    }
  }, [status, stream])

  function handleSwitchCamera() {
    const currentTrack = getVideoTrack(stream)
    const currentDeviceId = currentTrack?.getSettings().deviceId
    const currentIndex = devices.findIndex(
      (device) => device.deviceId === currentDeviceId,
    )
    const nextDevice =
      currentIndex >= 0
        ? devices[(currentIndex + 1) % devices.length]
        : devices[0]

    if (nextDevice && nextDevice.deviceId !== currentDeviceId) {
      void startCamera({ deviceId: nextDevice.deviceId })
    }
  }

  async function handleTorchToggle() {
    if (!torchAvailable || !stream) {
      return
    }
    try {
      await setTorch(getVideoTrack(stream), !torchOn)
      setTorchOn((current) => !current)
    } catch {
      setNotice('Flash control is not available for this camera.')
    }
  }

  function handleCapture() {
    const dataUrl = captureFrame(videoRef.current)
    if (!dataUrl) {
      setNotice('The camera preview is not ready yet. Try again in a moment.')
      return
    }

    const imageName = 'Camera capture'
    rememberRecentImage({
      id: `camera-${Date.now()}`,
      name: imageName,
      dataUrl,
      createdAt: Date.now(),
    })
    const image: ImagePreviewData = {
      dataUrl,
      name: imageName,
      source: 'camera',
    }
    navigate('/ingredients/recognition', { state: { image } })
  }

  function openGallery() {
    setMode('gallery')
    if (galleryInputRef.current) {
      galleryInputRef.current.value = ''
      galleryInputRef.current.click()
    }
  }

  async function handleGalleryChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) {
      return
    }

    const validation = validateImageFile(file)
    if (!validation.ok) {
      setNotice(validation.error)
      return
    }

    try {
      const dataUrl = await readImageFile(file)
      rememberRecentImage({
        id: `gallery-${Date.now()}`,
        name: file.name,
        dataUrl,
        createdAt: Date.now(),
      })
      const image: ImagePreviewData = {
        dataUrl,
        name: file.name,
        size: file.size,
        type: file.type,
        source: 'gallery',
      }
      navigate('/ingredients/upload', { state: { preloadedImage: image } })
    } catch (caughtError) {
      setNotice(
        caughtError instanceof Error
          ? caughtError.message
          : 'We could not open that image. Please try again.',
      )
    }
  }

  const canSwitchCamera = devices.length > 1

  return (
    <MobileScreen className="overflow-hidden bg-[#DEDEDE] px-0 pb-0">
      <div className="relative min-h-dvh w-full bg-[#DEDEDE]">
        <video
          aria-label="Live camera preview"
          autoPlay
          data-testid="camera-preview"
          className={
            status === 'ready'
              ? 'absolute inset-0 h-full w-full bg-[#DEDEDE] object-cover'
              : 'sr-only'
          }
          muted
          playsInline
          ref={videoRef}
        />

        <div className="absolute inset-x-0 top-0 z-20 flex items-center justify-between gap-2 p-5">
          <Button
            aria-label="Close camera"
            className="h-10 w-10 rounded-full bg-black/35 p-0 text-white hover:bg-black/55"
            onClick={goBack}
            size="sm"
            variant="ghost"
          >
            <X aria-hidden="true" size={22} />
          </Button>

          <Button
            aria-label={torchOn ? 'Turn flash off' : 'Turn flash on'}
            aria-pressed={torchOn}
            className="min-h-10 gap-2 rounded-full bg-black/35 px-4 text-sm text-white hover:bg-black/55 disabled:opacity-40"
            disabled={!torchAvailable}
            onClick={() => void handleTorchToggle()}
            size="sm"
            title={
              torchAvailable
                ? undefined
                : 'Flash is not supported by this camera'
            }
            variant="ghost"
          >
            {torchOn ? (
              <Zap aria-hidden="true" size={18} />
            ) : (
              <ZapOff aria-hidden="true" size={18} />
            )}
            Flash
          </Button>

          <Button
            aria-label="Switch camera"
            className="h-10 w-10 rounded-full bg-black/35 p-0 text-white hover:bg-black/55 disabled:opacity-30"
            disabled={!canSwitchCamera}
            onClick={handleSwitchCamera}
            size="sm"
            title={
              canSwitchCamera ? 'Switch camera' : 'Only one camera is available'
            }
            variant="ghost"
          >
            <SwitchCamera aria-hidden="true" size={20} />
          </Button>
        </div>

        {status === 'ready' ? (
          <>
            <p className="absolute inset-x-0 top-[25%] z-10 text-center text-sm font-medium text-white drop-shadow">
              Camera preview
            </p>
            <div className="pointer-events-none absolute inset-x-8 top-[43%] z-10 aspect-square max-h-[290px] -translate-y-1/2 rounded-3xl border-2 border-dashed border-white/85 shadow-[0_0_0_9999px_rgba(11,61,46,0.18)]">
              <p className="absolute inset-x-3 bottom-4 text-center text-sm font-semibold text-white drop-shadow">
                Position ingredients
                <br />
                inside the frame
              </p>
            </div>
          </>
        ) : (
          <div className="absolute inset-0 z-10 flex items-center justify-center px-6">
            <div className="w-full max-w-sm rounded-sheet bg-white p-6 text-center shadow-elevated">
              {status === 'requesting' ? (
                <>
                  <h1 className="text-lg font-bold">Starting camera…</h1>
                  <p className="mt-2 text-sm text-muted">
                    Allow camera access if your browser asks for permission.
                  </p>
                  {!isCameraSupported() && (
                    <p className="mt-2 text-sm text-chilli-red">
                      This browser does not support camera access.
                    </p>
                  )}
                </>
              ) : (
                <div role="alert">
                  <h1 className="text-lg font-bold">Camera unavailable</h1>
                  <p className="mt-2 text-sm text-muted">
                    {error?.message ??
                      'The camera could not be started on this device.'}
                  </p>
                  <div className="mt-5 grid gap-2">
                    <Button
                      className="w-full"
                      onClick={() =>
                        void startCamera({ facingMode: 'environment' })
                      }
                      variant="primary"
                    >
                      <RotateCcw aria-hidden="true" size={16} />
                      Try again
                    </Button>
                    <Button
                      className="w-full"
                      onClick={() => navigate('/ingredients/upload')}
                      variant="secondary"
                    >
                      Upload an image instead
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        <div className="absolute inset-x-0 bottom-0 z-20 bg-gradient-to-t from-black/55 to-transparent px-6 pb-8 pt-12">
          <div className="flex items-center justify-around">
            <Button
              aria-pressed={mode === 'photo'}
              className="min-w-24 gap-2 text-white"
              onClick={() => setMode('photo')}
              variant="ghost"
            >
              <Camera aria-hidden="true" size={18} />
              Photo
            </Button>
            <Button
              className="min-w-24 gap-2 text-white"
              onClick={openGallery}
              variant="ghost"
            >
              <Images aria-hidden="true" size={18} />
              Gallery
            </Button>
          </div>

          <div className="mt-6 flex justify-center">
            <Button
              aria-label="Capture photo"
              className="h-[68px] min-h-[68px] w-[68px] rounded-full border-4 border-white bg-spice-orange p-0 hover:bg-spice-orange/90 disabled:opacity-40"
              disabled={status !== 'ready'}
              onClick={handleCapture}
              size="lg"
            />
          </div>
          <p className="mt-2 text-center text-xs font-medium text-white">
            Capture
          </p>
        </div>

        {notice && (
          <p
            aria-live="polite"
            className="absolute inset-x-6 bottom-40 z-30 rounded-lg bg-white/95 px-3 py-2 text-center text-sm text-ink shadow-card"
            role="status"
          >
            {notice}
          </p>
        )}

        <label className="sr-only" htmlFor="camera-gallery-input">
          Choose an image from gallery
        </label>
        <input
          accept="image/jpeg,image/png"
          className="sr-only"
          id="camera-gallery-input"
          onChange={(event) => void handleGalleryChange(event)}
          ref={galleryInputRef}
          type="file"
        />
      </div>
    </MobileScreen>
  )
}

import { useState, type ChangeEvent, type DragEvent } from 'react'
import { ImagePlus, RotateCcw, Trash } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  formatFileSize,
  readImageFile,
  validateImageFile,
} from '../../services/imageService'
import {
  getRecentImages,
  rememberRecentImage,
  type RecentImage,
} from '../../services/recentImages'
import type { ImagePreviewData } from '../../types/ingredients'

interface UploadLocationState {
  preloadedImage?: ImagePreviewData
}

export function UploadImageScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/home')
  const preloadedImage = (location.state as UploadLocationState | null)
    ?.preloadedImage

  const [selected, setSelected] = useState<ImagePreviewData | null>(
    preloadedImage ?? null,
  )
  const [recentImages, setRecentImages] = useState<RecentImage[]>(() =>
    getRecentImages(),
  )
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [isDragging, setIsDragging] = useState(false)

  function openPicker() {
    const input = document.getElementById('ingredient-image-input')
    if (input instanceof HTMLInputElement) {
      input.value = ''
      input.click()
    }
  }

  async function handleFile(file: File | undefined) {
    if (!file) {
      setNotice('Image selection was cancelled.')
      return
    }

    const validation = validateImageFile(file)
    if (!validation.ok) {
      setError(validation.error)
      return
    }

    try {
      const dataUrl = await readImageFile(file)
      const image: ImagePreviewData = {
        dataUrl,
        name: file.name,
        size: file.size,
        type: file.type,
        source: 'upload',
      }
      setSelected(image)
      setError('')
      setNotice('')
      setRecentImages(
        rememberRecentImage({
          id: `upload-${Date.now()}`,
          name: file.name,
          dataUrl,
          createdAt: Date.now(),
        }),
      )
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : 'We could not open that image. Please try again.',
      )
    }
  }

  function handleInputChange(event: ChangeEvent<HTMLInputElement>) {
    void handleFile(event.target.files?.[0])
    event.target.value = ''
  }

  function handleDrop(event: DragEvent<HTMLButtonElement>) {
    event.preventDefault()
    setIsDragging(false)
    void handleFile(event.dataTransfer.files?.[0])
  }

  function handleRemove() {
    setSelected(null)
    setNotice('Image removed. Choose another image to continue.')
  }

  function handleContinue() {
    if (!selected) {
      setError('Choose an image before continuing.')
      return
    }
    navigate('/ingredients/recognition', { state: { image: selected } })
  }

  function selectRecent(image: RecentImage) {
    if (!image.dataUrl) {
      setError('This preview is no longer available. Choose the image again.')
      return
    }
    setSelected({
      dataUrl: image.dataUrl,
      name: image.name,
      source: 'recent',
    })
    setError('')
    setNotice('')
  }

  return (
    <MobileScreen aria-labelledby="upload-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3 lg:mx-auto lg:max-w-6xl">
        <p className="eyebrow-label">Choose a photo</p>
        <h1 className="mt-1 text-[1.8rem] font-extrabold" id="upload-title">
          Upload Image
        </h1>

        <button
          className={`mt-6 block w-full text-left ${isDragging ? 'opacity-80' : ''}`}
          onClick={openPicker}
          onDragLeave={() => setIsDragging(false)}
          onDragOver={(event) => {
            event.preventDefault()
            setIsDragging(true)
          }}
          onDrop={handleDrop}
          type="button"
        >
          <Card className="flex min-h-[254px] flex-col items-center justify-center gap-3 bg-warm-cream/50 text-center">
            <ImagePlus aria-hidden="true" className="text-ink" size={38} />
            <span className="text-base font-semibold text-ink">
              Image upload area
            </span>
            <span className="max-w-[16rem] text-sm text-muted">
              Choose a clear photo of your ingredients.
            </span>
            <span className="text-xs text-muted">
              supported formats: JPG, PNG
            </span>
          </Card>
        </button>

        {error && (
          <p className="mt-3 text-sm font-medium text-chilli-red" role="alert">
            {error}
          </p>
        )}
        {notice && (
          <p
            aria-live="polite"
            className="mt-3 text-sm text-muted"
            role="status"
          >
            {notice}
          </p>
        )}

        <Button
          className="mt-5 h-[46px] w-full rounded-none text-sm"
          onClick={openPicker}
          size="lg"
        >
          Choose Image
        </Button>

        {selected && (
          <Card className="mt-5">
            <div className="flex gap-3">
              <img
                alt="Selected ingredient image preview"
                className="h-20 w-20 shrink-0 rounded-lg bg-warm-cream object-cover"
                src={selected.dataUrl}
              />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-ink">
                  {selected.name ?? 'Selected image'}
                </p>
                <p className="mt-1 text-xs text-muted">
                  {selected.size
                    ? formatFileSize(selected.size)
                    : 'Ready to review'}
                </p>
                <Button
                  className="mt-1 min-h-8 gap-1 px-2 text-xs font-semibold text-fresh-green"
                  onClick={openPicker}
                  size="sm"
                  variant="ghost"
                >
                  <RotateCcw aria-hidden="true" size={14} />
                  Replace image
                </Button>
              </div>
              <Button
                aria-label="Remove selected image"
                className="h-8 w-8 shrink-0 rounded-full p-0 text-chilli-red"
                onClick={handleRemove}
                size="sm"
                variant="ghost"
              >
                <Trash aria-hidden="true" size={16} />
              </Button>
            </div>
            <Button
              className="mt-4 h-11 w-full"
              onClick={handleContinue}
              variant="primary"
            >
              Continue with Image
            </Button>
          </Card>
        )}

        <section aria-labelledby="recent-title" className="mt-8">
          <h2 className="text-base font-bold" id="recent-title">
            Recent Images
          </h2>
          {recentImages.length === 0 ? (
            <p className="mt-3 text-sm text-muted">
              No recent images in this session.
            </p>
          ) : (
            <ul className="mt-3 grid grid-cols-3 gap-3 md:grid-cols-4 lg:grid-cols-5">
              {recentImages.map((image) => (
                <li key={image.id}>
                  <button
                    className="w-full text-left"
                    onClick={() => selectRecent(image)}
                    type="button"
                  >
                    <Card className="overflow-hidden p-0">
                      {image.dataUrl ? (
                        <img
                          alt={image.name}
                          className="block h-[102px] w-full bg-warm-cream object-cover"
                          src={image.dataUrl}
                        />
                      ) : (
                        <span className="flex h-[102px] w-full items-center justify-center bg-warm-cream text-xs text-muted">
                          Preview unavailable
                        </span>
                      )}
                      <span className="block truncate p-2 text-[11px] font-medium text-ink">
                        {image.name}
                      </span>
                    </Card>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <label className="sr-only" htmlFor="ingredient-image-input">
          Choose an ingredient image
        </label>
        <input
          accept="image/jpeg,image/png"
          className="sr-only"
          id="ingredient-image-input"
          onChange={handleInputChange}
          type="file"
        />
      </section>
    </MobileScreen>
  )
}

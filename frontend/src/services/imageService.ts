export const ACCEPTED_IMAGE_TYPES = ['image/jpeg', 'image/png'] as const
export const MAX_IMAGE_BYTES = 5 * 1024 * 1024

export type ImageValidationResult = { ok: true } | { ok: false; error: string }

function hasSupportedExtension(fileName: string) {
  return /\.(jpe?g|png)$/i.test(fileName)
}

export function validateImageFile(file: File): ImageValidationResult {
  const hasKnownType = (ACCEPTED_IMAGE_TYPES as readonly string[]).includes(
    file.type,
  )

  if (file.type && !hasKnownType) {
    return { ok: false, error: 'Only JPG and PNG images are supported.' }
  }

  if (!file.type && !hasSupportedExtension(file.name)) {
    return { ok: false, error: 'Only JPG and PNG images are supported.' }
  }

  if (file.size === 0) {
    return { ok: false, error: 'That image is empty. Choose another file.' }
  }

  if (file.size > MAX_IMAGE_BYTES) {
    return { ok: false, error: 'Choose an image smaller than 5 MB.' }
  }

  return { ok: true }
}

export function readImageFile(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()

    reader.onerror = () =>
      reject(new Error('We could not read that image. Please try again.'))
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        resolve(reader.result)
        return
      }
      reject(new Error('We could not read that image. Please try again.'))
    }
    reader.readAsDataURL(file)
  })
}

export function formatFileSize(bytes: number) {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

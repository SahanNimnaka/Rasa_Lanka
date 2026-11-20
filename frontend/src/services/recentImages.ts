export interface RecentImage {
  id: string
  name: string
  dataUrl: string
  createdAt: number
}

const STORAGE_KEY = 'rasalanka.recent-images.v1'
const MAX_ENTRIES = 6
const MAX_STORED_DATA_URL_LENGTH = 1_500_000

function canUseSessionStorage() {
  try {
    return typeof window !== 'undefined' && Boolean(window.sessionStorage)
  } catch {
    return false
  }
}

export function getRecentImages(): RecentImage[] {
  if (!canUseSessionStorage()) {
    return []
  }

  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY)
    if (!raw) {
      return []
    }
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) {
      return []
    }
    return parsed.filter(
      (item): item is RecentImage =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as RecentImage).id === 'string' &&
        typeof (item as RecentImage).name === 'string' &&
        typeof (item as RecentImage).dataUrl === 'string' &&
        typeof (item as RecentImage).createdAt === 'number',
    )
  } catch {
    return []
  }
}

export function rememberRecentImage(image: RecentImage): RecentImage[] {
  const storedImage: RecentImage = {
    ...image,
    dataUrl:
      image.dataUrl.length <= MAX_STORED_DATA_URL_LENGTH ? image.dataUrl : '',
  }
  const existing = getRecentImages().filter(
    (item) => item.id !== storedImage.id,
  )
  const next = [storedImage, ...existing].slice(0, MAX_ENTRIES)

  if (canUseSessionStorage()) {
    try {
      window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next))
    } catch {
      // Session storage can be disabled or full. The in-memory state still
      // holds the images for the rest of this screen visit.
    }
  }

  return next
}

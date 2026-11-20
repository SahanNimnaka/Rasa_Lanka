import type { Location } from 'react-router-dom'

interface AuthRedirectState {
  from?: Pick<Location, 'pathname' | 'search' | 'hash'>
}

export function getPostAuthPath(
  location: Location,
  fallback = '/home',
): string {
  const from = (location.state as AuthRedirectState | null)?.from

  if (
    from?.pathname &&
    from.pathname !== '/login' &&
    from.pathname !== '/register'
  ) {
    return `${from.pathname}${from.search ?? ''}${from.hash ?? ''}`
  }

  return fallback
}

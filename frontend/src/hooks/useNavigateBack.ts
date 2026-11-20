import { useCallback } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

export function useNavigateBack(fallback = '/home') {
  const navigate = useNavigate()
  const location = useLocation()

  return useCallback(() => {
    if (location.key === 'default') {
      navigate(fallback, { replace: true })
      return
    }
    navigate(-1)
  }, [fallback, location.key, navigate])
}

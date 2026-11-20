import { useEffect, useState } from 'react'

export function useDebouncedValue<T>(
  value: T,
  delay = 300,
): [T, (value: T) => void] {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay)
    return () => window.clearTimeout(timer)
  }, [delay, value])

  return [debounced, setDebounced]
}

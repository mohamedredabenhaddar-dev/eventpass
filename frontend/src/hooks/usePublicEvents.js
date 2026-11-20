import { useCallback, useEffect, useState } from 'react'
import { fetchPublishedEvents } from '../services/eventService.js'

export function usePublicEvents(page = 1, filters = {}) {
  const [result, setResult] = useState({ key: null, payload: null, error: '' })
  const [attempt, setAttempt] = useState(0)
  const requestKey = `${page}:${attempt}`
  const filtersKey = JSON.stringify(filters)

  useEffect(() => {
    let active = true
    const activeFilters = JSON.parse(filtersKey)

    fetchPublishedEvents(page, activeFilters)
      .then((payload) => {
        if (active) setResult({ key: requestKey, payload, error: '' })
      })
      .catch((requestError) => {
        if (active) {
          setResult((previous) => ({
            key: requestKey,
            payload: previous.payload,
            error: requestError.userMessage || 'Events could not be loaded. Please try again.',
          }))
        }
      })

    return () => { active = false }
  }, [page, requestKey, filtersKey])

  const retry = useCallback(() => setAttempt((value) => value + 1), [])

  return {
    events: result.payload?.data || [],
    meta: result.payload?.meta || null,
    loading: result.key !== requestKey,
    error: result.key === requestKey ? result.error : '',
    retry,
  }
}

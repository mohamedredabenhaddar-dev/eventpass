import { useCallback, useEffect, useMemo, useState } from 'react'
import api, { AUTH_SESSION_EXPIRED_EVENT, TOKEN_STORAGE_KEY } from '../services/api.js'
import { AuthContext } from './authContext.js'

function saveSession(token, user) {
  sessionStorage.setItem(TOKEN_STORAGE_KEY, token)
  return user
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(() => Boolean(sessionStorage.getItem(TOKEN_STORAGE_KEY)))

  useEffect(() => {
    let active = true
    const handleSessionExpired = () => {
      setUser(null)
      setLoading(false)
    }

    window.addEventListener(AUTH_SESSION_EXPIRED_EVENT, handleSessionExpired)
    const token = sessionStorage.getItem(TOKEN_STORAGE_KEY)

    if (!token) {
      return () => {
        active = false
        window.removeEventListener(AUTH_SESSION_EXPIRED_EVENT, handleSessionExpired)
      }
    }

    api.get('/user')
      .then(({ data }) => { if (active) setUser(data) })
      .catch(() => {})
      .finally(() => { if (active) setLoading(false) })

    return () => {
      active = false
      window.removeEventListener(AUTH_SESSION_EXPIRED_EVENT, handleSessionExpired)
    }
  }, [])

  const login = useCallback(async (credentials) => {
    const { data } = await api.post('/login', credentials)
    setUser(saveSession(data.token, data.user))
    return data.user
  }, [])

  const register = useCallback(async (details) => {
    const { data } = await api.post('/register', details)
    setUser(saveSession(data.token, data.user))
    return data.user
  }, [])

  const logout = useCallback(async () => {
    try {
      if (sessionStorage.getItem(TOKEN_STORAGE_KEY)) await api.post('/logout')
    } finally {
      sessionStorage.removeItem(TOKEN_STORAGE_KEY)
      setUser(null)
    }
  }, [])

  const refreshUser = useCallback(async () => {
    const { data } = await api.get('/user')
    setUser(data)
    return data
  }, [])

  const value = useMemo(() => ({ user, loading, login, register, logout, refreshUser }), [user, loading, login, register, logout, refreshUser])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}


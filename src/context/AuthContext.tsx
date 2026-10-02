import { createContext, useContext, useState, useEffect, ReactNode, useCallback } from 'react'
import api, { bootstrapCsrf } from '../services/api'

export interface User {
  id: number
  name: string
  email: string
  role: string
  permissions: string[]
  branch_id: number | null
  is_cross_branch: boolean
  two_factor_enabled: boolean
}

export type AuthStatus = 'loading' | 'unauthenticated' | 'authenticated' | 'two-factor'

interface AuthContextType {
  user: User | null
  status: AuthStatus
  isAuthenticated: boolean
  twoFactorPending: boolean
  login: (email: string, password: string, remember?: boolean) => Promise<AuthStatus>
  completeTwoFactor: (code: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthStatus>('loading')
  const [twoFactorPending, setTwoFactorPending] = useState(false)

  const restoreSession = useCallback(async () => {
    setStatus('loading')
    try {
      const response = await api.get('/me')
      setUser(response.data.data?.user ?? response.data.user ?? null)
      setStatus('authenticated')
    } catch {
      setUser(null)
      setStatus('unauthenticated')
    }
  }, [])

  useEffect(() => {
    restoreSession()
  }, [restoreSession])

  useEffect(() => {
    const handleAuthError = () => {
      setUser(null)
      setStatus('unauthenticated')
      setTwoFactorPending(false)
    }
    window.addEventListener('auth-error', handleAuthError)
    window.addEventListener('auth:session-expired', handleAuthError)
    window.addEventListener('auth-event', handleAuthError)
    return () => {
      window.removeEventListener('auth-error', handleAuthError)
      window.removeEventListener('auth:session-expired', handleAuthError)
      window.removeEventListener('auth-event', handleAuthError)
    }
  }, [])

  const login = async (email: string, password: string, remember = false): Promise<AuthStatus> => {
    await bootstrapCsrf()
    const response = await api.post('/login', { email, password, remember })

    if (response.data.two_factor_required) {
      setTwoFactorPending(true)
      setStatus('two-factor')
      return 'two-factor'
    }

    setUser(response.data.user ?? null)
    setTwoFactorPending(false)
    setStatus('authenticated')
    return 'authenticated'
  }

  const completeTwoFactor = async (code: string): Promise<void> => {
    const response = await api.post('/auth/2fa/verify', { code })
    setUser(response.data.user ?? null)
    setTwoFactorPending(false)
    setStatus('authenticated')
  }

  const logout = async () => {
    try {
      await api.post('/logout')
    } finally {
      setUser(null)
      setTwoFactorPending(false)
      setStatus('unauthenticated')
    }
  }

  return (
    <AuthContext.Provider
      value={{
        user,
        status,
        isAuthenticated: status === 'authenticated',
        twoFactorPending,
        login,
        completeTwoFactor,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  SESSION_EXPIRED_EVENT,
  getMe,
  getSessionToken,
  login,
  logout,
} from '@/api/client'

const user = {
  id: '11111111-1111-4111-8111-111111111111',
  name: 'Amine Ben Salah',
  email: 'amine.bensalah@example.test',
  role: 'Owner',
  city: 'Tunis',
  accent: 'cursor-1',
}

const session = {
  token: 'session-token',
  user,
  workspace: { id: 'w1', name: 'Studio Carthage', plan: 'Pro' },
}

function response(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: status === 401 ? 'Unauthorized' : 'OK',
    json: async () => body,
  } as Response
}

function seedSession(): void {
  window.sessionStorage.setItem('mosaiq-api-token', session.token)
  window.sessionStorage.setItem('mosaiq-api-user', JSON.stringify(user))
}

beforeAll(() => {
  window.matchMedia = window.matchMedia ?? (() => ({
    matches: false,
    media: '(prefers-color-scheme: dark)',
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }) as MediaQueryList)
  Element.prototype.scrollIntoView = () => undefined
  window.HTMLElement.prototype.hasPointerCapture = () => false
  window.HTMLElement.prototype.setPointerCapture = () => undefined
  window.HTMLElement.prototype.releasePointerCapture = () => undefined
})

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  window.history.pushState({}, '', '/')
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.sessionStorage.clear()
  window.localStorage.clear()
})

describe('authentication session handling', () => {
  it('clears the session and emits an expiry event on an authenticated 401', async () => {
    seedSession()
    const listener = vi.fn()
    window.addEventListener(SESSION_EXPIRED_EVENT, listener)
    vi.stubGlobal('fetch', vi.fn(async () => response(401, { error: 'Authentication required.' })))

    await expect(getMe()).rejects.toMatchObject({ status: 401 })

    expect(getSessionToken()).toBeNull()
    expect(listener).toHaveBeenCalledTimes(1)
    window.removeEventListener(SESSION_EXPIRED_EVENT, listener)
  })

  it('redirects a protected route to sign-in and explains the expired session', async () => {
    seedSession()
    vi.stubGlobal('fetch', vi.fn(async () => response(401, { error: 'Authentication required.' })))

    const { default: App } = await import('@/App')
    render(<App />)

    expect(await screen.findByText('Bon retour sur Mosaiq', {}, { timeout: 5_000 })).toBeTruthy()
    expect(await screen.findByText('Votre session a expiré. Reconnectez-vous pour continuer.', {}, { timeout: 5_000 })).toBeTruthy()
    expect(getSessionToken()).toBeNull()
  }, 10_000)

  it('clears local credentials on logout even when the API is unreachable', async () => {
    seedSession()
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new Error('offline')
    }))

    await logout()

    expect(getSessionToken()).toBeNull()
    expect(window.sessionStorage.getItem('mosaiq-api-user')).toBeNull()
  })

  it('stores the session after a successful login', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response(200, session)))

    await login(user.email, 'Correct-Horse-2026!')

    expect(getSessionToken()).toBe(session.token)
    expect(JSON.parse(window.sessionStorage.getItem('mosaiq-api-user') ?? 'null')).toMatchObject({
      id: user.id,
      email: user.email,
    })
  })

  it('does not store a session when login is rejected', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response(401, { error: 'Invalid email or password.' })))

    await expect(login(user.email, 'wrong-password')).rejects.toMatchObject({ status: 401 })

    expect(getSessionToken()).toBeNull()
  })

  it('validates the registration password before calling the API', async () => {
    window.history.pushState({}, '', '/sign-in')
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    const { default: App } = await import('@/App')
    render(<App />)

    fireEvent.click(screen.getByRole('radio', { name: 'Créer un compte' }))
    fireEvent.change(screen.getByLabelText('Nom complet'), { target: { value: 'New Person' } })
    fireEvent.change(screen.getByLabelText('E-mail professionnel'), { target: { value: 'new@example.test' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'short-pass1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon espace' }))

    expect(await screen.findByText(/entre 12 et 72 caractères/)).toBeTruthy()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

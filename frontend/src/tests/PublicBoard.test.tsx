import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const fixture = vi.hoisted(() => {
  const author = {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Amine Ben Salah',
    email: 'amine.bensalah@example.test',
    role: 'Owner' as const,
    city: 'Tunis',
    accent: 'cursor-1' as const,
  }
  return {
    author,
    board: {
      id: '11111111-1111-4111-8111-222222222222',
      title: 'Tableau public',
      starred: false,
      isTemplate: false,
      publicAccess: true,
      members: [{ ...author, boardRole: 'Owner' }],
      noteColors: ['amber'],
      workspace: { id: 'w1', name: 'Studio Carthage' },
      elementCount: 1,
      createdAt: '2026-11-14T09:00:00Z',
      updatedAt: '2026-11-14T09:00:00Z',
      elements: [{
        id: '11111111-1111-4111-8111-333333333333',
        type: 'note',
        x: 100,
        y: 80,
        width: 220,
        height: 140,
        rotation: 0,
        content: 'Décision publique',
        color: 'amber',
        metadata: { stroke: 'none', textSize: 'm', align: 'left' },
        author,
        createdAt: '2026-11-14T09:01:00Z',
      }],
    },
    getPublicBoard: vi.fn(),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getSessionToken: () => null,
    getPublicBoard: fixture.getPublicBoard,
  }
})

import App from '@/App'

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
})

beforeEach(() => {
  window.history.pushState({}, '', '/board/11111111-1111-4111-8111-222222222222')
  window.sessionStorage.clear()
  window.localStorage.clear()
  fixture.getPublicBoard.mockReset()
})

afterEach(cleanup)

describe('signed-out public board', () => {
  it('renders the sanitized board read-only without redirecting to sign-in', async () => {
    fixture.getPublicBoard.mockResolvedValue(fixture.board)

    render(<App />)

    expect(await screen.findByRole('heading', { name: 'Tableau public' })).toBeTruthy()
    expect(screen.getByText('Lecture seule (Accès public)')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Se connecter pour modifier' }).getAttribute('href')).toBe('/sign-in')
    expect(screen.getByText('Décision publique')).toBeTruthy()
    expect(screen.queryByRole('heading', { name: 'Bon retour sur Mosaiq' })).toBeNull()
    expect(window.location.pathname).toBe('/board/11111111-1111-4111-8111-222222222222')
    expect(fixture.getPublicBoard).toHaveBeenCalledWith('11111111-1111-4111-8111-222222222222')
  })

  it('shows the unavailable state when public access is disabled', async () => {
    fixture.getPublicBoard.mockResolvedValue({ ...fixture.board, publicAccess: false })

    render(<App />)

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('Ce tableau n’est pas disponible.')).toBeTruthy()
    expect(screen.queryByText('Lecture seule (Accès public)')).toBeNull()
  })
})

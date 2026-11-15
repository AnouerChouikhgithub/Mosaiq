import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const fixture = vi.hoisted(() => {
  const user = {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Amine Ben Salah',
    email: 'amine.bensalah@example.test',
    role: 'Owner' as const,
    city: 'Tunis',
    accent: 'cursor-1' as const,
  }
  return {
    user,
    board: {
      id: '11111111-1111-4111-8111-222222222222',
      title: 'Atelier badge',
      starred: false,
      isTemplate: false,
      publicAccess: false,
      members: [{ ...user, boardRole: 'Owner' }],
      noteColors: ['amber'],
      workspace: { id: 'w1', name: 'Studio Carthage' },
      elementCount: 0,
      createdAt: '2026-11-14T09:00:00Z',
      updatedAt: '2026-11-14T09:00:00Z',
    },
    getBoard: vi.fn(),
    getCanvasElements: vi.fn(),
    getComments: vi.fn(),
    getPresence: vi.fn(),
    streamBoardEvents: vi.fn(),
    updateBoard: vi.fn(),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getSessionToken: () => 'test-token',
    getSessionUser: () => fixture.user,
    getBoard: fixture.getBoard,
    getCanvasElements: fixture.getCanvasElements,
    getComments: fixture.getComments,
    getPresence: fixture.getPresence,
    streamBoardEvents: fixture.streamBoardEvents,
    updateBoard: fixture.updateBoard,
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
  fixture.getBoard.mockReset().mockResolvedValue(fixture.board)
  fixture.getCanvasElements.mockReset().mockResolvedValue([])
  fixture.getComments.mockReset().mockResolvedValue([])
  fixture.getPresence.mockReset().mockResolvedValue([])
  fixture.streamBoardEvents.mockReset()
  fixture.updateBoard.mockReset()
})

afterEach(cleanup)

describe('live SSE badge', () => {
  it('shows En direct when the authenticated stream emits an event', async () => {
    fixture.streamBoardEvents.mockImplementation(async (
      _boardId: string,
      onEvent: (event: { presence?: [] }) => void,
      signal: AbortSignal,
    ) => {
      onEvent({ presence: [] })
      await new Promise<void>((resolve) => {
        if (signal.aborted) return resolve()
        signal.addEventListener('abort', () => resolve(), { once: true })
      })
    })

    render(<App />)

    expect(await screen.findByText('En direct')).toBeTruthy()
    expect(fixture.streamBoardEvents).toHaveBeenCalledWith(
      '11111111-1111-4111-8111-222222222222',
      expect.any(Function),
      expect.any(AbortSignal),
    )
  })

  it('persists a renamed board title instead of only changing local state', async () => {
    fixture.streamBoardEvents.mockImplementation(async () => undefined)
    fixture.updateBoard.mockResolvedValue({ ...fixture.board, title: 'Titre enregistré' })

    render(<App />)

    const input = await screen.findByLabelText('Titre du tableau')
    fireEvent.change(input, { target: { value: 'Titre enregistré' } })
    fireEvent.blur(input)

    await waitFor(() =>
      expect(fixture.updateBoard).toHaveBeenCalledWith(fixture.board.id, {
        title: 'Titre enregistré',
      }),
    )
    await screen.findByDisplayValue('Titre enregistré')
  })

  it('shows Mode hors ligne when the stream fails while offline', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    fixture.streamBoardEvents.mockRejectedValue(new Error('stream unavailable'))

    render(<App />)

    expect(await screen.findByText('Mode hors ligne')).toBeTruthy()
  })
})

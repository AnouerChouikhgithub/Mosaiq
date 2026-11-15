import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => {
  const user = {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Amine Ben Salah',
    email: 'amine.bensalah@example.test',
    role: 'Owner' as const,
    city: 'Tunis',
    accent: 'cursor-1' as const,
  }
  const board = {
    id: '11111111-1111-4111-8111-222222222222',
    title: 'Atelier cycle',
    starred: false,
    isTemplate: false,
    status: 'active' as const,
    archivedAt: null,
    trashedAt: null,
    publicAccess: false,
    members: [{ ...user, boardRole: 'Owner' }],
    noteColors: ['amber', 'sky', 'mint'],
    workspace: { id: 'w1', name: 'Studio Carthage' },
    elementCount: 0,
    createdAt: '2026-11-14T09:00:00Z',
    updatedAt: '2026-11-14T09:00:00Z',
  }
  return {
    user,
    board,
    archived: false,
    trashed: false,
    getBoards: vi.fn(),
    archiveBoard: vi.fn(),
    trashBoard: vi.fn(),
    restoreBoardLifecycle: vi.fn(),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getBoards: api.getBoards,
    archiveBoard: api.archiveBoard,
    trashBoard: api.trashBoard,
    restoreBoardLifecycle: api.restoreBoardLifecycle,
  }
})

import App from '@/App'

function openMenu(trigger: HTMLElement) {
  trigger.focus()
  fireEvent.keyDown(trigger, { key: 'Enter', code: 'Enter' })
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
  window.sessionStorage.setItem('mosaiq-api-token', 'lifecycle-test-token')
  window.sessionStorage.setItem('mosaiq-api-user', JSON.stringify(api.user))
  window.sessionStorage.setItem('mosaiq-api-workspace', JSON.stringify({ id: 'w1', name: 'Studio Carthage', plan: 'Pro' }))
  api.archived = false
  api.trashed = false
  api.getBoards.mockImplementation(async (_workspaceId?: string, status?: string) => {
    if (status === 'archived') return api.archived ? [{ ...api.board, status: 'archived' }] : []
    if (status === 'trashed') return api.trashed ? [{ ...api.board, status: 'trashed' }] : []
    return api.archived || api.trashed ? [] : [api.board]
  })
  api.archiveBoard.mockImplementation(async () => {
    api.archived = true
    return { ...api.board, status: 'archived' }
  })
  api.trashBoard.mockImplementation(async () => {
    api.trashed = true
    return { ...api.board, status: 'trashed' }
  })
  api.restoreBoardLifecycle.mockImplementation(async () => {
    api.archived = false
    api.trashed = false
    return { ...api.board, status: 'active' }
  })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.sessionStorage.clear()
  window.localStorage.clear()
})

describe('dashboard board lifecycle', () => {
  it('archives, shows the archive view and restores a board', async () => {
    render(<App />)
    await screen.findByText('Atelier cycle')

    openMenu(screen.getByLabelText('Actions pour Atelier cycle'))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Archiver' }))
    expect(await screen.findByRole('heading', { name: 'Archiver « Atelier cycle » ?' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Archiver le tableau' }))
    await waitFor(() => expect(api.archiveBoard).toHaveBeenCalledWith(api.board.id))
    await waitFor(() => expect(screen.queryByText('Atelier cycle')).toBeNull())

    fireEvent.click(screen.getAllByRole('link', { name: 'Archives' })[0])
    expect(await screen.findByText('Atelier cycle')).toBeTruthy()
    await waitFor(() => expect(api.getBoards).toHaveBeenCalledWith(undefined, 'archived'))

    openMenu(screen.getByLabelText('Actions pour Atelier cycle'))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Restaurer' }))
    await waitFor(() => expect(api.restoreBoardLifecycle).toHaveBeenCalledWith(api.board.id))
    await waitFor(() => expect(screen.queryByText('Atelier cycle')).toBeNull())
  })

  it('moves a board to trash and restores it from the trash view', async () => {
    render(<App />)
    await screen.findByText('Atelier cycle')

    openMenu(screen.getByLabelText('Actions pour Atelier cycle'))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Mettre à la corbeille' }))
    expect(await screen.findByRole('heading', { name: 'Mettre « Atelier cycle » à la corbeille ?' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Mettre à la corbeille' }))
    await waitFor(() => expect(api.trashBoard).toHaveBeenCalledWith(api.board.id))

    fireEvent.click(screen.getAllByRole('link', { name: 'Corbeille' })[0])
    expect(await screen.findByText('Atelier cycle')).toBeTruthy()
    await waitFor(() => expect(api.getBoards).toHaveBeenCalledWith(undefined, 'trashed'))

    openMenu(screen.getByLabelText('Actions pour Atelier cycle'))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Restaurer' }))
    await waitFor(() => expect(api.restoreBoardLifecycle).toHaveBeenCalledWith(api.board.id))
    await waitFor(() => expect(screen.queryByText('Atelier cycle')).toBeNull())
  })

  it('shows an archive load error with a retry action', async () => {
    render(<App />)
    await screen.findByText('Atelier cycle')

    api.getBoards.mockImplementation(async (_workspaceId?: string, status?: string) => {
      if (status === 'archived') throw new Error('Serveur indisponible')
      return [api.board]
    })
    fireEvent.click(screen.getAllByRole('link', { name: 'Archives' })[0])

    expect(await screen.findByText('Impossible de charger ces tableaux.')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy()
  })

  it('keeps the card when the backend rejects a lifecycle action', async () => {
    api.archiveBoard.mockRejectedValueOnce(new Error('Action non autorisée.'))
    render(<App />)
    await screen.findByText('Atelier cycle')

    openMenu(screen.getByLabelText('Actions pour Atelier cycle'))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Archiver' }))
    fireEvent.click(screen.getByRole('button', { name: 'Archiver le tableau' }))

    expect(await screen.findByText('Impossible de modifier ce tableau')).toBeTruthy()
    expect(screen.getByText('Atelier cycle')).toBeTruthy()
  })
})

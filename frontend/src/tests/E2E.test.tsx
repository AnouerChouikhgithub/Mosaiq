import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

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
    title: 'Atelier E2E',
    starred: false,
    isTemplate: false,
    publicAccess: false,
    members: [{ ...user, boardRole: 'Owner' }],
    noteColors: ['amber', 'sky', 'mint'],
    workspace: { id: 'w1', name: 'Studio Carthage' },
    elementCount: 0,
    createdAt: '2026-11-14T09:00:00Z',
    updatedAt: '2026-11-14T09:00:00Z',
  }
  const note = {
    id: '11111111-1111-4111-8111-333333333333',
    type: 'note',
    x: 10,
    y: 20,
    width: 220,
    height: 140,
    rotation: 0,
    content: 'Nouvelle note',
    color: 'amber',
    metadata: { stroke: 'none', textSize: 'm', align: 'left' },
    author: user,
    createdAt: '2026-11-14T09:01:00Z',
  }

  return {
    user,
    board,
    note,
    login: vi.fn(async () => {
      window.sessionStorage.setItem('mosaiq-api-token', 'e2e-session-token')
      window.sessionStorage.setItem('mosaiq-api-user', JSON.stringify(user))
      window.sessionStorage.setItem('mosaiq-api-workspace', JSON.stringify({ id: 'w1', name: 'Studio Carthage', plan: 'Pro', memberCount: 8 }))
      return { token: 'e2e-session-token', user, workspace: { id: 'w1', name: 'Studio Carthage', plan: 'Pro', memberCount: 8 } }
    }),
    logout: vi.fn(async () => { window.sessionStorage.clear() }),
    getBoards: vi.fn(async () => [board]),
    getBoard: vi.fn(async () => ({ ...board, elements: [note] })),
    getCanvasElements: vi.fn(async () => []),
    createCanvasElement: vi.fn(async (input: Record<string, unknown>) => ({ ...note, ...input })),
    updateCanvasElement: vi.fn(async (_id: string, patch: Record<string, unknown>) => ({ ...note, ...patch })),
    getComments: vi.fn(async () => []),
    getPresence: vi.fn(async () => []),
    updatePresence: vi.fn(async () => undefined),
    streamBoardEvents: vi.fn(async (_id: string, onEvent: (event: unknown) => void, signal: AbortSignal) => {
      onEvent({ presence: [] })
      await new Promise<void>((resolve) => {
        const timer = setTimeout(resolve, 80)
        signal.addEventListener('abort', () => { clearTimeout(timer); resolve() })
      })
    }),
    getTimeline: vi.fn(async () => [
      { id: 't1', at: '2026-11-14T09:00:00Z', label: 'Board created', kind: 'board', reveals: null, snapshot: null, author: { id: user.id, name: user.name } },
      { id: 't2', at: '2026-11-14T09:01:00Z', label: 'Note added', kind: 'note', reveals: [], snapshot: null, author: { id: user.id, name: user.name } },
      { id: 't3', at: '2026-11-14T09:02:00Z', label: 'Board renamed', kind: 'rename', reveals: null, snapshot: null, author: { id: user.id, name: user.name } },
    ]),
  }
})

vi.mock('@/api/client', () => ({
  getSessionToken: () => window.sessionStorage.getItem('mosaiq-api-token'),
  getSessionUser: () => {
    const value = window.sessionStorage.getItem('mosaiq-api-user')
    return value ? JSON.parse(value) : null
  },
  getSessionWorkspace: () => {
    const value = window.sessionStorage.getItem('mosaiq-api-workspace')
    return value ? JSON.parse(value) : null
  },
  WORKSPACE_UPDATED_EVENT: 'mosaiq:workspace-updated',
  rememberSessionWorkspace: vi.fn(),
  getWorkspaces: vi.fn(async () => []),
  updateWorkspace: vi.fn(),
  getWorkspaceMembers: vi.fn(async () => []),
  updateWorkspaceMember: vi.fn(),
  removeWorkspaceMember: vi.fn(),
  getWorkspaceInvitations: vi.fn(async () => []),
  inviteWorkspaceMember: vi.fn(),
  revokeWorkspaceInvitation: vi.fn(),
  login: api.login,
  register: vi.fn(),
  acceptBoardInvitation: vi.fn(),
  logout: api.logout,
  getBoards: api.getBoards,
  archiveBoard: vi.fn(),
  trashBoard: vi.fn(),
  restoreBoardLifecycle: vi.fn(),
  getBoard: api.getBoard,
  getPublicBoard: vi.fn(),
  createBoard: vi.fn(),
  updateBoard: vi.fn(),
  toggleStarBoard: vi.fn(),
  getCanvasElements: api.getCanvasElements,
  createCanvasElement: api.createCanvasElement,
  updateCanvasElement: api.updateCanvasElement,
  deleteCanvasElement: vi.fn(),
  getComments: api.getComments,
  createComment: vi.fn(),
  replyToComment: vi.fn(),
  resolveComment: vi.fn(),
  getPresence: api.getPresence,
  updatePresence: api.updatePresence,
  streamBoardEvents: api.streamBoardEvents,
  getTimeline: api.getTimeline,
  restoreTimeline: vi.fn(),
  inviteBoardCollaborator: vi.fn(),
  updateBoardMember: vi.fn(),
  removeBoardMember: vi.fn(),
  setBoardLinkAccess: vi.fn(),
  warnFallback: vi.fn(),
  isRemoteId: (id: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id),
  toBoard: (remote: typeof api.board) => ({
    id: remote.id,
    title: remote.title,
    updatedAt: remote.updatedAt,
    updatedAtLabel: 'Today',
    starred: remote.starred,
    memberIds: remote.members.map((member) => member.id),
    noteColors: remote.noteColors,
    isTemplate: remote.isTemplate,
  }),
  toStickyNote: (element: typeof api.note) => ({
    id: element.id,
    text: element.content,
    authorId: element.author.id,
    color: element.color,
    stroke: 'none',
    textSize: 'm',
    align: 'left',
    x: element.x,
    y: element.y,
    width: element.width,
    height: element.height,
    rotation: element.rotation,
  }),
  toTimelineEntry: (entry: { id: string; at: string; label: string; kind: string; author: { id: string; name: string } }) => ({
    id: entry.id,
    userId: entry.author.id,
    authorName: entry.author.name,
    at: entry.at.slice(11, 16),
    label: entry.label,
    kind: entry.kind,
    reveals: [],
  }),
  toCommentThread: (thread: unknown) => thread,
  toLiveCursor: (presence: { user: { id: string; name: string; accent: string }; x: number; y: number }) => ({
    userId: presence.user.id,
    x: presence.x,
    y: presence.y,
    name: presence.user.name,
    accent: presence.user.accent,
  }),
}))

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

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.sessionStorage.clear()
  window.localStorage.clear()
  document.documentElement.classList.remove('dark')
  document.documentElement.dir = 'ltr'
})

describe('Mosaiq end-to-end scenario', () => {
  it('signs in, edits a note, switches locale/theme, scrubs replay and signs out', async () => {
    window.history.pushState({}, '', '/sign-in')
    render(<App />)

    await screen.findByRole('heading', { name: 'Bon retour sur Mosaiq' })
    const form = document.querySelector('form')
    expect(form).not.toBeNull()
    fireEvent.change(within(form as HTMLFormElement).getByLabelText('E-mail professionnel'), {
      target: { value: 'amine.bensalah@example.test' },
    })
    fireEvent.change(within(form as HTMLFormElement).getByLabelText('Mot de passe'), {
      target: { value: 'MosaiqDemo2026!' },
    })
    await act(async () => {
      fireEvent.click(within(form as HTMLFormElement).getByRole('button', { name: /Se connecter/ }))
    })

    await screen.findByRole('heading', { name: 'Tableaux' })
    expect(api.login).toHaveBeenCalledWith('amine.bensalah@example.test', 'MosaiqDemo2026!')

    fireEvent.click(await screen.findByLabelText('Ouvrir le tableau Atelier E2E'))
    await screen.findByDisplayValue('Atelier E2E')
    await screen.findByText('En direct')

    fireEvent.click(screen.getByRole('button', { name: 'Note' }))
    const canvas = screen.getByRole('group', { name: /Canevas du tableau/ })
    fireEvent.click(canvas.firstElementChild as HTMLElement)

    await screen.findByText('Nouvelle note')
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)) })
    fireEvent.doubleClick(screen.getByRole('button', { name: 'Sélectionner la note : Nouvelle note' }))
    const editor = await screen.findByLabelText('Texte de la note')
    fireEvent.change(editor, { target: { value: 'Note éditée en E2E' } })
    const textarea = editor as HTMLTextAreaElement
    expect(textarea.value).toBe('Note éditée en E2E')
    await act(async () => {
      textarea.focus()
      textarea.blur()
    })
    await screen.findByText('Note éditée en E2E')

    openMenu(screen.getByLabelText('Langue'))
    fireEvent.click(await screen.findByRole('menuitem', { name: /English/ }))
    expect(document.documentElement.dir).toBe('ltr')

    openMenu(screen.getByLabelText('Language'))
    fireEvent.click(await screen.findByRole('menuitem', { name: /العربية/ }))
    expect(document.documentElement.dir).toBe('rtl')

    openMenu(screen.getByLabelText('اللغة'))
    fireEvent.click(await screen.findByRole('menuitem', { name: /English/ }))
    expect(document.documentElement.dir).toBe('ltr')

    openMenu(screen.getByLabelText('Theme'))
    fireEvent.click(await screen.findByRole('menuitem', { name: /Dark/ }))
    await waitFor(() => expect(document.documentElement.classList.contains('dark')).toBe(true))
    openMenu(screen.getByLabelText('Theme'))
    fireEvent.click(await screen.findByRole('menuitem', { name: /Light/ }))
    await waitFor(() => expect(document.documentElement.classList.contains('dark')).toBe(false))

    fireEvent.click(screen.getByRole('link', { name: 'Replay' }))
    const scrubber = await screen.findByLabelText('Position in board history')
    fireEvent.change(scrubber, { target: { value: '2' } })
    await screen.findByText(/step 3 of 3/)

    fireEvent.click(screen.getAllByRole('link', { name: 'Exit replay' })[0])
    await screen.findByDisplayValue('Atelier E2E')
    fireEvent.click(screen.getByLabelText('Back to boards'))
    await screen.findByRole('heading', { name: 'Boards' })

    const aside = screen.getByRole('complementary')
    openMenu(within(aside).getByText('Amine Ben Salah').closest('button') as HTMLElement)
    fireEvent.click(await screen.findByRole('menuitem', { name: /Sign out/ }))

    await screen.findByRole('heading', { name: 'Welcome back to Mosaiq' })
    expect(api.logout).toHaveBeenCalled()
    expect(window.sessionStorage.getItem('mosaiq-api-token')).toBeNull()
  }, 15_000)
})
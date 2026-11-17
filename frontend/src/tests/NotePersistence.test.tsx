import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { mergeSyncedNotes } from '@/lib/noteSync'
import type { StickyNote } from '@/mocks'

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
    title: 'Atelier notes',
    starred: false,
    isTemplate: false,
    publicAccess: false,
    members: [{ ...user, boardRole: 'Owner' }],
    noteColors: ['amber'],
    workspace: { id: 'w1', name: 'Studio Carthage' },
    elementCount: 0,
    createdAt: '2026-11-14T09:00:00Z',
    updatedAt: '2026-11-14T09:00:00Z',
  }
  const element = {
    id: '11111111-1111-4111-8111-333333333333',
    type: 'note',
    x: 0,
    y: 0,
    width: 220,
    height: 140,
    rotation: 0,
    content: 'Nouvelle note',
    color: 'amber',
    metadata: { stroke: 'none', textSize: 'm', align: 'left' },
    author: { id: user.id, name: user.name, role: user.role, accent: user.accent },
    createdAt: '2026-11-14T09:01:00Z',
  }

  return {
    user,
    board,
    element,
    getBoard: vi.fn(),
    getCanvasElements: vi.fn(),
    createCanvasElement: vi.fn(),
    updateCanvasElement: vi.fn(),
    getComments: vi.fn(),
    getPresence: vi.fn(),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getBoard: api.getBoard,
    getCanvasElements: api.getCanvasElements,
    createCanvasElement: api.createCanvasElement,
    updateCanvasElement: api.updateCanvasElement,
    getComments: api.getComments,
    getPresence: api.getPresence,
    // The stream is long-lived in production; kept pending so tests only drive
    // the polling path they assert on.
    streamBoardEvents: () => new Promise<void>(() => undefined),
  }
})

import App from '@/App'

const noteButton = () => screen.getByRole('button', { name: 'Sélectionner la note : Nouvelle note' })

let pollCallbacks: Array<() => void> = []
let intervalSpy: { mockRestore: () => void } | undefined

async function renderEditor() {
  render(<App />)
  await screen.findByDisplayValue('Atelier notes')
  fireEvent.click(screen.getByRole('button', { name: 'Note' }))
  fireEvent.click(screen.getByRole('group', { name: /Canevas du tableau/ }).firstElementChild as HTMLElement)
}

/** Runs the editor's 3 s REST poll without waiting three real seconds. */
async function runPoll() {
  await act(async () => {
    pollCallbacks.forEach((callback) => callback())
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
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
})

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  window.sessionStorage.setItem('mosaiq-api-token', 'note-persistence-token')
  window.sessionStorage.setItem('mosaiq-api-user', JSON.stringify(api.user))
  window.sessionStorage.setItem('mosaiq-api-workspace', JSON.stringify({ id: 'w1', name: 'Studio Carthage', plan: 'Pro' }))
  window.history.pushState({}, '', `/board/${api.board.id}`)

  api.getBoard.mockResolvedValue(api.board)
  api.getCanvasElements.mockResolvedValue([])
  api.createCanvasElement.mockResolvedValue(api.element)
  api.updateCanvasElement.mockResolvedValue(api.element)
  api.getComments.mockResolvedValue([])
  api.getPresence.mockResolvedValue([])

  pollCallbacks = []
  const realSetInterval = window.setInterval.bind(window)
  intervalSpy = vi.spyOn(window, 'setInterval').mockImplementation(((
    handler: TimerHandler,
    timeout?: number,
    ...args: unknown[]
  ) => {
    if (timeout === 3000 && typeof handler === 'function') {
      pollCallbacks.push(handler as () => void)
    }
    return realSetInterval(handler, timeout, ...args)
  }) as typeof window.setInterval)
  vi.spyOn(console, 'warn').mockImplementation(() => undefined)
})

afterEach(() => {
  cleanup()
  intervalSpy?.mockRestore()
  vi.restoreAllMocks()
  window.sessionStorage.clear()
  window.localStorage.clear()
})

describe('editor note persistence', () => {
  it('shows a saving state, then the saved state with the server id', async () => {
    let resolveCreate: (value: typeof api.element) => void = () => undefined
    api.createCanvasElement.mockImplementationOnce(
      () => new Promise<typeof api.element>((resolve) => { resolveCreate = resolve }),
    )

    await renderEditor()

    expect(await screen.findByText('Enregistrement…')).toBeTruthy()
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)
    expect(api.createCanvasElement).toHaveBeenCalledTimes(1)

    await act(async () => { resolveCreate(api.element) })

    expect(await screen.findByText('Note enregistrée')).toBeTruthy()
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)

    // Editing now targets the server element, so the temporary id was replaced.
    fireEvent.doubleClick(noteButton())
    const editor = await screen.findByLabelText('Texte de la note')
    fireEvent.change(editor, { target: { value: 'Contenu modifié' } })
    await act(async () => {
      editor.focus()
      editor.blur()
    })

    await waitFor(() =>
      expect(api.updateCanvasElement).toHaveBeenCalledWith(api.element.id, { content: 'Contenu modifié' }),
    )
  })

  it('keeps a failed note visible with its content and a retry action', async () => {
    api.createCanvasElement.mockRejectedValueOnce(new Error('API 500 for /elements'))

    await renderEditor()

    expect(await screen.findByText('Non enregistrée')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Réessayer l’enregistrement de la note' })).toBeTruthy()
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)

    // A local-only note is never patched as if it had a server record.
    fireEvent.doubleClick(noteButton())
    const editor = await screen.findByLabelText('Texte de la note')
    fireEvent.change(editor, { target: { value: 'Contenu conservé' } })
    await act(async () => {
      editor.focus()
      editor.blur()
    })
    expect(api.updateCanvasElement).not.toHaveBeenCalled()
    expect(screen.getByText('Contenu conservé')).toBeTruthy()
  })

  it('retries a failed note once and replaces its temporary id', async () => {
    api.createCanvasElement.mockRejectedValueOnce(new Error('offline'))

    await renderEditor()
    const retry = await screen.findByRole('button', { name: 'Réessayer l’enregistrement de la note' })
    fireEvent.click(retry)

    expect(await screen.findByText('Note enregistrée')).toBeTruthy()
    expect(api.createCanvasElement).toHaveBeenCalledTimes(2)
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)

    fireEvent.doubleClick(noteButton())
    const editor = await screen.findByLabelText('Texte de la note')
    fireEvent.change(editor, { target: { value: 'Après réessai' } })
    await act(async () => {
      editor.focus()
      editor.blur()
    })
    await waitFor(() =>
      expect(api.updateCanvasElement).toHaveBeenCalledWith(api.element.id, { content: 'Après réessai' }),
    )
  })

  it('preserves a pending note through polling and never duplicates it once saved', async () => {
    let resolveCreate: (value: typeof api.element) => void = () => undefined
    api.createCanvasElement.mockImplementationOnce(
      () => new Promise<typeof api.element>((resolve) => { resolveCreate = resolve }),
    )

    await renderEditor()
    expect(await screen.findByText('Enregistrement…')).toBeTruthy()

    // Polling while the create request is still in flight keeps one local note.
    await runPoll()
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)

    await act(async () => { resolveCreate(api.element) })
    expect(await screen.findByText('Note enregistrée')).toBeTruthy()

    // The next poll returns the persisted element: it replaces the local copy
    // instead of appearing twice.
    api.getCanvasElements.mockResolvedValue([api.element])
    await runPoll()
    expect(screen.getAllByText('Nouvelle note')).toHaveLength(1)
    expect(screen.queryByText('Note enregistrée')).toBeNull()
  })
})

describe('canvas note reconciliation', () => {
  const base: Omit<StickyNote, 'id' | 'text'> = {
    authorId: 'author',
    color: 'amber',
    stroke: 'none',
    textSize: 'm',
    align: 'left',
    x: 0,
    y: 0,
    width: 220,
    height: 140,
    rotation: 0,
  }
  const pending: StickyNote = { ...base, id: 'local-1700000000000', text: 'En cours', saveState: 'saving' }
  const failed: StickyNote = { ...base, id: 'local-1700000000001', text: 'Échec', saveState: 'failed' }
  const persisted: StickyNote = { ...base, id: '11111111-1111-4111-8111-333333333333', text: 'Enregistrée' }

  it('keeps pending and failed local notes when server data arrives', () => {
    expect(mergeSyncedNotes([pending, failed], []).map((note) => note.id)).toEqual([pending.id, failed.id])
  })

  it('does not duplicate a note whose id was replaced by the server id', () => {
    const reconciled = mergeSyncedNotes([{ ...persisted, saveState: 'saved' }], [persisted])

    expect(reconciled).toStrictEqual([persisted])
    expect(reconciled).toHaveLength(1)
  })

  it('prefers the server version of a persisted note and appends local-only notes', () => {
    const renamedOnServer = { ...persisted, text: 'Renommée ailleurs' }
    const reconciled = mergeSyncedNotes([{ ...persisted, text: 'Ancienne' }, pending], [renamedOnServer])

    expect(reconciled).toStrictEqual([renamedOnServer, pending])
  })
})

import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  createBoard: vi.fn(),
  createCanvasElement: vi.fn(),
  deleteCanvasElement: vi.fn(),
  trashBoard: vi.fn(),
  getSessionUser: vi.fn(),
  getSessionWorkspace: vi.fn(),
}))

const ai = vi.hoisted(() => ({
  generate: vi.fn(),
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    createBoard: api.createBoard,
    createCanvasElement: api.createCanvasElement,
    deleteCanvasElement: api.deleteCanvasElement,
    trashBoard: api.trashBoard,
    getSessionUser: api.getSessionUser,
    getSessionWorkspace: api.getSessionWorkspace,
  }
})

vi.mock('@/api/aiBrainstorming', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/aiBrainstorming')>()
  return { ...actual, generateBrainstormingProposal: ai.generate }
})

import { BrainstormingDialog } from '@/features/ai/BrainstormingDialog'
import { layoutNotesOverlap, layoutProposalSections } from '@/features/ai/layout'
import type { AiBrainstormingResponse, AiNoteType } from '@/features/ai/types'
import type { CanvasElementInput } from '@/api/client'
import { I18nProvider } from '@/i18n'
import { ToastProvider } from '@/components/ui/toast'

/** Deterministic proposal: 3 sections × 3 notes, colours mapped by note type. */
const noteTypes: AiNoteType[] = ['idea', 'question', 'action']
const sectionTitles = ['Session goals', 'Options to explore', 'Next steps']

function fixture(): AiBrainstormingResponse {
  return {
    schemaVersion: '1.0',
    language: 'en',
    detailLevel: 'balanced',
    truncated: false,
    proposal: {
      title: 'Shared bikes in Tunis',
      objective: 'Pick three pilot districts',
      sections: sectionTitles.map((title, sectionIndex) => ({
        id: `s${sectionIndex + 1}`,
        title,
        category: sectionIndex === 0 ? 'goals' : 'ideas',
        notes: noteTypes.map((type, noteIndex) => ({
          id: `s${sectionIndex + 1}-n${noteIndex + 1}`,
          type,
          content: `${title} note ${noteIndex + 1}`,
        })),
      })),
    },
  }
}

function sessionUser(role: 'Owner' | 'Editor' = 'Owner') {
  return {
    id: 'user-1',
    name: 'Anouer',
    email: 'anouer@example.test',
    role,
    city: 'Tunis',
    accent: 'cursor-1',
  }
}

function renderDialog() {
  const onOpenChange = vi.fn()
  const view = render(
    <MemoryRouter initialEntries={['/']}>
      <I18nProvider>
        <ToastProvider>
          <Routes>
            <Route
              path="/"
              element={<BrainstormingDialog open onOpenChange={onOpenChange} />}
            />
            <Route path="/board/:id" element={<p>board route</p>} />
          </Routes>
        </ToastProvider>
      </I18nProvider>
    </MemoryRouter>,
  )
  return { ...view, onOpenChange }
}

async function openPreview() {
  ai.generate.mockResolvedValue(fixture())
  renderDialog()
  fireEvent.change(screen.getByLabelText('Topic'), {
    target: { value: 'Shared bikes in Tunis' },
  })
  fireEvent.change(screen.getByLabelText('Objective'), {
    target: { value: 'Pick three pilot districts' },
  })
  fireEvent.click(screen.getByLabelText('I understand'))
  fireEvent.click(screen.getByRole('button', { name: 'Generate the proposal' }))
  await screen.findByRole('heading', { name: 'Review the proposal' })
}

function approve() {
  fireEvent.click(screen.getByRole('button', { name: 'Create the board' }))
}

function createElementPayloads(): CanvasElementInput[] {
  return api.createCanvasElement.mock.calls.map((call) => call[1] as CanvasElementInput)
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

let elementSequence = 0

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.setItem('mosaiq-locale', 'en')
  ai.generate.mockReset()
  api.createBoard.mockReset()
  api.createCanvasElement.mockReset()
  api.deleteCanvasElement.mockReset()
  api.trashBoard.mockReset()
  api.getSessionUser.mockReset()
  api.getSessionWorkspace.mockReset()

  elementSequence = 0
  api.getSessionUser.mockReturnValue(sessionUser())
  api.getSessionWorkspace.mockReturnValue({ id: 'workspace-1', name: 'Mosaiq', plan: 'Pro' })
  api.createBoard.mockResolvedValue({ id: 'board-1', title: 'Shared bikes in Tunis' })
  api.createCanvasElement.mockImplementation(
    async (_boardId: string, payload: CanvasElementInput) => {
      elementSequence += 1
      return {
        id: `element-${elementSequence}`,
        content: payload.content ?? null,
        color: payload.color ?? null,
        metadata: payload.metadata ?? null,
        mutationId: payload.mutationId ?? null,
        type: payload.type,
        x: payload.x,
        y: payload.y,
        width: payload.width,
        height: payload.height,
        rotation: payload.rotation ?? 0,
        createdAt: new Date(0).toISOString(),
        author: { id: 'user-1', name: 'Anouer', role: 'Owner', accent: 'cursor-1' },
      }
    },
  )
  api.deleteCanvasElement.mockResolvedValue(undefined)
  api.trashBoard.mockResolvedValue({ id: 'board-1' })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('AI proposal import', () => {
  it('creates the board and one ordinary note per approved note', async () => {
    await openPreview()
    approve()

    await waitFor(() => expect(api.createCanvasElement).toHaveBeenCalledTimes(9))
    expect(api.createBoard).toHaveBeenCalledTimes(1)
    expect(api.createBoard).toHaveBeenCalledWith('Shared bikes in Tunis', 'workspace-1', [
      'amber',
      'rose',
      'sky',
      'mint',
      'lilac',
    ])
    expect(await screen.findByText('board route')).toBeTruthy()
  })

  it('maps every note to a canvas note with colour, metadata and a unique key', async () => {
    await openPreview()
    approve()
    await waitFor(() => expect(api.createCanvasElement).toHaveBeenCalledTimes(9))

    const payloads = createElementPayloads()
    const colors = ['amber', 'sky', 'lilac']

    payloads.forEach((payload, index) => {
      expect(payload.type).toBe('note')
      expect(payload.authorId).toBe('user-1')
      expect(payload.rotation).toBe(0)
      expect(payload.content).toBe(`${sectionTitles[Math.floor(index / 3)]} note ${(index % 3) + 1}`)
      expect(payload.color).toBe(colors[index % 3])
      expect(payload.metadata).toMatchObject({
        stroke: 'none',
        textSize: 'm',
        align: 'left',
        aiNoteType: noteTypes[index % 3],
        aiSectionId: `s${Math.floor(index / 3) + 1}`,
        aiSectionTitle: sectionTitles[Math.floor(index / 3)],
      })
    })

    const mutationIds = payloads.map((payload) => payload.mutationId)
    expect(new Set(mutationIds).size).toBe(9)
    expect(mutationIds.every((id) => typeof id === 'string' && id.length === 36)).toBe(true)
    // One create per note, sent through the normal element endpoint.
    expect(api.createCanvasElement.mock.calls.every((call) => call[0] === 'board-1')).toBe(true)
  })

  it('places notes deterministically without overlap and inside the canvas', async () => {
    await openPreview()
    approve()
    await waitFor(() => expect(api.createCanvasElement).toHaveBeenCalledTimes(9))

    const payloads = createElementPayloads()
    const expected = layoutProposalSections([3, 3, 3])

    payloads.forEach((payload, index) => {
      expect(payload.x).toBe(expected.notes[index].x)
      expect(payload.y).toBe(expected.notes[index].y)
      expect(payload.width).toBe(expected.noteWidth)
      expect(payload.height).toBe(expected.noteHeight)
      expect((payload.x ?? 0) + (payload.width ?? 0)).toBeLessThanOrEqual(1400)
      expect((payload.y ?? 0) + (payload.height ?? 0)).toBeLessThanOrEqual(880)
    })

    for (let first = 0; first < payloads.length; first += 1) {
      for (let second = first + 1; second < payloads.length; second += 1) {
        const a = payloads[first]
        const b = payloads[second]
        expect(
          layoutNotesOverlap(
            { x: a.x ?? 0, y: a.y ?? 0, width: a.width ?? 0, height: a.height ?? 0 },
            { x: b.x ?? 0, y: b.y ?? 0, width: b.width ?? 0, height: b.height ?? 0 },
          ),
        ).toBe(false)
      }
    }
  })

  it('imports the edited proposal, not the generated one', async () => {
    await openPreview()

    fireEvent.change(screen.getByLabelText('Board title'), {
      target: { value: 'Bikes in Greater Tunis' },
    })
    fireEvent.change(screen.getAllByLabelText('Note content')[0], {
      target: { value: 'Agree on one measurable outcome' },
    })
    approve()

    await waitFor(() => expect(api.createCanvasElement).toHaveBeenCalledTimes(9))
    expect(api.createBoard.mock.calls[0][0]).toBe('Bikes in Greater Tunis')
    expect(createElementPayloads()[0].content).toBe('Agree on one measurable outcome')
  })

  it('writes nothing before approval', async () => {
    await openPreview()

    expect(api.createBoard).not.toHaveBeenCalled()
    expect(api.createCanvasElement).not.toHaveBeenCalled()
    expect(globalThis.fetch).toBeDefined()
  })

  it('refuses to import a proposal edited beyond the limits', async () => {
    await openPreview()

    fireEvent.change(screen.getAllByLabelText('Section title')[0], {
      target: { value: 'ab' },
    })
    expect(screen.getByText('Limits exceeded')).toBeTruthy()
    expect(
      (screen.getByRole('button', { name: 'Create the board' }) as HTMLButtonElement).disabled,
    ).toBe(true)

    approve()
    expect(api.createBoard).not.toHaveBeenCalled()
    expect(api.createCanvasElement).not.toHaveBeenCalled()
  })

  it('starts a single import when approval is clicked twice', async () => {
    let release: (value: unknown) => void = () => undefined
    api.createBoard.mockImplementation(
      () => new Promise((resolve) => {
        release = resolve
      }),
    )
    await openPreview()

    // The same node is clicked three times: once before the re-render and twice
    // after it. Only the first click may start an import.
    const action = screen.getByRole('button', { name: 'Create the board' })
    fireEvent.click(action)
    fireEvent.click(action)
    fireEvent.click(action)

    expect(api.createBoard).toHaveBeenCalledTimes(1)
    release({ id: 'board-1' })
    await waitFor(() => expect(api.createCanvasElement).toHaveBeenCalledTimes(9))
    expect(api.createBoard).toHaveBeenCalledTimes(1)
  })

  it('rolls a failed import back and reports it', async () => {
    api.createCanvasElement
      .mockImplementationOnce(async (_boardId: string, payload: CanvasElementInput) => ({
        id: 'element-1',
        content: payload.content ?? null,
        color: payload.color ?? null,
        metadata: payload.metadata ?? null,
        mutationId: payload.mutationId ?? null,
        type: 'note',
        x: payload.x,
        y: payload.y,
        width: payload.width,
        height: payload.height,
        rotation: 0,
        createdAt: new Date(0).toISOString(),
        author: { id: 'user-1', name: 'Anouer', role: 'Owner', accent: 'cursor-1' },
      }))
      .mockRejectedValue(Object.assign(new Error('Element rejected.'), { status: 422 }))

    await openPreview()
    approve()

    expect(await screen.findByText('Import failed')).toBeTruthy()
    // The created note is removed and the board is trashed: nothing partial stays.
    expect(api.deleteCanvasElement).toHaveBeenCalledWith('element-1')
    expect(api.trashBoard).toHaveBeenCalledWith('board-1')
    expect(screen.queryByText('board route')).toBeNull()
    expect(screen.getByRole('heading', { name: 'Review the proposal' })).toBeTruthy()
  })

  it('reports a permission change instead of creating notes', async () => {
    api.createBoard.mockRejectedValue(
      Object.assign(new Error('Only workspace administrators can create boards.'), { status: 403 }),
    )

    await openPreview()
    approve()

    expect(await screen.findByText('Import failed')).toBeTruthy()
    expect(
      screen.getByText(
        'Your permissions changed: only workspace administrators can create the board.',
      ),
    ).toBeTruthy()
    expect(api.createCanvasElement).not.toHaveBeenCalled()
    expect(api.trashBoard).not.toHaveBeenCalled()
  })

  it('reports an expired session', async () => {
    api.createBoard.mockRejectedValue(
      Object.assign(new Error('Authentication required.'), { status: 401 }),
    )

    await openPreview()
    approve()

    expect(await screen.findByText('Import failed')).toBeTruthy()
    expect(screen.getByText('Your session expired. Sign in again and retry.')).toBeTruthy()
  })

  it('never announces success before the last note is persisted', async () => {
    let finishLast: (value: unknown) => void = () => undefined
    let calls = 0
    api.createCanvasElement.mockImplementation(
      async (_boardId: string, payload: CanvasElementInput) => {
        calls += 1
        const element = {
          id: `element-${calls}`,
          content: payload.content ?? null,
          color: payload.color ?? null,
          metadata: payload.metadata ?? null,
          mutationId: payload.mutationId ?? null,
          type: 'note',
          x: payload.x,
          y: payload.y,
          width: payload.width,
          height: payload.height,
          rotation: 0,
          createdAt: new Date(0).toISOString(),
          author: { id: 'user-1', name: 'Anouer', role: 'Owner', accent: 'cursor-1' },
        }
        if (calls === 9) {
          return new Promise((resolve) => {
            finishLast = resolve
          })
        }
        return element
      },
    )

    await openPreview()
    approve()

    await screen.findByText('Creating the board and its notes. Do not close this window.')
    expect(screen.queryByText(/was created/)).toBeNull()

    finishLast({ id: 'element-9' })
    expect(await screen.findByText('“Shared bikes in Tunis” was created')).toBeTruthy()
  })

  it('does not offer approval to a non-administrator', async () => {
    api.getSessionUser.mockReturnValue(sessionUser('Editor'))

    await openPreview()

    expect(
      screen.getByText(
        'Only a workspace administrator can create a board. You can generate and edit proposals, but not import them.',
      ),
    ).toBeTruthy()
    expect(
      (screen.getByRole('button', { name: 'Create the board' }) as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(api.createBoard).not.toHaveBeenCalled()
  })
})

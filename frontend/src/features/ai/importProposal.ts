import {
  createBoard,
  createCanvasElement,
  deleteCanvasElement,
  getSessionUser,
  trashBoard,
  type ApiCanvasElement,
  type ApiSessionUser,
} from '@/api/client'
import { createMutationId } from '@/lib/mutationId'
import type { NoteColor } from '@/mocks'
import { layoutProposalSections, type ProposalLayout } from './layout'
import { AI_BOARD_NOTE_COLORS, AI_NOTE_TYPE_COLORS } from './limits'
import type { ProposalDraft } from './proposalDraft'
import { normalizePromptText, validateProposalDraft } from './validation'
import type { AiNoteType } from './types'

/**
 * Turns an approved preview draft into an ordinary Mosaiq board.
 *
 * Nothing here is AI-specific persistence: the board is created with the normal
 * `POST /api/boards` and every note with the normal idempotent
 * `POST /api/boards/{id}/elements`, exactly like a manually created note. The
 * provider never creates entities; the client does, after explicit approval.
 */

export type ProposalImportErrorKind =
  | 'invalid'
  | 'forbidden'
  | 'unauthorized'
  | 'network'
  | 'failed'

export class ProposalImportError extends Error {
  readonly kind: ProposalImportErrorKind

  constructor(kind: ProposalImportErrorKind, message: string, options: { cause?: unknown } = {}) {
    super(message, options.cause === undefined ? undefined : { cause: options.cause })
    this.name = 'ProposalImportError'
    this.kind = kind
  }
}

export interface ProposalImportNote {
  /** Minted once per note; every retry of this create reuses it. */
  mutationId: string
  sectionId: string
  sectionIndex: number
  sectionTitle: string
  noteType: AiNoteType
  content: string
  color: NoteColor
  x: number
  y: number
  width: number
  height: number
}

export interface ProposalImportPlan {
  title: string
  notes: ProposalImportNote[]
  layout: ProposalLayout
}

export interface ImportProgress {
  completed: number
  total: number
}

export interface ImportProposalOptions {
  workspaceId?: string
  onProgress?: (progress: ImportProgress) => void
  signal?: AbortSignal
}

function statusOf(error: unknown): number | undefined {
  if (error && typeof error === 'object' && 'status' in error) {
    const status = (error as { status?: unknown }).status
    return typeof status === 'number' ? status : undefined
  }
  return undefined
}

function mapImportFailure(error: unknown): ProposalImportError {
  if (error instanceof ProposalImportError) return error
  const status = statusOf(error)
  if (status === 401) return new ProposalImportError('unauthorized', 'Session expired.', { cause: error })
  if (status === 403) return new ProposalImportError('forbidden', 'Not allowed to import here.', { cause: error })
  if (status === 422) return new ProposalImportError('invalid', 'The board payload was rejected.', { cause: error })
  if (status === undefined) return new ProposalImportError('network', 'The API could not be reached.', { cause: error })
  return new ProposalImportError('failed', `The import failed with ${status}.`, { cause: error })
}

/**
 * Validates the edited draft again and freezes the exact board, notes and
 * coordinates that approval will persist. Pure apart from the minted UUIDs.
 *
 * @throws ProposalImportError when the draft no longer satisfies the contract.
 */
export function buildProposalImportPlan(draft: ProposalDraft): ProposalImportPlan {
  const issues = validateProposalDraft(draft)
  if (issues.length > 0) {
    throw new ProposalImportError('invalid', 'The proposal is not valid.')
  }

  const title = normalizePromptText(draft.title)
  const sections = draft.sections
    .map((section) => ({
      id: section.id,
      title: normalizePromptText(section.title),
      notes: section.notes
        .map((note) => ({
          type: note.type,
          content: normalizePromptText(note.content),
        }))
        .filter((note) => note.content.length > 0),
    }))
    .filter((section) => section.notes.length > 0)

  if (sections.length === 0) {
    throw new ProposalImportError('invalid', 'The proposal has no notes.')
  }

  // Placement is computed from the same counts in the same order the notes are
  // created, so section grouping and coordinates cannot drift apart.
  const layout = layoutProposalSections(sections.map((section) => section.notes.length))
  const placed = new Map(
    layout.notes.map((note) => [`${note.sectionIndex}-${note.noteIndex}`, note]),
  )

  const notes: ProposalImportNote[] = []
  sections.forEach((section, sectionIndex) => {
    section.notes.forEach((note, noteIndex) => {
      const position = placed.get(`${sectionIndex}-${noteIndex}`)
      if (!position) {
        throw new ProposalImportError('invalid', 'The layout is incomplete.')
      }
      notes.push({
        mutationId: createMutationId(),
        sectionId: section.id,
        sectionIndex,
        sectionTitle: section.title,
        noteType: note.type,
        content: note.content,
        color: AI_NOTE_TYPE_COLORS[note.type],
        x: position.x,
        y: position.y,
        width: position.width,
        height: position.height,
      })
    })
  })

  return { title, notes, layout }
}

function requireAuthor(): ApiSessionUser {
  const user = getSessionUser()
  if (!user) {
    throw new ProposalImportError('unauthorized', 'Authentication required.')
  }
  return user
}

/**
 * One ordinary create, retried once with the SAME mutation id for transport
 * errors, 5xx and 409. The server replays the stored element for a repeated key,
 * so a retry can never duplicate the note. A 4xx is final: the key was reused
 * with different content or the caller lost permission.
 */
async function createNoteWithRetry(
  boardId: string,
  authorId: string,
  note: ProposalImportNote,
): Promise<ApiCanvasElement> {
  const payload = {
    authorId,
    mutationId: note.mutationId,
    type: 'note',
    x: note.x,
    y: note.y,
    width: note.width,
    height: note.height,
    rotation: 0,
    content: note.content,
    color: note.color,
    metadata: {
      stroke: 'none',
      textSize: 'm',
      align: 'left',
      aiNoteType: note.noteType,
      aiSectionId: note.sectionId,
      aiSectionTitle: note.sectionTitle,
    },
  }

  let lastError: unknown
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      return await createCanvasElement(boardId, payload)
    } catch (error) {
      lastError = error
      const status = statusOf(error)
      const retryable = status === undefined || status === 409 || status >= 500
      if (!retryable || attempt === 1) break
    }
  }
  throw lastError
}

/**
 * Compensating rollback for an aborted import.
 *
 * Mosaiq has no permanent board deletion (it is PLANNED), so the import cannot
 * be undone server-side in one transaction. It removes the notes it created and
 * trashes the board, which leaves nothing active and nothing partially visible
 * to collaborators; the trashed board is empty and can be restored or left
 * alone. The notes and their timeline entries share one transaction each, so a
 * deleted note never leaves an inconsistent history.
 */
async function rollbackImport(
  boardId: string,
  created: readonly ApiCanvasElement[],
): Promise<void> {
  for (const element of [...created].reverse()) {
    await deleteCanvasElement(element.id).catch(() => undefined)
  }
  await trashBoard(boardId).catch(() => undefined)
}

/**
 * Creates the board and its notes through the normal endpoints, in the order the
 * user reviewed.
 *
 * @throws ProposalImportError — callers must not show a success message.
 */
export async function importProposal(
  draft: ProposalDraft,
  options: ImportProposalOptions = {},
): Promise<{ boardId: string; noteCount: number }> {
  const author = requireAuthor()
  const plan = buildProposalImportPlan(draft)

  if (options.signal?.aborted) {
    throw new ProposalImportError('failed', 'Import cancelled.')
  }

  let boardId: string
  try {
    // All five palette tokens are sent so every note-type colour stays valid for
    // the board, even when this proposal happens to use only some of them.
    const board = await createBoard(plan.title, options.workspaceId, [
      ...AI_BOARD_NOTE_COLORS,
    ])
    boardId = board.id
  } catch (error) {
    // Board creation is one transaction on the server: on failure nothing exists.
    throw mapImportFailure(error)
  }

  const created: ApiCanvasElement[] = []
  options.onProgress?.({ completed: 0, total: plan.notes.length })

  try {
    for (const note of plan.notes) {
      if (options.signal?.aborted) {
        throw new ProposalImportError('failed', 'Import cancelled.')
      }
      created.push(await createNoteWithRetry(boardId, author.id, note))
      options.onProgress?.({ completed: created.length, total: plan.notes.length })
    }
  } catch (error) {
    await rollbackImport(boardId, created)
    throw mapImportFailure(error)
  }

  return { boardId, noteCount: created.length }
}

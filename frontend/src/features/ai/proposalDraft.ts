import { AI_BRAINSTORMING_LIMITS } from './limits'
import type {
  AiBrainstormingResponse,
  AiNoteType,
  AiSectionCategory,
} from './types'

/**
 * Local, editable copy of a proposal. It lives only in component state: the
 * preview has no persistence path and never touches a board (§8.5).
 */
export interface ProposalDraftNote {
  id: string
  type: AiNoteType
  content: string
}

export interface ProposalDraftSection {
  id: string
  title: string
  category: AiSectionCategory
  notes: ProposalDraftNote[]
}

export interface ProposalDraft {
  title: string
  objective: string
  sections: ProposalDraftSection[]
}

export type ProposalDraftAction =
  | { type: 'title'; value: string }
  | { type: 'objective'; value: string }
  | { type: 'sectionTitle'; sectionId: string; value: string }
  | { type: 'sectionCategory'; sectionId: string; value: AiSectionCategory }
  | { type: 'moveSection'; sectionId: string; direction: 'up' | 'down' }
  | { type: 'removeSection'; sectionId: string }
  | { type: 'addSection' }
  | { type: 'noteContent'; sectionId: string; noteId: string; value: string }
  | { type: 'noteType'; sectionId: string; noteId: string; value: AiNoteType }
  | { type: 'removeNote'; sectionId: string; noteId: string }
  | { type: 'addNote'; sectionId: string }

/** Deep copy so edits cannot mutate the received response object. */
export function draftFromResponse(response: AiBrainstormingResponse): ProposalDraft {
  return {
    title: response.proposal.title,
    objective: response.proposal.objective,
    sections: response.proposal.sections.map((section) => ({
      id: section.id,
      title: section.title,
      category: section.category,
      notes: section.notes.map((note) => ({
        id: note.id,
        type: note.type,
        content: note.content,
      })),
    })),
  }
}

export function totalDraftNotes(draft: ProposalDraft): number {
  return draft.sections.reduce((count, section) => count + section.notes.length, 0)
}

function unusedId(prefix: string, taken: Set<string>): string {
  let index = 1
  while (taken.has(`${prefix}${index}`)) index += 1
  return `${prefix}${index}`
}

/**
 * Contract §4.2 ids are stable only for the response, but keeping the same
 * format means Sprint 2 can use a preview id as a draft key without rewriting.
 */
export function nextSectionId(draft: ProposalDraft): string {
  return unusedId('s', new Set(draft.sections.map((section) => section.id)))
}

export function nextNoteId(section: ProposalDraftSection): string {
  return unusedId(`${section.id}-n`, new Set(section.notes.map((note) => note.id)))
}

function mapSection(
  draft: ProposalDraft,
  sectionId: string,
  update: (section: ProposalDraftSection) => ProposalDraftSection,
): ProposalDraft {
  return {
    ...draft,
    sections: draft.sections.map((section) =>
      section.id === sectionId ? update(section) : section,
    ),
  }
}

function mapNote(
  section: ProposalDraftSection,
  noteId: string,
  update: (note: ProposalDraftNote) => ProposalDraftNote,
): ProposalDraftSection {
  return {
    ...section,
    notes: section.notes.map((note) => (note.id === noteId ? update(note) : note)),
  }
}

/**
 * Caps are enforced here rather than only in the UI so a reordered or
 * programmatically dispatched action can never create an invalid proposal.
 */
export function proposalDraftReducer(
  draft: ProposalDraft,
  action: ProposalDraftAction,
): ProposalDraft {
  const limits = AI_BRAINSTORMING_LIMITS

  switch (action.type) {
    case 'title':
      return { ...draft, title: action.value }
    case 'objective':
      return { ...draft, objective: action.value }
    case 'sectionTitle':
      return mapSection(draft, action.sectionId, (section) => ({
        ...section,
        title: action.value,
      }))
    case 'sectionCategory':
      return mapSection(draft, action.sectionId, (section) => ({
        ...section,
        category: action.value,
      }))
    case 'noteContent':
      return mapSection(draft, action.sectionId, (section) =>
        mapNote(section, action.noteId, (note) => ({ ...note, content: action.value })),
      )
    case 'noteType':
      return mapSection(draft, action.sectionId, (section) =>
        mapNote(section, action.noteId, (note) => ({ ...note, type: action.value })),
      )
    case 'removeSection': {
      if (draft.sections.length <= limits.sectionsMin) return draft
      return {
        ...draft,
        sections: draft.sections.filter((section) => section.id !== action.sectionId),
      }
    }
    case 'moveSection': {
      const index = draft.sections.findIndex((section) => section.id === action.sectionId)
      if (index === -1) return draft
      const target = action.direction === 'up' ? index - 1 : index + 1
      if (target < 0 || target >= draft.sections.length) return draft
      const sections = [...draft.sections]
      const [moved] = sections.splice(index, 1)
      sections.splice(target, 0, moved)
      return { ...draft, sections }
    }
    case 'addSection': {
      if (draft.sections.length >= limits.sectionsMax) return draft
      const id = nextSectionId(draft)
      return {
        ...draft,
        sections: [
          ...draft.sections,
          {
            id,
            title: '',
            category: 'ideas',
            notes: [{ id: `${id}-n1`, type: 'idea', content: '' }],
          },
        ],
      }
    }
    case 'removeNote': {
      return mapSection(draft, action.sectionId, (section) => {
        if (section.notes.length <= limits.notesPerSectionMin) return section
        return {
          ...section,
          notes: section.notes.filter((note) => note.id !== action.noteId),
        }
      })
    }
    case 'addNote': {
      if (totalDraftNotes(draft) >= limits.totalNotesMax) return draft
      return mapSection(draft, action.sectionId, (section) => {
        if (section.notes.length >= limits.notesPerSectionMax) return section
        return {
          ...section,
          notes: [
            ...section.notes,
            { id: nextNoteId(section), type: 'idea', content: '' },
          ],
        }
      })
    }
    default:
      return draft
  }
}

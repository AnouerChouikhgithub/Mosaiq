import type { NoteColor } from '@/mocks'
import type {
  AiDetailLevel,
  AiLanguage,
  AiNoteType,
  AiSectionCategory,
} from './types'

/**
 * The authoritative limits from contract §5. Validation, counters and the
 * preview all read these constants; no literal limit is repeated elsewhere.
 */
export const AI_BRAINSTORMING_LIMITS = {
  topicMin: 3,
  topicMax: 280,
  objectiveMin: 5,
  objectiveMax: 500,
  contextMax: 2000,
  sectionsMin: 1,
  sectionsMax: 5,
  sectionTitleMin: 3,
  sectionTitleMax: 80,
  notesPerSectionMin: 1,
  notesPerSectionMax: 10,
  totalNotesMin: 3,
  totalNotesMax: 25,
  noteContentMin: 3,
  noteContentMax: 280,
  proposalTitleMin: 3,
  proposalTitleMax: 120,
  proposalObjectiveMin: 5,
  proposalObjectiveMax: 300,
  requestBodyMaxBytes: 8192,
  responseMaxBytes: 65536,
  /** §6.9 — overrides the default 5 s request bound of the shared client. */
  requestTimeoutMs: 35_000,
} as const

export const AI_LANGUAGES: readonly AiLanguage[] = ['en', 'fr', 'ar'] as const

export const AI_DETAIL_LEVELS: readonly AiDetailLevel[] = [
  'concise',
  'balanced',
  'detailed',
] as const

export const AI_NOTE_TYPES: readonly AiNoteType[] = [
  'idea',
  'question',
  'challenge',
  'opportunity',
  'action',
] as const

export const AI_SECTION_CATEGORIES: readonly AiSectionCategory[] = [
  'goals',
  'audience',
  'ideas',
  'risks',
  'actions',
  'questions',
] as const

/** §4.4 — prompt budget per level; the hard caps above still win. */
export const AI_DETAIL_NOTE_BUDGET: Record<AiDetailLevel, number> = {
  concise: 9,
  balanced: 15,
  detailed: 25,
}

/** §7.4 — the only mapping from a semantic note type to a canvas color. */
export const AI_NOTE_TYPE_COLORS: Record<AiNoteType, NoteColor> = {
  idea: 'amber',
  question: 'sky',
  challenge: 'rose',
  opportunity: 'mint',
  action: 'lilac',
}

/**
 * §7.3 — deterministic layout shared by the preview and, from Sprint 2, the
 * apply path. Kept here so the board cannot drift from what the user reviewed.
 */
export const AI_NOTE_LAYOUT = {
  noteWidth: 220,
  noteHeight: 140,
  columnGap: 48,
  rowGap: 24,
  originX: 80,
  originY: 160,
} as const

export function aiNotePosition(sectionIndex: number, noteIndex: number) {
  return {
    x: AI_NOTE_LAYOUT.originX + sectionIndex * (AI_NOTE_LAYOUT.noteWidth + AI_NOTE_LAYOUT.columnGap),
    y: AI_NOTE_LAYOUT.originY + noteIndex * (AI_NOTE_LAYOUT.noteHeight + AI_NOTE_LAYOUT.rowGap),
  }
}

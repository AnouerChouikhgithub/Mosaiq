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

/** The five palette tokens a generated board accepts (contract §7.1). */
export const AI_BOARD_NOTE_COLORS: readonly NoteColor[] = [
  'amber',
  'rose',
  'sky',
  'mint',
  'lilac',
] as const

// Placement lives in `./layout.ts`: the contract §7.3 formula did not fit the
// real 1400×880 canvas, so the fitting algorithm is the single owner of
// coordinates for both the review and the import path.

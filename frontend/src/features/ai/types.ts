/**
 * Wire types for the AI brainstorming endpoint.
 *
 * They mirror `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` (v1.0) exactly.
 * The contract is owned by both sprints: do not add fields here before the
 * document changes. Unknown response fields are ignored by design (§9).
 */

export const AI_SCHEMA_VERSION = '1.0'

export type AiLanguage = 'en' | 'fr' | 'ar'
export type AiDetailLevel = 'concise' | 'balanced' | 'detailed'
export type AiNoteType = 'idea' | 'question' | 'challenge' | 'opportunity' | 'action'
export type AiSectionCategory =
  | 'goals'
  | 'audience'
  | 'ideas'
  | 'risks'
  | 'actions'
  | 'questions'

/** §3 — the request body; `context` is explicitly nullable. */
export interface AiBrainstormingRequest {
  topic: string
  objective: string
  context: string | null
  language: AiLanguage
  detailLevel: AiDetailLevel
  schemaVersion?: typeof AI_SCHEMA_VERSION
}

/** §4.3 — a note is semantic; persistence still uses canvas type `note`. */
export interface AiBrainstormingNote {
  id: string
  type: AiNoteType
  content: string
}

/** §4.2 */
export interface AiBrainstormingSection {
  id: string
  title: string
  category: AiSectionCategory
  notes: AiBrainstormingNote[]
}

/** §4.1 */
export interface AiBrainstormingProposal {
  title: string
  objective: string
  sections: AiBrainstormingSection[]
}

/** §4 — one proposal per successful call; nothing is persisted. */
export interface AiBrainstormingResponse {
  schemaVersion: string
  language: AiLanguage
  detailLevel: AiDetailLevel
  truncated: boolean
  proposal: AiBrainstormingProposal
}

/** §6.4 — the `details` entry of a validation error. */
export interface AiValidationDetail {
  field: string
  message: string
}

/** §6.4–6.8 — error bodies keep `error` first and add a stable `code`. */
export interface AiErrorBody {
  error?: string
  code?: string
  details?: AiValidationDetail[]
  retryAfter?: number
}

/** Values owned by the generation form before they become a request. */
export interface AiBrainstormingFormValues {
  topic: string
  objective: string
  context: string
  language: AiLanguage
  detailLevel: AiDetailLevel
}

import type { TranslationKey } from '@/i18n'
import { AI_BRAINSTORMING_LIMITS } from './limits'
import type { AiBrainstormingFormValues } from './types'
import type { ProposalDraft } from './proposalDraft'

/** A validation message kept as a translation key plus its numbers. */
export interface AiValidationMessage {
  key: TranslationKey
  params?: Record<string, number>
}

export type AiFormField = 'topic' | 'objective' | 'context'
export type AiFormErrors = Partial<Record<AiFormField, AiValidationMessage>>

/**
 * Limits are counted in Unicode characters (code points), matching the server's
 * normalisation. Grapheme clusters are not merged, exactly like the backend.
 */
export function countCharacters(value: string): number {
  return Array.from(value).length
}

/** Control characters become spaces, like the server's normalisation. */
function stripControlCharacters(value: string): string {
  let output = ''
  for (const character of value) {
    const code = character.codePointAt(0) ?? 0
    output += code < 32 || code === 127 ? ' ' : character
  }
  return output
}

/** Trim, drop control characters and collapse whitespace runs to one space. */
export function normalizePromptText(value: string): string {
  return stripControlCharacters(value).replace(/\s+/gu, ' ').trim()
}

export function validateAiForm(values: AiBrainstormingFormValues): AiFormErrors {
  const errors: AiFormErrors = {}
  const limits = AI_BRAINSTORMING_LIMITS
  const topic = normalizePromptText(values.topic)
  const objective = normalizePromptText(values.objective)
  const context = normalizePromptText(values.context)

  if (!topic) {
    errors.topic = { key: 'ai.form.required' }
  } else if (countCharacters(topic) < limits.topicMin) {
    errors.topic = { key: 'ai.form.topicTooShort', params: { min: limits.topicMin } }
  } else if (countCharacters(topic) > limits.topicMax) {
    errors.topic = { key: 'ai.form.topicTooLong', params: { max: limits.topicMax } }
  }

  if (!objective) {
    errors.objective = { key: 'ai.form.required' }
  } else if (countCharacters(objective) < limits.objectiveMin) {
    errors.objective = {
      key: 'ai.form.objectiveTooShort',
      params: { min: limits.objectiveMin },
    }
  } else if (countCharacters(objective) > limits.objectiveMax) {
    errors.objective = {
      key: 'ai.form.objectiveTooLong',
      params: { max: limits.objectiveMax },
    }
  }

  if (countCharacters(context) > limits.contextMax) {
    errors.context = {
      key: 'ai.form.contextTooLong',
      params: { max: limits.contextMax },
    }
  }

  return errors
}

/** One preview problem, tied to the offending draft item. */
export interface AiDraftIssue {
  id: string
  key: TranslationKey
  params?: Record<string, number>
}

/**
 * Preview validation mirrors the contract's output limits. It is advisory: the
 * preview never persists anything, but the user should see what Sprint 2's
 * apply path would reject.
 */
export function validateProposalDraft(draft: ProposalDraft): AiDraftIssue[] {
  const limits = AI_BRAINSTORMING_LIMITS
  const issues: AiDraftIssue[] = []
  const titleLength = countCharacters(normalizePromptText(draft.title))
  const objectiveLength = countCharacters(normalizePromptText(draft.objective))
  const totalNotes = draft.sections.reduce(
    (count, section) => count + section.notes.length,
    0,
  )

  if (titleLength < limits.proposalTitleMin || titleLength > limits.proposalTitleMax) {
    issues.push({
      id: 'proposal-title',
      key: 'ai.limit.titleLength',
      params: { min: limits.proposalTitleMin, max: limits.proposalTitleMax },
    })
  }

  if (
    objectiveLength < limits.proposalObjectiveMin ||
    objectiveLength > limits.proposalObjectiveMax
  ) {
    issues.push({
      id: 'proposal-objective',
      key: 'ai.limit.objectiveLength',
      params: { min: limits.proposalObjectiveMin, max: limits.proposalObjectiveMax },
    })
  }

  if (
    draft.sections.length < limits.sectionsMin ||
    draft.sections.length > limits.sectionsMax
  ) {
    issues.push({
      id: 'section-count',
      key: 'ai.limit.sectionCount',
      params: { min: limits.sectionsMin, max: limits.sectionsMax },
    })
  }

  if (totalNotes > limits.totalNotesMax) {
    issues.push({
      id: 'note-total',
      key: 'ai.limit.totalNotes',
      params: { max: limits.totalNotesMax },
    })
  } else if (totalNotes < limits.totalNotesMin) {
    issues.push({
      id: 'note-total',
      key: 'ai.limit.fewNotes',
      params: { min: limits.totalNotesMin },
    })
  }

  for (const section of draft.sections) {
    const sectionTitleLength = countCharacters(normalizePromptText(section.title))
    if (
      sectionTitleLength < limits.sectionTitleMin ||
      sectionTitleLength > limits.sectionTitleMax
    ) {
      issues.push({
        id: `${section.id}-title`,
        key: 'ai.limit.sectionTitleLength',
        params: { min: limits.sectionTitleMin, max: limits.sectionTitleMax },
      })
    }

    if (section.notes.length > limits.notesPerSectionMax) {
      issues.push({
        id: `${section.id}-note-count`,
        key: 'ai.limit.sectionNotes',
        params: { max: limits.notesPerSectionMax },
      })
    }

    for (const note of section.notes) {
      const length = countCharacters(normalizePromptText(note.content))
      if (length < limits.noteContentMin || length > limits.noteContentMax) {
        issues.push({
          id: `${note.id}-content`,
          key: 'ai.limit.noteContentLength',
          params: { min: limits.noteContentMin, max: limits.noteContentMax },
        })
      }
    }
  }

  return issues
}

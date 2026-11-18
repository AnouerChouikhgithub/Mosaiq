import { ArrowDown, ArrowUp, Plus, Sparkles, Trash2 } from 'lucide-react'
import { useMemo, useReducer } from 'react'
import { noteClasses } from '@/components/board/colors'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input, NativeSelect, Textarea } from '@/components/ui/input'
import { useI18n, type TranslationKey } from '@/i18n'
import { cn } from '@/lib/utils'
import { readAiBrainstormingFlags } from './config'
import {
  AI_BRAINSTORMING_LIMITS,
  AI_NOTE_TYPES,
  AI_NOTE_TYPE_COLORS,
} from './limits'
import {
  draftFromResponse,
  proposalDraftReducer,
  totalDraftNotes,
} from './proposalDraft'
import { validateProposalDraft } from './validation'
import type { AiBrainstormingResponse, AiNoteType, AiSectionCategory } from './types'

const noteTypeLabelKeys: Record<AiNoteType, TranslationKey> = {
  idea: 'ai.noteType.idea',
  question: 'ai.noteType.question',
  challenge: 'ai.noteType.challenge',
  opportunity: 'ai.noteType.opportunity',
  action: 'ai.noteType.action',
}

const categoryLabelKeys: Record<AiSectionCategory, TranslationKey> = {
  goals: 'ai.category.goals',
  audience: 'ai.category.audience',
  ideas: 'ai.category.ideas',
  risks: 'ai.category.risks',
  actions: 'ai.category.actions',
  questions: 'ai.category.questions',
}

export interface BrainstormingPreviewProps {
  response: AiBrainstormingResponse
  onEditRequest: () => void
  onDiscard: () => void
}

/**
 * The proposal preview editor.
 *
 * It is deliberately not the board editor: the draft is local state, there is no
 * canvas, no timeline and no save call. Sprint 2 adds the apply path on top of
 * this component; until then "discard" is the only outcome, which is exactly
 * what contract §8.5 requires.
 */
export function BrainstormingPreview({
  response,
  onEditRequest,
  onDiscard,
}: BrainstormingPreviewProps) {
  const { t } = useI18n()
  const limits = AI_BRAINSTORMING_LIMITS
  const [draft, dispatch] = useReducer(
    proposalDraftReducer,
    response,
    draftFromResponse,
  )
  const issues = useMemo(() => validateProposalDraft(draft), [draft])
  const totalNotes = totalDraftNotes(draft)
  const useMock = readAiBrainstormingFlags().useMock

  return (
    <section aria-labelledby="ai-preview-heading" className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-2">
        <h3 id="ai-preview-heading" className="text-title">
          {t('ai.preview.title')}
        </h3>
        <Badge variant="primary">{t('ai.preview.localBadge')}</Badge>
        {useMock ? <Badge variant="outline">{t('ai.preview.mockBadge')}</Badge> : null}
        <span className="ms-auto text-caption text-muted-foreground">
          {t('ai.preview.summary', {
            sections: draft.sections.length,
            notes: totalNotes,
          })}
        </span>
      </div>
      <p className="text-caption text-muted-foreground">
        {t('ai.preview.description')}
      </p>

      {response.truncated ? (
        <p
          role="status"
          className="flex items-start gap-2 rounded-card border border-warning/40 bg-warning-soft px-3 py-2 text-caption text-warning-text"
        >
          <Sparkles className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          {t('ai.preview.truncated')}
        </p>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2 sm:col-span-2">
          <label
            htmlFor="ai-proposal-title"
            className="text-caption font-medium text-foreground"
          >
            {t('ai.preview.boardTitle')}
          </label>
          <Input
            id="ai-proposal-title"
            value={draft.title}
            maxLength={limits.proposalTitleMax}
            onChange={(event) => dispatch({ type: 'title', value: event.target.value })}
            className="text-base font-semibold"
          />
        </div>
        <div className="flex flex-col gap-2 sm:col-span-2">
          <label
            htmlFor="ai-proposal-objective"
            className="text-caption font-medium text-foreground"
          >
            {t('ai.preview.objective')}
          </label>
          <Textarea
            id="ai-proposal-objective"
            value={draft.objective}
            rows={2}
            maxLength={limits.proposalObjectiveMax}
            onChange={(event) =>
              dispatch({ type: 'objective', value: event.target.value })
            }
          />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <h4 className="text-sm font-semibold">{t('ai.preview.sections')}</h4>
        <span className="text-caption text-muted-foreground">
          {t('ai.preview.sectionsCount', {
            count: draft.sections.length,
            max: limits.sectionsMax,
          })}
        </span>
        <span className="text-caption text-muted-foreground">
          {t('ai.preview.totalNotes', {
            count: totalNotes,
            max: limits.totalNotesMax,
          })}
        </span>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          className="ms-auto"
          onClick={() => dispatch({ type: 'addSection' })}
          disabled={draft.sections.length >= limits.sectionsMax}
          title={
            draft.sections.length >= limits.sectionsMax
              ? t('ai.limit.maxReached')
              : undefined
          }
        >
          <Plus className="size-4" aria-hidden="true" />
          {t('ai.preview.addSection')}
        </Button>
      </div>

      <div className="grid items-start gap-4 lg:grid-cols-2">
        {draft.sections.map((section, sectionIndex) => {
          const sectionNotes = section.notes.length
          const sectionFull = sectionNotes >= limits.notesPerSectionMax
          const totalFull = totalNotes >= limits.totalNotesMax

          return (
            <article
              key={section.id}
              aria-labelledby={`ai-section-${section.id}-title`}
              className="flex min-w-0 flex-col gap-3 rounded-card border border-border bg-surface p-4"
            >
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="neutral">{sectionIndex + 1}</Badge>
                <Badge variant="outline">
                  {t(categoryLabelKeys[section.category])}
                </Badge>
                <div className="ms-auto flex items-center gap-1">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`${t('ai.preview.moveUp')} — ${section.title || section.id}`}
                    disabled={sectionIndex === 0}
                    onClick={() =>
                      dispatch({
                        type: 'moveSection',
                        sectionId: section.id,
                        direction: 'up',
                      })
                    }
                  >
                    <ArrowUp className="size-4" aria-hidden="true" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`${t('ai.preview.moveDown')} — ${section.title || section.id}`}
                    disabled={sectionIndex === draft.sections.length - 1}
                    onClick={() =>
                      dispatch({
                        type: 'moveSection',
                        sectionId: section.id,
                        direction: 'down',
                      })
                    }
                  >
                    <ArrowDown className="size-4" aria-hidden="true" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('ai.preview.removeSectionAria', {
                      title: section.title || section.id,
                    })}
                    disabled={draft.sections.length <= limits.sectionsMin}
                    onClick={() =>
                      dispatch({ type: 'removeSection', sectionId: section.id })
                    }
                  >
                    <Trash2 className="size-4" aria-hidden="true" />
                  </Button>
                </div>
              </div>

              <div className="flex flex-col gap-2">
                <label
                  htmlFor={`ai-section-${section.id}-title`}
                  className="text-caption font-medium text-foreground"
                >
                  {t('ai.preview.sectionTitle')}
                </label>
                <Input
                  id={`ai-section-${section.id}-title`}
                  value={section.title}
                  maxLength={limits.sectionTitleMax}
                  onChange={(event) =>
                    dispatch({
                      type: 'sectionTitle',
                      sectionId: section.id,
                      value: event.target.value,
                    })
                  }
                />
              </div>

              <ul className="flex flex-col gap-3">
                {section.notes.map((note, noteIndex) => (
                  <li
                    key={note.id}
                    className="flex flex-col gap-2 rounded-xl border border-border/70 bg-muted/40 p-3"
                  >
                    <div className="flex items-center gap-2">
                      <span
                        aria-hidden="true"
                        className={cn(
                          'size-3 shrink-0 rounded-[3px] border border-border/60',
                          noteClasses[AI_NOTE_TYPE_COLORS[note.type]].surface,
                        )}
                      />
                      <label
                        htmlFor={`ai-note-${note.id}-type`}
                        className="sr-only"
                      >
                        {t('ai.preview.noteType')}
                      </label>
                      <NativeSelect
                        id={`ai-note-${note.id}-type`}
                        value={note.type}
                        wrapperClassName="min-w-0 flex-1"
                        className="h-9 text-caption"
                        onChange={(event) =>
                          dispatch({
                            type: 'noteType',
                            sectionId: section.id,
                            noteId: note.id,
                            value: event.target.value as AiNoteType,
                          })
                        }
                      >
                        {AI_NOTE_TYPES.map((type) => (
                          <option key={type} value={type}>
                            {t(noteTypeLabelKeys[type])}
                          </option>
                        ))}
                      </NativeSelect>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('ai.preview.removeNoteAria', {
                          index: noteIndex + 1,
                        })}
                        disabled={sectionNotes <= limits.notesPerSectionMin}
                        onClick={() =>
                          dispatch({
                            type: 'removeNote',
                            sectionId: section.id,
                            noteId: note.id,
                          })
                        }
                      >
                        <Trash2 className="size-4" aria-hidden="true" />
                      </Button>
                    </div>
                    <label htmlFor={`ai-note-${note.id}-content`} className="sr-only">
                      {t('ai.preview.noteContent')}
                    </label>
                    <Textarea
                      id={`ai-note-${note.id}-content`}
                      value={note.content}
                      rows={2}
                      maxLength={limits.noteContentMax}
                      className="min-h-16 bg-surface text-caption"
                      onChange={(event) =>
                        dispatch({
                          type: 'noteContent',
                          sectionId: section.id,
                          noteId: note.id,
                          value: event.target.value,
                        })
                      }
                    />
                  </li>
                ))}
              </ul>

              {sectionNotes === 0 ? (
                <p className="text-caption text-muted-foreground">
                  {t('ai.preview.emptySection')}
                </p>
              ) : null}

              <div className="flex flex-wrap items-center gap-2">
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => dispatch({ type: 'addNote', sectionId: section.id })}
                  disabled={sectionFull || totalFull}
                  title={
                    sectionFull || totalFull ? t('ai.limit.maxReached') : undefined
                  }
                >
                  <Plus className="size-4" aria-hidden="true" />
                  {t('ai.preview.addNote')}
                </Button>
                <span className="text-caption text-muted-foreground">
                  {t('ai.preview.notesCount', {
                    count: sectionNotes,
                    max: limits.notesPerSectionMax,
                  })}
                </span>
              </div>
            </article>
          )
        })}
      </div>

      {issues.length > 0 ? (
        <div
          role="status"
          className="rounded-card border border-warning/40 bg-warning-soft px-3 py-2 text-caption text-warning-text"
        >
          <p className="font-semibold">{t('ai.preview.limitsTitle')}</p>
          <p>{t('ai.preview.limitsBody')}</p>
          <ul className="mt-1 list-disc space-y-0.5 ps-5">
            {issues.slice(0, 5).map((issue) => (
              <li key={issue.id}>{t(issue.key, issue.params)}</li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="flex flex-col-reverse gap-2 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-caption text-muted-foreground">
          {t('ai.preview.footerHint')}
        </p>
        <div className="flex flex-col-reverse gap-2 sm:flex-row">
          <Button type="button" variant="secondary" onClick={onEditRequest}>
            {t('ai.preview.editRequest')}
          </Button>
          <Button type="button" variant="primary" onClick={onDiscard}>
            {t('ai.preview.discard')}
          </Button>
        </div>
      </div>
    </section>
  )
}

import { AlertTriangle, Loader2, ShieldCheck, Sparkles } from 'lucide-react'
import { useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Field, Input, NativeSelect, Textarea } from '@/components/ui/input'
import { useToast } from '@/components/ui/toast-context'
import { getSessionUser, getSessionWorkspace } from '@/api/client'
import { useI18n, type TranslationKey } from '@/i18n'
import { cn } from '@/lib/utils'
import {
  AI_CONSENT_STORAGE_KEY,
  hasAcceptedAiNotice,
  rememberAiNoticeAcceptance,
} from './config'
import { BrainstormingPreview } from './BrainstormingPreview'
import type { AiErrorKind } from './errors'
import {
  AI_BRAINSTORMING_LIMITS,
  AI_DETAIL_LEVELS,
  AI_LANGUAGES,
} from './limits'
import { useBrainstormingGeneration } from './useBrainstormingGeneration'
import { countCharacters, validateAiForm, type AiFormErrors } from './validation'
import {
  importProposal,
  ProposalImportError,
  type ProposalImportErrorKind,
} from './importProposal'
import { totalDraftNotes, type ProposalDraft } from './proposalDraft'
import { toWireRequest } from '@/api/aiBrainstorming'
import type {
  AiBrainstormingFormValues,
  AiDetailLevel,
  AiLanguage,
} from './types'

const languageLabelKeys: Record<AiLanguage, TranslationKey> = {
  en: 'language.en',
  fr: 'language.fr',
  ar: 'language.ar',
}

const detailLabelKeys: Record<AiDetailLevel, TranslationKey> = {
  concise: 'ai.form.detail.concise',
  balanced: 'ai.form.detail.balanced',
  detailed: 'ai.form.detail.detailed',
}

const detailBudgetKeys: Record<AiDetailLevel, TranslationKey> = {
  concise: 'ai.form.budget.concise',
  balanced: 'ai.form.budget.balanced',
  detailed: 'ai.form.budget.detailed',
}

const errorCopy: Record<AiErrorKind, { title: TranslationKey; body: TranslationKey }> = {
  invalid: { title: 'ai.error.invalidTitle', body: 'ai.error.invalidBody' },
  unsupported_version: {
    title: 'ai.error.invalidTitle',
    body: 'ai.error.invalidBody',
  },
  workspace_inactive: {
    title: 'ai.error.workspaceTitle',
    body: 'ai.error.workspaceBody',
  },
  rate_limited: { title: 'ai.error.rateTitle', body: 'ai.error.rateBody' },
  unavailable: {
    title: 'ai.error.unavailableTitle',
    body: 'ai.error.unavailableBody',
  },
  provider_failed: {
    title: 'ai.error.providerTitle',
    body: 'ai.error.providerBody',
  },
  timeout: { title: 'ai.error.timeoutTitle', body: 'ai.error.timeoutBody' },
  network: { title: 'ai.error.networkTitle', body: 'ai.error.networkBody' },
  unauthorized: {
    title: 'ai.error.sessionTitle',
    body: 'ai.error.sessionBody',
  },
  internal: { title: 'ai.error.internalTitle', body: 'ai.error.internalBody' },
  cancelled: {
    title: 'ai.state.cancelledTitle',
    body: 'ai.state.cancelledBody',
  },
}

const importErrorBodies: Record<ProposalImportErrorKind, TranslationKey> = {
  invalid: 'ai.import.invalidBody',
  forbidden: 'ai.import.forbiddenBody',
  unauthorized: 'ai.import.unauthorizedBody',
  network: 'ai.import.networkBody',
  failed: 'ai.import.failedBody',
}

/** Only workspace administrators may create the board (contract §7.1). */
function canCreateBoards(): boolean {
  const role = getSessionUser()?.role
  return role === 'Owner' || role === 'Admin'
}

interface ImportState {
  status: 'idle' | 'importing' | 'error'
  completed: number
  total: number
  error: ProposalImportError | null
}

const idleImport: ImportState = {
  status: 'idle',
  completed: 0,
  total: 0,
  error: null,
}

function createInitialValues(locale: AiLanguage): AiBrainstormingFormValues {
  return {
    topic: '',
    objective: '',
    context: '',
    language: locale,
    detailLevel: 'balanced',
  }
}

/** Shared shell of the transient status panels. */
function StatusPanel({
  tone,
  icon,
  title,
  body,
  children,
  role,
}: {
  tone: 'info' | 'danger' | 'warning'
  icon: ReactNode
  title: string
  body: string
  children?: ReactNode
  role: 'status' | 'alert'
}) {
  const tones = {
    info: 'border-border bg-muted/50 text-foreground',
    danger: 'border-danger/40 bg-danger-soft text-danger-text',
    warning: 'border-warning/40 bg-warning-soft text-warning-text',
  } as const

  return (
    <div
      role={role}
      aria-live={role === 'alert' ? 'assertive' : 'polite'}
      className={cn('rounded-card border px-3 py-3 text-caption', tones[tone])}
    >
      <p className="flex items-center gap-2 text-sm font-semibold">
        {icon}
        {title}
      </p>
      <p className="mt-1">{body}</p>
      {children ? <div className="mt-3 flex flex-wrap gap-2">{children}</div> : null}
    </div>
  )
}

export interface BrainstormingDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * The AI brainstorming entry, kept in a dialog so the dashboard is untouched.
 *
 * Flow: form → generating → preview (local edits only) or a translated error.
 * The dialog owns no persistence: it never creates a board or an element, and
 * the mocked transport is marked in the preview until Sprint 2 lands.
 */
export function BrainstormingDialog({ open, onOpenChange }: BrainstormingDialogProps) {
  const { t, locale } = useI18n()
  const { toast } = useToast()
  const navigate = useNavigate()
  const limits = AI_BRAINSTORMING_LIMITS
  const topicRef = useRef<HTMLInputElement>(null)
  const [values, setValues] = useState<AiBrainstormingFormValues>(() =>
    createInitialValues(locale),
  )
  const [errors, setErrors] = useState<AiFormErrors>({})
  const [consent, setConsent] = useState(hasAcceptedAiNotice)
  const [consentError, setConsentError] = useState(false)
  const [importState, setImportState] = useState<ImportState>(idleImport)
  // A ref, not state: two clicks in the same tick must not both start an import.
  const importingRef = useRef(false)
  const { state, generate, cancel, retry, reset } = useBrainstormingGeneration()

  const generating = state.status === 'generating'
  const showPreview = state.status === 'success' && state.response !== null
  const importing = importState.status === 'importing'

  // The dashboard mounts this dialog per opening, so the initial values always
  // start from the current interface language (contract §3: no implicit
  // default, the client sends its locale).

  function close() {
    // Closing mid-import would hide a board that is being written.
    if (importingRef.current) return
    reset()
    setErrors({})
    setConsentError(false)
    setImportState(idleImport)
    onOpenChange(false)
  }

  /**
   * Approval: the only place that writes anything (§8.5). The draft is
   * re-validated inside the import path; success is reported only after every
   * note has been persisted, and the user lands on the real board.
   */
  async function approve(draft: ProposalDraft) {
    if (importingRef.current) return
    importingRef.current = true
    setImportState({ status: 'importing', completed: 0, total: totalDraftNotes(draft), error: null })

    try {
      const result = await importProposal(draft, {
        workspaceId: getSessionWorkspace()?.id,
        onProgress: ({ completed, total }) =>
          setImportState({ status: 'importing', completed, total, error: null }),
      })
      toast({
        title: t('ai.import.success', { title: draft.title.trim() }),
        description: t('ai.import.successBody', { count: result.noteCount }),
      })
      importingRef.current = false
      onOpenChange(false)
      navigate(`/board/${result.boardId}`)
    } catch (error) {
      const importError =
        error instanceof ProposalImportError
          ? error
          : new ProposalImportError('failed', 'The import failed.', { cause: error })
      setImportState({ status: 'error', completed: 0, total: 0, error: importError })
      importingRef.current = false
    }
  }

  function updateField(field: 'topic' | 'objective' | 'context', value: string) {
    setValues((current) => ({ ...current, [field]: value }))
    if (errors[field]) {
      setErrors((current) => ({ ...current, [field]: undefined }))
    }
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (generating) return

    const nextErrors = validateAiForm(values)
    setErrors(nextErrors)

    if (!consent) {
      setConsentError(true)
      return
    }
    setConsentError(false)

    if (Object.keys(nextErrors).length > 0) return
    void generate(toWireRequest(values))
  }

  const description = showPreview
    ? t('ai.preview.description')
    : t('ai.dialog.description')

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (next) onOpenChange(true)
        else close()
      }}
    >
      <DialogContent
        className="max-h-[92svh] w-[calc(100vw-1.5rem)] max-w-3xl overflow-y-auto p-0"
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          topicRef.current?.focus()
        }}
      >
        <DialogHeader className="pe-14">
          <DialogTitle>{t('ai.dialog.title')}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        {showPreview && state.response ? (
          <div className="flex flex-col gap-4 px-4 pb-6 sm:px-6">
            <BrainstormingPreview
              response={state.response}
              canCreateBoard={canCreateBoards()}
              importing={
                importing
                  ? { completed: importState.completed, total: importState.total }
                  : null
              }
              onApprove={(draft) => void approve(draft)}
              onEditRequest={reset}
              onDiscard={close}
            />
            {importState.status === 'error' && importState.error ? (
              <StatusPanel
                role="alert"
                tone="danger"
                icon={<AlertTriangle className="size-4" aria-hidden="true" />}
                title={t('ai.import.errorTitle')}
                body={t(importErrorBodies[importState.error.kind])}
              />
            ) : null}
          </div>
        ) : (
          <form
            className="flex min-w-0 flex-col gap-5 px-4 pb-6 sm:px-6"
            onSubmit={handleSubmit}
            noValidate
          >
            <fieldset
              disabled={generating}
              className="m-0 flex min-w-0 flex-col gap-5 border-0 p-0"
            >
              <Field
                label={t('ai.form.topic')}
                htmlFor="ai-topic"
                hint={t('ai.form.topicHint')}
                counter={t('ai.form.counter', {
                  count: countCharacters(values.topic),
                  max: limits.topicMax,
                })}
                error={errors.topic ? t(errors.topic.key, errors.topic.params) : undefined}
              >
                <Input
                  id="ai-topic"
                  name="topic"
                  ref={topicRef}
                  value={values.topic}
                  maxLength={limits.topicMax}
                  autoComplete="off"
                  placeholder={t('ai.form.topicPlaceholder')}
                  aria-invalid={errors.topic ? true : undefined}
                  aria-describedby="ai-topic-message ai-topic-counter"
                  onChange={(event) => updateField('topic', event.target.value)}
                />
              </Field>

              <Field
                label={t('ai.form.objective')}
                htmlFor="ai-objective"
                hint={t('ai.form.objectiveHint')}
                counter={t('ai.form.counter', {
                  count: countCharacters(values.objective),
                  max: limits.objectiveMax,
                })}
                error={
                  errors.objective
                    ? t(errors.objective.key, errors.objective.params)
                    : undefined
                }
              >
                <Input
                  id="ai-objective"
                  name="objective"
                  value={values.objective}
                  maxLength={limits.objectiveMax}
                  autoComplete="off"
                  placeholder={t('ai.form.objectivePlaceholder')}
                  aria-invalid={errors.objective ? true : undefined}
                  aria-describedby="ai-objective-message ai-objective-counter"
                  onChange={(event) => updateField('objective', event.target.value)}
                />
              </Field>

              <Field
                label={t('ai.form.context')}
                htmlFor="ai-context"
                hint={t('ai.form.contextHint')}
                counter={t('ai.form.counter', {
                  count: countCharacters(values.context),
                  max: limits.contextMax,
                })}
                error={
                  errors.context
                    ? t(errors.context.key, errors.context.params)
                    : undefined
                }
              >
                <Textarea
                  id="ai-context"
                  name="context"
                  value={values.context}
                  maxLength={limits.contextMax}
                  rows={3}
                  placeholder={t('ai.form.contextPlaceholder')}
                  aria-invalid={errors.context ? true : undefined}
                  aria-describedby="ai-context-message ai-context-counter"
                  onChange={(event) => updateField('context', event.target.value)}
                />
              </Field>

              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('ai.form.language')} htmlFor="ai-language">
                  <NativeSelect
                    id="ai-language"
                    name="language"
                    value={values.language}
                    onChange={(event) =>
                      setValues((current) => ({
                        ...current,
                        language: event.target.value as AiLanguage,
                      }))
                    }
                  >
                    {AI_LANGUAGES.map((language) => (
                      <option key={language} value={language}>
                        {t(languageLabelKeys[language])}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>

                <Field
                  label={t('ai.form.detail')}
                  htmlFor="ai-detail"
                  hint={t(detailBudgetKeys[values.detailLevel])}
                >
                  <NativeSelect
                    id="ai-detail"
                    name="detailLevel"
                    value={values.detailLevel}
                    onChange={(event) =>
                      setValues((current) => ({
                        ...current,
                        detailLevel: event.target.value as AiDetailLevel,
                      }))
                    }
                  >
                    {AI_DETAIL_LEVELS.map((level) => (
                      <option key={level} value={level}>
                        {t(detailLabelKeys[level])}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
              </div>

              <div className="rounded-card border border-border bg-muted/60 p-3">
                <div className="flex items-start gap-2">
                  <ShieldCheck
                    className="mt-0.5 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                  />
                  <div className="min-w-0">
                    <p className="text-sm font-semibold">{t('ai.form.privacyTitle')}</p>
                    <p className="mt-1 text-caption text-muted-foreground">
                      {t('ai.form.privacyBody')}
                    </p>
                    <p className="mt-1 text-caption text-muted-foreground">
                      {t('ai.form.privacySession')}
                    </p>
                  </div>
                </div>
                <label className="mt-3 flex items-center gap-2 text-caption font-medium text-foreground">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-border accent-primary"
                    checked={consent}
                    aria-invalid={consentError ? true : undefined}
                    aria-describedby={consentError ? 'ai-consent-error' : undefined}
                    onChange={(event) => {
                      const accepted = event.target.checked
                      setConsent(accepted)
                      setConsentError(false)
                      if (accepted) {
                        rememberAiNoticeAcceptance()
                      } else {
                        window.sessionStorage.removeItem(AI_CONSENT_STORAGE_KEY)
                      }
                    }}
                  />
                  {t('ai.form.privacyAccept')}
                </label>
                {consentError ? (
                  <p
                    id="ai-consent-error"
                    role="alert"
                    className="mt-2 text-caption text-danger-text"
                  >
                    {t('ai.form.privacyRequired')}
                  </p>
                ) : null}
              </div>
            </fieldset>

            {generating ? (
              <StatusPanel
                role="status"
                tone="info"
                icon={
                  <Loader2 className="size-4 animate-spin text-primary" aria-hidden="true" />
                }
                title={t('ai.state.generating')}
                body={t('ai.state.generatingBody')}
              >
                <Button type="button" variant="secondary" size="sm" onClick={cancel}>
                  {t('ai.state.cancel')}
                </Button>
              </StatusPanel>
            ) : null}

            {state.status === 'cancelled' ? (
              <StatusPanel
                role="status"
                tone="warning"
                icon={<AlertTriangle className="size-4" aria-hidden="true" />}
                title={t('ai.state.cancelledTitle')}
                body={t('ai.state.cancelledBody')}
              >
                <Button type="button" variant="secondary" size="sm" onClick={retry}>
                  {t('ai.state.retry')}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={reset}>
                  {t('ai.state.back')}
                </Button>
              </StatusPanel>
            ) : null}

            {state.status === 'error' && state.error ? (
              <StatusPanel
                role="alert"
                tone="danger"
                icon={<AlertTriangle className="size-4" aria-hidden="true" />}
                title={t(errorCopy[state.error.kind].title)}
                body={
                  state.error.kind === 'rate_limited'
                    ? state.error.retryAfter
                      ? t('ai.error.rateBody', { seconds: state.error.retryAfter })
                      : t('ai.error.rateBodyFallback')
                    : t(errorCopy[state.error.kind].body)
                }
              >
                <Button type="button" variant="secondary" size="sm" onClick={retry}>
                  {t('ai.state.retry')}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={reset}>
                  {t('ai.state.back')}
                </Button>
                {state.error.details && state.error.details.length > 0 ? (
                  <ul className="w-full list-disc space-y-0.5 ps-5">
                    {state.error.details.map((detail) => (
                      <li key={`${detail.field}-${detail.message}`}>
                        {detail.field}: {detail.message}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </StatusPanel>
            ) : null}

            <div className="flex flex-col-reverse gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
              <Button type="button" variant="secondary" onClick={close}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" loading={generating}>
                <Sparkles className="size-4" aria-hidden="true" />
                {t('ai.form.submit')}
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}

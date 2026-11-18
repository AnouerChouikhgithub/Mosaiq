import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  generate: vi.fn(),
  getBoards: vi.fn(async () => []),
}))

vi.mock('@/api/aiBrainstorming', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/aiBrainstorming')>()
  return { ...actual, generateBrainstormingProposal: api.generate }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, getBoards: api.getBoards }
})

import { BrainstormingDialog } from '@/features/ai/BrainstormingDialog'
import { AiGenerationError } from '@/features/ai/errors'
import { toWireRequest } from '@/api/aiBrainstorming'
import { DashboardScreen } from '@/screens/DashboardScreen'
import { I18nProvider } from '@/i18n'
import { ThemeProvider } from '@/theme/ThemeProvider'
import { ToastProvider } from '@/components/ui/toast'
import type {
  AiBrainstormingRequest,
  AiBrainstormingResponse,
  AiLanguage,
  AiNoteType,
  AiSectionCategory,
} from '@/features/ai/types'

/** Deterministic contract-shaped response: 4 sections × 3 notes. */
const fixtureSections: Record<
  AiLanguage,
  { title: string; category: AiSectionCategory }[]
> = {
  en: [
    { title: 'Session goals', category: 'goals' },
    { title: 'Who is involved', category: 'audience' },
    { title: 'Options to explore', category: 'ideas' },
    { title: 'Risks and blockers', category: 'risks' },
  ],
  fr: [
    { title: 'Objectifs de la session', category: 'goals' },
    { title: 'Publics concernés', category: 'audience' },
    { title: 'Pistes à explorer', category: 'ideas' },
    { title: 'Risques et freins', category: 'risks' },
  ],
  ar: [
    { title: 'أهداف الجلسة', category: 'goals' },
    { title: 'الجهات المعنية', category: 'audience' },
    { title: 'مسارات للاستكشاف', category: 'ideas' },
    { title: 'المخاطر والعوائق', category: 'risks' },
  ],
}

const fixtureFirstNote: Record<AiLanguage, (topic: string) => string> = {
  en: (topic) => `State ${topic} in one shared sentence`,
  fr: (topic) => `Clarifier ${topic} en une phrase partagée`,
  ar: (topic) => `توضيح ${topic} في جملة مشتركة`,
}

const fixtureNoteTypes: AiNoteType[] = ['idea', 'question', 'challenge']

function fixture(
  overrides: Partial<AiBrainstormingRequest> = {},
): AiBrainstormingResponse {
  const request = toWireRequest({
    topic: 'Shared bikes in Tunis',
    objective: 'Pick three pilot districts',
    context: '',
    language: 'en',
    detailLevel: 'balanced',
    ...overrides,
  })

  return {
    schemaVersion: '1.0',
    language: request.language,
    detailLevel: request.detailLevel,
    truncated: false,
    proposal: {
      title: request.topic,
      objective: request.objective,
      sections: fixtureSections[request.language].map((section, sectionIndex) => ({
        id: `s${sectionIndex + 1}`,
        title: section.title,
        category: section.category,
        notes: fixtureNoteTypes.map((type, noteIndex) => ({
          id: `s${sectionIndex + 1}-n${noteIndex + 1}`,
          type,
          content:
            sectionIndex === 0 && noteIndex === 0
              ? fixtureFirstNote[request.language](request.topic)
              : `${section.title} note ${noteIndex + 1}`,
        })),
      })),
    },
  }
}

function renderDialog() {
  const onOpenChange = vi.fn()
  const view = render(
    <MemoryRouter>
      <I18nProvider>
        <ToastProvider>
          <BrainstormingDialog open onOpenChange={onOpenChange} />
        </ToastProvider>
      </I18nProvider>
    </MemoryRouter>,
  )
  return { ...view, onOpenChange }
}

function renderDashboard() {
  return render(
    <MemoryRouter>
      <I18nProvider>
        <ThemeProvider>
          <ToastProvider>
            <DashboardScreen onOpenPalette={() => undefined} />
          </ToastProvider>
        </ThemeProvider>
      </I18nProvider>
    </MemoryRouter>,
  )
}

function fillForm(
  values: { topic?: string; objective?: string; context?: string } = {},
  labels = { topic: 'Topic', objective: 'Objective', context: 'Context (optional)' },
) {
  fireEvent.change(screen.getByLabelText(labels.topic), {
    target: { value: values.topic ?? 'Shared bikes in Tunis' },
  })
  fireEvent.change(screen.getByLabelText(labels.objective), {
    target: { value: values.objective ?? 'Pick three pilot districts' },
  })
  if (values.context) {
    fireEvent.change(screen.getByLabelText(labels.context), {
      target: { value: values.context },
    })
  }
}

function acceptNotice(label = 'I understand') {
  fireEvent.click(screen.getByLabelText(label))
}

function submit(label = 'Generate the proposal') {
  fireEvent.click(screen.getByRole('button', { name: label }))
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

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  window.localStorage.setItem('mosaiq-locale', 'en')
  api.generate.mockReset()
  api.getBoards.mockResolvedValue([])
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  vi.unstubAllEnvs()
  window.sessionStorage.clear()
  window.localStorage.clear()
  document.documentElement.dir = 'ltr'
})

describe('AI brainstorming dialog', () => {
  it('renders the form with the contract limits and defaults', () => {
    renderDialog()

    expect(
      screen.getByRole('heading', { name: 'Generate a brainstorming board' }),
    ).toBeTruthy()

    const topic = screen.getByLabelText('Topic') as HTMLInputElement
    const objective = screen.getByLabelText('Objective') as HTMLInputElement
    const context = screen.getByLabelText('Context (optional)') as HTMLTextAreaElement

    expect(topic.maxLength).toBe(280)
    expect(objective.maxLength).toBe(500)
    expect(context.maxLength).toBe(2000)
    expect(screen.getByText('0/280 characters')).toBeTruthy()
    expect(screen.getByText('0/500 characters')).toBeTruthy()
    expect(screen.getByText('0/2000 characters')).toBeTruthy()
    expect(screen.getByText('3 to 280 characters. A specific topic helps the model.')).toBeTruthy()
    expect((screen.getByLabelText('Content language') as HTMLSelectElement).value).toBe('en')
    expect((screen.getByLabelText('Detail level') as HTMLSelectElement).value).toBe('balanced')
    expect(screen.getByText('Up to 15 notes')).toBeTruthy()
    expect(screen.getByRole('checkbox', { name: 'I understand' })).toBeTruthy()
  })

  it('blocks an empty submission with field errors and no request', () => {
    renderDialog()
    acceptNotice()
    submit()

    expect(screen.getAllByText('This field is required.')).toHaveLength(2)
    expect(api.generate).not.toHaveBeenCalled()
  })

  it('requires the external-provider notice before generating', () => {
    renderDialog()
    fillForm()
    submit()

    expect(screen.getByText('Confirm the notice before generating.')).toBeTruthy()
    expect(api.generate).not.toHaveBeenCalled()
  })

  it('enforces the contract character limits', () => {
    renderDialog()
    acceptNotice()

    fillForm({ topic: 'ab', objective: 'Valid objective' })
    submit()
    expect(screen.getByText('The topic needs at least 3 characters.')).toBeTruthy()
    expect(screen.getByText('2/280 characters')).toBeTruthy()
    expect(api.generate).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Topic'), {
      target: { value: 'a'.repeat(281) },
    })
    submit()
    expect(
      screen.getByText('The topic cannot exceed 280 characters.'),
    ).toBeTruthy()
    expect(api.generate).not.toHaveBeenCalled()
  })

  it('sends the selected language and detail level', async () => {
    api.generate.mockResolvedValue(fixture({ language: 'ar', detailLevel: 'concise' }))
    renderDialog()
    acceptNotice()

    fireEvent.change(screen.getByLabelText('Content language'), {
      target: { value: 'ar' },
    })
    fireEvent.change(screen.getByLabelText('Detail level'), {
      target: { value: 'concise' },
    })
    expect(screen.getByText('Up to 9 notes')).toBeTruthy()
    fillForm()
    submit()

    await waitFor(() => expect(api.generate).toHaveBeenCalledTimes(1))
    expect(api.generate.mock.calls[0][0]).toMatchObject({
      topic: 'Shared bikes in Tunis',
      objective: 'Pick three pilot districts',
      context: null,
      language: 'ar',
      detailLevel: 'concise',
      schemaVersion: '1.0',
    })
  })

  it('shows the generating state, prevents duplicate requests and can cancel', async () => {
    let capturedSignal: AbortSignal | undefined
    api.generate.mockImplementation(
      (_request: AiBrainstormingRequest, options: { signal?: AbortSignal }) =>
        new Promise((_resolve, reject) => {
          capturedSignal = options.signal
          options.signal?.addEventListener('abort', () => {
            reject(new AiGenerationError('cancelled', 'cancelled'))
          })
        }),
    )

    renderDialog()
    fillForm()
    acceptNotice()
    submit()
    submit()

    expect(await screen.findByText('Generating…')).toBeTruthy()
    expect(api.generate).toHaveBeenCalledTimes(1)
    expect(
      (screen.getByRole('button', { name: 'Generate the proposal' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Cancel generation' }))

    expect(capturedSignal?.aborted).toBe(true)
    expect(await screen.findByText('Generation cancelled')).toBeTruthy()
    expect(api.generate).toHaveBeenCalledTimes(1)
  })

  it('renders the editable preview after a successful generation without writing', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
    api.generate.mockResolvedValue(fixture())
    renderDialog()
    fillForm({ context: 'Budget is limited to one quarter' })
    acceptNotice()
    submit()

    expect(
      await screen.findByRole('heading', { name: 'Review the proposal' }),
    ).toBeTruthy()
    expect((screen.getByLabelText('Board title') as HTMLInputElement).value).toBe(
      'Shared bikes in Tunis',
    )
    expect(screen.getByDisplayValue('Session goals')).toBeTruthy()
    expect(screen.getByDisplayValue('Who is involved')).toBeTruthy()
    expect(
      screen.getByDisplayValue('State Shared bikes in Tunis in one shared sentence'),
    ).toBeTruthy()
    expect(screen.getByText('Local preview — not saved')).toBeTruthy()
    // The approval action exists, but rendering the preview writes nothing.
    expect(screen.getByRole('button', { name: 'Create the board' })).toBeTruthy()
    expect(fetchSpy).not.toHaveBeenCalled()
    fetchSpy.mockRestore()
  })

  it('maps provider, network, timeout and rate-limit failures to translated states', async () => {
    const cases: [AiGenerationError, string][] = [
      [new AiGenerationError('provider_failed', 'upstream'), 'The provider failed'],
      [new AiGenerationError('timeout', 'slow'), 'Generation took too long'],
      [new AiGenerationError('network', 'offline'), 'Network unavailable'],
      [new AiGenerationError('unavailable', 'disabled'), 'AI is not configured'],
      [new AiGenerationError('workspace_inactive', 'inactive'), 'Workspace inactive'],
    ]

    for (const [error, title] of cases) {
      api.generate.mockReset()
      api.generate.mockRejectedValue(error)
      const view = renderDialog()
      fillForm()
      acceptNotice()
      submit()

      expect(await screen.findByText(title)).toBeTruthy()
      view.unmount()
      window.sessionStorage.clear()
    }
  })

  it('shows the provider retry delay for a rate limit', async () => {
    api.generate.mockRejectedValue(
      new AiGenerationError('rate_limited', 'slow down', { retryAfter: 420 }),
    )
    renderDialog()
    fillForm()
    acceptNotice()
    submit()

    expect(await screen.findByText('Too many requests')).toBeTruthy()
    expect(screen.getByText('Try again in 420 seconds.')).toBeTruthy()
  })

  it('retries the same request after a failure', async () => {
    api.generate
      .mockRejectedValueOnce(new AiGenerationError('network', 'offline'))
      .mockResolvedValueOnce(fixture())
    renderDialog()
    fillForm()
    acceptNotice()
    submit()

    expect(await screen.findByText('Network unavailable')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(
      await screen.findByRole('heading', { name: 'Review the proposal' }),
    ).toBeTruthy()
    expect(api.generate).toHaveBeenCalledTimes(2)
    expect(api.generate.mock.calls[1][0]).toEqual(api.generate.mock.calls[0][0])
  })
})

describe('AI brainstorming preview', () => {
  async function openPreview() {
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
    api.generate.mockResolvedValue(fixture())
    const view = renderDialog()
    fillForm()
    acceptNotice()
    submit()
    await screen.findByRole('heading', { name: 'Review the proposal' })
    return { view, fetchSpy }
  }

  it('edits the title, objective, section titles and note content locally', async () => {
    const { fetchSpy } = await openPreview()

    fireEvent.change(screen.getByLabelText('Board title'), {
      target: { value: 'Bikes in Greater Tunis' },
    })
    fireEvent.change(screen.getByLabelText('Objective'), {
      target: { value: 'Choose the first two districts' },
    })
    const sectionTitles = screen.getAllByLabelText('Section title')
    fireEvent.change(sectionTitles[0], { target: { value: 'Shared goals' } })
    const noteContents = screen.getAllByLabelText('Note content')
    fireEvent.change(noteContents[0], {
      target: { value: 'Agree on one measurable outcome' },
    })

    expect(screen.getByDisplayValue('Bikes in Greater Tunis')).toBeTruthy()
    expect(screen.getByDisplayValue('Choose the first two districts')).toBeTruthy()
    expect(screen.getByDisplayValue('Shared goals')).toBeTruthy()
    expect(screen.getByDisplayValue('Agree on one measurable outcome')).toBeTruthy()
    expect(fetchSpy).not.toHaveBeenCalled()
    expect(screen.getByText('Local preview — not saved')).toBeTruthy()
    fetchSpy.mockRestore()
  })

  it('adds and removes notes within the contract caps', async () => {
    const { fetchSpy } = await openPreview()

    expect(screen.getAllByText('3/10 notes')).toHaveLength(4)
    fireEvent.click(screen.getAllByRole('button', { name: 'Add a note' })[0])
    expect(screen.getAllByText('4/10 notes')).toHaveLength(1)
    expect(screen.getByText('13/25 notes in total')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Remove note 4' }))
    expect(screen.getAllByText('3/10 notes')).toHaveLength(4)
    expect(fetchSpy).not.toHaveBeenCalled()
    fetchSpy.mockRestore()
  })

  it('adds, reorders and removes sections, then respects the section cap', async () => {
    const { fetchSpy } = await openPreview()

    const titles = () =>
      (screen.getAllByLabelText('Section title') as HTMLInputElement[]).map(
        (input) => input.value,
      )
    expect(titles()).toEqual(['Session goals', 'Who is involved', 'Options to explore', 'Risks and blockers'])

    fireEvent.click(
      screen.getByRole('button', { name: 'Move section down — Session goals' }),
    )
    expect(titles()[0]).toBe('Who is involved')

    fireEvent.click(screen.getByRole('button', { name: 'Add a section' }))
    expect(titles()).toHaveLength(5)
    expect(
      (screen.getByRole('button', { name: 'Add a section' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true)

    fireEvent.click(
      screen.getByRole('button', { name: 'Remove section Risks and blockers' }),
    )
    expect(titles()).toHaveLength(4)
    expect(fetchSpy).not.toHaveBeenCalled()
    fetchSpy.mockRestore()
  })

  it('reviews note types and keeps the color mapping from the contract', async () => {
    await openPreview()

    const typeSelects = screen.getAllByLabelText('Note type') as HTMLSelectElement[]
    expect(typeSelects[0].value).toBe('idea')
    fireEvent.change(typeSelects[0], { target: { value: 'challenge' } })
    expect(typeSelects[0].value).toBe('challenge')
    expect(screen.getByText('4 sections · 12 notes')).toBeTruthy()
  })

  it('keeps the preview out of the board editor', async () => {
    const { fetchSpy } = await openPreview()

    expect(
      screen.getByText(
        'Creating the board writes the notes immediately. Collaborators see them as ordinary notes.',
      ),
    ).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull()
    expect(screen.queryByText('En direct')).toBeNull()
    expect(fetchSpy).not.toHaveBeenCalled()
    fetchSpy.mockRestore()
  })
})

describe('AI brainstorming localisation', () => {
  it('renders Arabic labels, generated content and RTL direction', async () => {
    window.localStorage.setItem('mosaiq-locale', 'ar')
    api.generate.mockResolvedValue(
      fixture({ language: 'ar', topic: 'ورشة تصميم', objective: 'تحديد المحاور والأدوار' }),
    )
    renderDialog()

    expect(document.documentElement.dir).toBe('rtl')
    expect(screen.getByLabelText('الموضوع')).toBeTruthy()
    expect(screen.getByLabelText('الهدف')).toBeTruthy()
    expect(screen.getByText('0/280 حرفًا')).toBeTruthy()

    fillForm(
      { topic: 'ورشة تصميم', objective: 'تحديد المحاور والأدوار' },
      { topic: 'الموضوع', objective: 'الهدف', context: 'السياق (اختياري)' },
    )
    acceptNotice('فهمت')
    submit('أنشئ المقترح')

    expect(await screen.findByDisplayValue('أهداف الجلسة')).toBeTruthy()
    expect(screen.getByDisplayValue(/توضيح ورشة تصميم/)).toBeTruthy()
    expect(document.documentElement.dir).toBe('rtl')
  })

  it('uses a responsive dialog and preview grid', async () => {
    api.generate.mockResolvedValue(fixture())
    renderDialog()

    const dialog = screen.getByRole('dialog')
    expect(dialog.className).toContain('w-[calc(100vw-1.5rem)]')
    expect(dialog.className).toContain('max-w-3xl')
    expect(dialog.className).toContain('max-h-[92svh]')

    fillForm()
    acceptNotice()
    submit()
    await screen.findByRole('heading', { name: 'Review the proposal' })
    const grid = screen.getAllByLabelText('Section title')[0].closest('.grid')
    expect(grid?.className).toContain('lg:grid-cols-2')
  })
})

describe('AI brainstorming feature flag', () => {
  it('hides every AI entry point when the flag is off', async () => {
    vi.stubEnv('VITE_AI_BRAINSTORMING_ENABLED', 'false')
    renderDashboard()

    await screen.findByRole('heading', { name: 'Boards' })
    expect(screen.queryByLabelText('Brainstorm with AI')).toBeNull()
    expect(screen.queryByText('Assisted brainstorming')).toBeNull()
    expect(screen.queryByRole('dialog')).toBeNull()
    // The rest of the dashboard is unchanged.
    expect(screen.getAllByRole('button', { name: 'New board' }).length).toBeGreaterThan(0)
  })

  it('shows the entry points when the flag is on', async () => {
    vi.stubEnv('VITE_AI_BRAINSTORMING_ENABLED', 'true')
    renderDashboard()

    await screen.findByRole('heading', { name: 'Boards' })
    expect(screen.getByLabelText('Brainstorm with AI')).toBeTruthy()
    expect(screen.getByText('Assisted brainstorming')).toBeTruthy()

    fireEvent.click(screen.getByLabelText('Brainstorm with AI'))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Topic')).toBeTruthy()
  })
})

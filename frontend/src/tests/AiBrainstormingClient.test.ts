import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { API_BASE_URL } from '@/api/client'
import { fetchBrainstormingProposal } from '@/api/aiBrainstorming'
import { AiGenerationError } from '@/features/ai/errors'
import { AI_BRAINSTORMING_LIMITS } from '@/features/ai/limits'
import type { AiBrainstormingRequest, AiBrainstormingResponse } from '@/features/ai/types'

/**
 * Sprint 3 — the real generation client, not a mock of it.
 *
 * Every other frontend AI suite replaces `@/api/aiBrainstorming`, which proves
 * the dialog's state machine but never exercises the transport: the 35 s budget,
 * caller cancellation, the shape/schema guards and the status-to-kind mapping.
 * These cases close that gap with a stubbed `fetch` only.
 */

const GENERATE_PATH = '/ai/brainstorming/generate'

interface StubResponse {
  ok: boolean
  status: number
  json: () => Promise<unknown>
}

function stubResponse(data: unknown, status = 200): StubResponse {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
  }
}

function wireRequest(overrides: Partial<AiBrainstormingRequest> = {}): AiBrainstormingRequest {
  return {
    topic: 'Shared bikes in Tunis',
    objective: 'Pick three pilot districts',
    context: null,
    language: 'en',
    detailLevel: 'balanced',
    schemaVersion: '1.0',
    ...overrides,
  }
}

function contractResponse(): AiBrainstormingResponse {
  return {
    schemaVersion: '1.0',
    language: 'en',
    detailLevel: 'balanced',
    truncated: false,
    proposal: {
      title: 'Shared bikes in Tunis',
      objective: 'Pick three pilot districts',
      sections: [
        {
          id: 's1',
          title: 'Session goals',
          category: 'goals',
          notes: [
            { id: 's1-n1', type: 'idea', content: 'Agree on one measurable outcome' },
            { id: 's1-n2', type: 'question', content: 'Which district goes first?' },
            { id: 's1-n3', type: 'action', content: 'Book four bikes for a two-week pilot' },
          ],
        },
      ],
    },
  }
}

function stubFetch(handler: (url: string, init: RequestInit) => Promise<StubResponse>) {
  const fetchMock = vi.fn(handler)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/** A transport that only settles when the request signal aborts. */
function stallingFetch() {
  return stubFetch(
    (_url, init) =>
      new Promise<StubResponse>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => {
          reject(Object.assign(new Error('Aborted'), { name: 'AbortError' }))
        })
      }),
  )
}

async function expectKind(promise: Promise<unknown>, kind: string, extra: Record<string, unknown> = {}) {
  await expect(promise).rejects.toBeInstanceOf(AiGenerationError)
  await expect(promise).rejects.toMatchObject({ kind, ...extra })
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  window.sessionStorage.clear()
})

describe('AI generation client', () => {
  it('posts the contract body to the Mosaiq endpoint and returns the proposal', async () => {
    const fetchMock = stubFetch(async () => stubResponse(contractResponse()))
    const request = wireRequest()

    const response = await fetchBrainstormingProposal(request)

    expect(response).toEqual(contractResponse())
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const call = fetchMock.mock.calls[0]
    expect(call[0]).toBe(`${API_BASE_URL}${GENERATE_PATH}`)
    const init = call[1] as RequestInit
    expect(init.method).toBe('POST')
    expect(init.body).toBe(JSON.stringify(request))
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json')
    expect((init.headers as Record<string, string>).Accept).toBe('application/json')
  })

  it('sends the session bearer token when the user is signed in', async () => {
    const fetchMock = stubFetch(async () => stubResponse(contractResponse()))
    window.sessionStorage.setItem('mosaiq-api-token', 'session-token')

    await fetchBrainstormingProposal(wireRequest())

    const init = fetchMock.mock.calls[0][1] as RequestInit
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer session-token')
  })

  it('refuses an oversized body without calling the network', async () => {
    const fetchMock = stubFetch(async () => stubResponse(contractResponse()))

    await expectKind(
      fetchBrainstormingProposal(wireRequest({ context: 'x'.repeat(9000) })),
      'invalid',
    )
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it.each([
    { status: 401, body: {}, kind: 'unauthorized' },
    { status: 403, body: { code: 'ai.workspace_inactive' }, kind: 'workspace_inactive' },
    { status: 422, body: { code: 'ai.invalid_request' }, kind: 'invalid' },
    { status: 429, body: { code: 'ai.rate_limited', retryAfter: 420 }, kind: 'rate_limited' },
    { status: 500, body: {}, kind: 'internal' },
    { status: 502, body: { code: 'ai.provider_failed' }, kind: 'provider_failed' },
    { status: 503, body: { code: 'ai.provider_unavailable' }, kind: 'unavailable' },
    { status: 504, body: { code: 'ai.provider_timeout' }, kind: 'timeout' },
  ])('maps HTTP $status to $kind', async ({ status, body, kind }) => {
    stubFetch(async () => stubResponse(body, status))

    await expectKind(fetchBrainstormingProposal(wireRequest()), kind)
  })

  it('keeps validation details and a sane retry delay from the error body', async () => {
    stubFetch(async () =>
      stubResponse(
        {
          code: 'ai.invalid_request',
          error: '"topic" is required.',
          details: [{ field: 'topic', message: 'is required.' }],
        },
        422,
      ),
    )
    await expectKind(fetchBrainstormingProposal(wireRequest()), 'invalid', {
      status: 422,
      details: [{ field: 'topic', message: 'is required.' }],
    })

    stubFetch(async () => stubResponse({ code: 'ai.rate_limited', retryAfter: 420 }, 429))
    await expectKind(fetchBrainstormingProposal(wireRequest()), 'rate_limited', { retryAfter: 420 })

    // A hostile or nonsensical Retry-After is dropped, not surfaced to the user.
    stubFetch(async () => stubResponse({ code: 'ai.rate_limited', retryAfter: 999_999 }, 429))
    const hostile = await fetchBrainstormingProposal(wireRequest()).catch((error: unknown) => error)
    expect(hostile).toBeInstanceOf(AiGenerationError)
    expect((hostile as AiGenerationError).retryAfter).toBeUndefined()
  })

  it('treats a non-JSON success body as a provider failure', async () => {
    stubFetch(async () => ({
      ok: true,
      status: 200,
      json: async () => {
        throw new SyntaxError('Unexpected token <')
      },
    }))

    await expectKind(fetchBrainstormingProposal(wireRequest()), 'provider_failed')
  })

  it.each([
    ['a missing proposal', { schemaVersion: '1.0', language: 'en', detailLevel: 'balanced', truncated: false }],
    ['an unknown section category', { ...contractResponse(), proposal: { ...contractResponse().proposal, sections: [{ id: 's1', title: 'x', category: 'invented', notes: [] }] } }],
    ['an unknown note type', { ...contractResponse(), proposal: { ...contractResponse().proposal, sections: [{ id: 's1', title: 'x', category: 'ideas', notes: [{ id: 'n1', type: 'todo', content: 'x' }] }] } }],
    ['a non-boolean truncated flag', { ...contractResponse(), truncated: 'no' }],
  ])('rejects an off-contract response: %s', async (_label, body) => {
    stubFetch(async () => stubResponse(body))

    await expectKind(fetchBrainstormingProposal(wireRequest()), 'provider_failed')
  })

  it('rejects a future schema version instead of guessing its shape', async () => {
    stubFetch(async () => stubResponse({ ...contractResponse(), schemaVersion: '2.0' }))

    await expectKind(fetchBrainstormingProposal(wireRequest()), 'unsupported_version')
  })

  it('reports a caller cancellation as cancelled, not as a timeout', async () => {
    stallingFetch()
    const controller = new AbortController()

    const pending = fetchBrainstormingProposal(wireRequest(), { signal: controller.signal })
    const assertion = expectKind(pending, 'cancelled')
    controller.abort()

    await assertion
  })

  it('aborts at the contract budget and reports a timeout', async () => {
    vi.useFakeTimers()
    stallingFetch()

    const pending = fetchBrainstormingProposal(wireRequest())
    const assertion = expectKind(pending, 'timeout')
    await vi.advanceTimersByTimeAsync(AI_BRAINSTORMING_LIMITS.requestTimeoutMs)

    await assertion
  })

  it('reports an unreachable endpoint as a network failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )

    await expectKind(fetchBrainstormingProposal(wireRequest()), 'network')
  })
})

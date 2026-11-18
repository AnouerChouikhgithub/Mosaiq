import { API_BASE_URL, getSessionToken } from '@/api/client'
import { readAiBrainstormingFlags } from '@/features/ai/config'
import { AiGenerationError, mapAiErrorResponse } from '@/features/ai/errors'
import { AI_BRAINSTORMING_LIMITS } from '@/features/ai/limits'
import { normalizePromptText } from '@/features/ai/validation'
import { generateMockProposal } from '@/features/ai/mockResponse'
import {
  AI_SCHEMA_VERSION,
  type AiBrainstormingFormValues,
  type AiBrainstormingRequest,
  type AiBrainstormingResponse,
  type AiDetailLevel,
  type AiLanguage,
  type AiNoteType,
  type AiSectionCategory,
} from '@/features/ai/types'

/**
 * Dedicated client for `POST /api/ai/brainstorming/generate` (contract §6).
 *
 * It is separate from `api/client.ts` for one reason: the shared request helper
 * bounds every call at 5 s, while generation needs the contract's 35 s budget.
 * Everything else (base URL, bearer token, `{ error }` rendering) follows the
 * existing conventions, and the real endpoint is the only network dependency —
 * there is no second persistence path here.
 *
 * While Sprint 1A is being built in parallel, `generateBrainstormingProposal`
 * routes to a mocked contract-shaped response. Flip
 * `VITE_AI_BRAINSTORMING_MOCK=false` to call the real endpoint; nothing else in
 * the UI changes.
 */

const AI_GENERATE_PATH = '/ai/brainstorming/generate'

export interface AiRequestOptions {
  signal?: AbortSignal
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function isLanguage(value: unknown): value is AiLanguage {
  return value === 'en' || value === 'fr' || value === 'ar'
}

function isDetailLevel(value: unknown): value is AiDetailLevel {
  return value === 'concise' || value === 'balanced' || value === 'detailed'
}

function isNoteType(value: unknown): value is AiNoteType {
  return (
    value === 'idea' ||
    value === 'question' ||
    value === 'challenge' ||
    value === 'opportunity' ||
    value === 'action'
  )
}

function isSectionCategory(value: unknown): value is AiSectionCategory {
  return (
    value === 'goals' ||
    value === 'audience' ||
    value === 'ideas' ||
    value === 'risks' ||
    value === 'actions' ||
    value === 'questions'
  )
}

/**
 * Shape guard for the untrusted response body. Unknown extra fields are
 * tolerated (§9); a missing or mistyped required field is not.
 */
export function isAiBrainstormingResponse(
  value: unknown,
): value is AiBrainstormingResponse {
  if (!isRecord(value)) return false
  if (typeof value.schemaVersion !== 'string') return false
  if (!isLanguage(value.language)) return false
  if (!isDetailLevel(value.detailLevel)) return false
  if (typeof value.truncated !== 'boolean') return false
  if (!isRecord(value.proposal)) return false

  const proposal = value.proposal
  if (typeof proposal.title !== 'string' || typeof proposal.objective !== 'string') {
    return false
  }
  if (!Array.isArray(proposal.sections)) return false

  return proposal.sections.every((section) => {
    if (!isRecord(section)) return false
    if (
      typeof section.id !== 'string' ||
      typeof section.title !== 'string' ||
      !isSectionCategory(section.category) ||
      !Array.isArray(section.notes)
    ) {
      return false
    }
    return section.notes.every(
      (note) =>
        isRecord(note) &&
        typeof note.id === 'string' &&
        isNoteType(note.type) &&
        typeof note.content === 'string',
    )
  })
}

/** Turns the form values into the exact request body of contract §3. */
export function toWireRequest(
  values: AiBrainstormingFormValues,
): AiBrainstormingRequest {
  const context = normalizePromptText(values.context)

  return {
    topic: normalizePromptText(values.topic),
    objective: normalizePromptText(values.objective),
    context: context ? context : null,
    language: values.language,
    detailLevel: values.detailLevel,
    schemaVersion: AI_SCHEMA_VERSION,
  }
}

/** Calls the real endpoint with the contract's 35 s budget (§6.9). */
export async function fetchBrainstormingProposal(
  request: AiBrainstormingRequest,
  options: AiRequestOptions = {},
): Promise<AiBrainstormingResponse> {
  const controller = new AbortController()
  const abortFromCaller = () => controller.abort()
  let timedOut = false

  options.signal?.addEventListener('abort', abortFromCaller, { once: true })
  const timer = window.setTimeout(() => {
    timedOut = true
    controller.abort()
  }, AI_BRAINSTORMING_LIMITS.requestTimeoutMs)

  try {
    const body = JSON.stringify(request)
    if (
      new TextEncoder().encode(body).byteLength >
      AI_BRAINSTORMING_LIMITS.requestBodyMaxBytes
    ) {
      throw new AiGenerationError('invalid', 'The request body exceeds 8 KiB.')
    }

    const token = getSessionToken()
    const response = await fetch(`${API_BASE_URL}${AI_GENERATE_PATH}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      signal: controller.signal,
      body,
    })

    if (!response.ok) {
      let errorBody: unknown = null
      try {
        errorBody = await response.json()
      } catch {
        // A non-JSON error body keeps the status-based mapping.
      }
      throw mapAiErrorResponse(response.status, errorBody)
    }

    let payload: unknown
    try {
      payload = await response.json()
    } catch (error) {
      throw new AiGenerationError('provider_failed', 'The response was not JSON.', {
        cause: error,
      })
    }

    if (!isAiBrainstormingResponse(payload)) {
      throw new AiGenerationError('provider_failed', 'The response does not match the contract.')
    }
    if (payload.schemaVersion !== AI_SCHEMA_VERSION) {
      throw new AiGenerationError(
        'unsupported_version',
        `Unsupported schema version ${payload.schemaVersion}.`,
      )
    }

    return payload
  } catch (error) {
    if (error instanceof AiGenerationError) throw error
    // The caller's intent wins when both a cancel and a timeout happened.
    if (options.signal?.aborted) {
      throw new AiGenerationError('cancelled', 'Generation cancelled.', { cause: error })
    }
    if (timedOut) {
      throw new AiGenerationError(
        'timeout',
        `Generation timed out after ${AI_BRAINSTORMING_LIMITS.requestTimeoutMs} ms.`,
        { cause: error },
      )
    }
    throw new AiGenerationError('network', 'The AI endpoint could not be reached.', {
      cause: error,
    })
  } finally {
    window.clearTimeout(timer)
    options.signal?.removeEventListener('abort', abortFromCaller)
  }
}

/**
 * The single entry point the UI uses. In Sprint 1B it is mocked by default; the
 * real transport sits beside it and is selected by `VITE_AI_BRAINSTORMING_MOCK`.
 */
export function generateBrainstormingProposal(
  request: AiBrainstormingRequest,
  options: AiRequestOptions = {},
): Promise<AiBrainstormingResponse> {
  if (readAiBrainstormingFlags().useMock) {
    return generateMockProposal(request, options)
  }
  return fetchBrainstormingProposal(request, options)
}

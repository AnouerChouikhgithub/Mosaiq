import type { AiErrorBody, AiValidationDetail } from './types'

/**
 * Every failure the generation experience can show, translated by the UI.
 * `kind` is the only value components branch on; HTTP details stay here.
 */
export type AiErrorKind =
  | 'invalid'
  | 'unsupported_version'
  | 'workspace_inactive'
  | 'rate_limited'
  | 'unavailable'
  | 'provider_failed'
  | 'timeout'
  | 'network'
  | 'unauthorized'
  | 'internal'
  | 'cancelled'

export interface AiGenerationErrorOptions {
  status?: number
  code?: string
  retryAfter?: number
  details?: AiValidationDetail[]
  cause?: unknown
}

/** A contract-shaped failure; never carries prompt or provider text. */
export class AiGenerationError extends Error {
  readonly kind: AiErrorKind
  readonly status?: number
  readonly code?: string
  readonly retryAfter?: number
  readonly details?: AiValidationDetail[]

  constructor(kind: AiErrorKind, message: string, options: AiGenerationErrorOptions = {}) {
    super(message, options.cause === undefined ? undefined : { cause: options.cause })
    this.name = 'AiGenerationError'
    this.kind = kind
    this.status = options.status
    this.code = options.code
    this.retryAfter = options.retryAfter
    this.details = options.details
  }
}

export function isAiGenerationError(value: unknown): value is AiGenerationError {
  return value instanceof AiGenerationError
}

/** Clamps a provider-supplied `Retry-After` to the contract's sane range (§6.8). */
export function saneRetryAfter(value: unknown): number | undefined {
  if (typeof value !== 'number' || !Number.isFinite(value)) return undefined
  if (value < 1 || value > 3600) return undefined
  return Math.round(value)
}

const kindsByCode: Record<string, AiErrorKind> = {
  'ai.invalid_request': 'invalid',
  'ai.unsupported_schema_version': 'unsupported_version',
  'ai.malformed_json': 'invalid',
  'ai.workspace_inactive': 'workspace_inactive',
  'ai.rate_limited': 'rate_limited',
  'ai.provider_unavailable': 'unavailable',
  'ai.provider_timeout': 'timeout',
  'ai.provider_failed': 'provider_failed',
  'ai.internal_error': 'internal',
}

/** §6.4–6.10 — maps an error response onto one translated state. */
export function mapAiErrorResponse(status: number, body: unknown): AiGenerationError {
  const parsed: AiErrorBody =
    body && typeof body === 'object' ? (body as AiErrorBody) : {}
  const code = typeof parsed.code === 'string' ? parsed.code : undefined
  const message =
    typeof parsed.error === 'string' && parsed.error.trim()
      ? parsed.error
      : `AI request failed with ${status}`

  const kind: AiErrorKind =
    (code ? kindsByCode[code] : undefined) ??
    (status === 401
      ? 'unauthorized'
      : status === 403
        ? 'workspace_inactive'
        : status === 422
          ? 'invalid'
          : status === 429
            ? 'rate_limited'
            : status === 502
              ? 'provider_failed'
              : status === 503
                ? 'unavailable'
                : status === 504
                  ? 'timeout'
                  : 'internal')

  return new AiGenerationError(kind, message, {
    status,
    code,
    retryAfter: saneRetryAfter(parsed.retryAfter),
    details: Array.isArray(parsed.details) ? parsed.details : undefined,
  })
}

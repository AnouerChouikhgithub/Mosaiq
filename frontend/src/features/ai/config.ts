/**
 * Feature configuration for the AI brainstorming experience.
 *
 * Both switches are build-time environment variables, matching the project's
 * existing `VITE_*` convention (see `api/client.ts`). They are read on demand so
 * a test can stub them, and they default to "on" so the demo shows the feature.
 *
 * - `VITE_AI_BRAINSTORMING_ENABLED=false` hides every entry point. Nothing else
 *   in Mosaiq changes: no request is made and no component is mounted.
 * - `VITE_AI_BRAINSTORMING_MOCK=false` sends the real request to Sprint 1A's
 *   endpoint. Sprint 1B ships with the mock on because the backend is developed
 *   in parallel; flip this only once the endpoint exists.
 */
export interface AiBrainstormingFlags {
  enabled: boolean
  useMock: boolean
}

export function readAiBrainstormingFlags(
  env: Record<string, string | boolean | undefined> = import.meta.env,
): AiBrainstormingFlags {
  return {
    enabled: env.VITE_AI_BRAINSTORMING_ENABLED !== 'false',
    useMock: env.VITE_AI_BRAINSTORMING_MOCK !== 'false',
  }
}

export function isAiBrainstormingEnabled(): boolean {
  return readAiBrainstormingFlags().enabled
}

/**
 * §8.2 — the external-provider notice is acknowledged per browser session.
 * `sessionStorage` is a reminder, not a security control.
 */
export const AI_CONSENT_STORAGE_KEY = 'mosaiq-ai-consent'

export function hasAcceptedAiNotice(): boolean {
  try {
    return window.sessionStorage.getItem(AI_CONSENT_STORAGE_KEY) === 'accepted'
  } catch {
    return false
  }
}

export function rememberAiNoticeAcceptance(): void {
  try {
    window.sessionStorage.setItem(AI_CONSENT_STORAGE_KEY, 'accepted')
  } catch {
    // Consent cannot be persisted in private mode; the checkbox still gates the
    // request for this mount.
  }
}

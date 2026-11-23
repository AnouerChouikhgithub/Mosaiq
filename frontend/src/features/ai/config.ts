/**
 * Feature configuration for the AI brainstorming experience.
 *
 * `VITE_AI_BRAINSTORMING_ENABLED=false` hides every entry point. Nothing else in
 * Mosaiq changes: no request is made and no component is mounted. Generation
 * always calls the real Mosaiq endpoint; there is no mock transport any more.
 */
export interface AiBrainstormingFlags {
  enabled: boolean
}

export function readAiBrainstormingFlags(
  env: Record<string, string | boolean | undefined> = import.meta.env,
): AiBrainstormingFlags {
  return {
    enabled: env.VITE_AI_BRAINSTORMING_ENABLED !== 'false',
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

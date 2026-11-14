import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  acceptBoardInvitation,
  inviteBoardCollaborator,
  register,
} from '@/api/client'

interface Captured {
  url: string
  init?: RequestInit
}

function stubFetch(payload: unknown, captured: Captured[]) {
  vi.stubGlobal('fetch', vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
    captured.push({ url: String(url), init })
    return new Response(JSON.stringify(payload), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }))
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.sessionStorage.clear()
})

describe('invitation API', () => {
  it('creates an invitation for the requested board, email and role', async () => {
    const captured: Captured[] = []
    stubFetch({ token: 'invite-token', email: 'new@example.test', role: 'Editor', expiresAt: '2026-11-21T00:00:00Z' }, captured)

    const invitation = await inviteBoardCollaborator('board-1', 'new@example.test', 'Editor')

    expect(invitation.token).toBe('invite-token')
    expect(captured[0]?.url).toMatch(/\/api\/boards\/board-1\/invitations$/)
    expect(captured[0]?.init?.method).toBe('POST')
    expect(JSON.parse(String(captured[0]?.init?.body))).toEqual({ email: 'new@example.test', role: 'Editor' })
  })

  it('forwards the token at registration and accepts it for existing accounts', async () => {
    const captured: Captured[] = []
    stubFetch({
      token: 'session-token',
      user: { id: 'user-1', name: 'New Person', email: 'new@example.test', role: 'Viewer', city: 'Tunis', accent: 'cursor-1' },
      workspace: { id: 'workspace-1', name: 'Studio', plan: 'Starter' },
    }, captured)

    await register('New Person', 'new@example.test', 'Long-Password-2026!', 'invite-token')
    expect(JSON.parse(String(captured[0]?.init?.body))).toMatchObject({ invitationToken: 'invite-token' })

    await acceptBoardInvitation('invite-token')
    expect(captured[1]?.url).toMatch(/\/api\/boards\/invitations\/invite-token\/accept$/)
    expect(captured[1]?.init?.method).toBe('POST')
  })
})
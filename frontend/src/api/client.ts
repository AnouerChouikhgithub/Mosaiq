import type {
  Board,
  CommentThread,
  TimelineEntry,
  NoteAlign,
  NoteColor,
  NoteStroke,
  NoteTextSize,
  StickyNote,
  User,
} from '@/mocks'
import { users } from '@/mocks'
import type { Locale } from '@/i18n'

const API_BASE_URL = (
  import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api'
).replace(/\/$/, '')

// Bound only stalled transports; 401/500 responses still reject immediately.
const REQUEST_TIMEOUT_MS = 5_000
const SESSION_TOKEN_KEY = 'mosaiq-api-token'
const SESSION_USER_KEY = 'mosaiq-api-user'
const SESSION_WORKSPACE_KEY = 'mosaiq-api-workspace'

export interface ApiSessionUser extends ApiUser {
  boardRole?: string
}

export interface ApiSessionWorkspace {
  id: string
  name: string
  plan: string
  memberCount?: number
}

export interface ApiSession {
  token: string
  user: ApiSessionUser
  workspace: ApiSessionWorkspace
}

export interface ApiTimelineEntry {
  id: string
  at: string
  label: string
  kind: string
  reveals: string[] | null
  snapshot: ApiCanvasElement[] | null
  author: { id: string; name: string }
}

export interface ApiCommentMessage {
  id: string
  content: string
  createdAt: string
  author: ApiCanvasAuthor
}

export interface ApiCommentThread {
  id: string
  x: number
  y: number
  resolved: boolean
  createdAt: string
  messages: ApiCommentMessage[]
}

export function toCommentThread(thread: ApiCommentThread): CommentThread {
  return {
    id: thread.id,
    x: thread.x,
    y: thread.y,
    resolved: thread.resolved,
    messages: thread.messages.map((message) => ({
      id: message.id,
      authorId: localUserId(message.author),
      authorName: message.author.name,
      body: message.content,
      at: new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(message.createdAt)),
    })),
  }
}

export function toTimelineEntry(entry: ApiTimelineEntry): TimelineEntry {
  return {
    id: entry.id,
    userId: entry.author.id,
    authorName: entry.author.name,
    at: new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(entry.at)),
    label: entry.label,
    kind: ['board', 'note', 'connector', 'frame', 'rename'].includes(entry.kind) ? entry.kind as TimelineEntry['kind'] : 'board',
    reveals: entry.reveals ?? undefined,
  }
}

export function getSessionToken(): string | null {
  return window.sessionStorage.getItem(SESSION_TOKEN_KEY)
}

export function getSessionUser(): ApiSessionUser | null {
  const value = window.sessionStorage.getItem(SESSION_USER_KEY)
  if (!value) return null
  try {
    return JSON.parse(value) as ApiSessionUser
  } catch {
    window.sessionStorage.removeItem(SESSION_USER_KEY)
    return null
  }
}

function saveSession(session: ApiSession): void {
  window.sessionStorage.setItem(SESSION_TOKEN_KEY, session.token)
  window.sessionStorage.setItem(SESSION_USER_KEY, JSON.stringify(session.user))
  window.sessionStorage.setItem(SESSION_WORKSPACE_KEY, JSON.stringify(session.workspace))
}

export function getSessionWorkspace(): ApiSessionWorkspace | null {
  const value = window.sessionStorage.getItem(SESSION_WORKSPACE_KEY)
  if (!value) return null
  try {
    return JSON.parse(value) as ApiSessionWorkspace
  } catch {
    window.sessionStorage.removeItem(SESSION_WORKSPACE_KEY)
    return null
  }
}

export function clearSession(): void {
  window.sessionStorage.removeItem(SESSION_TOKEN_KEY)
  window.sessionStorage.removeItem(SESSION_USER_KEY)
  window.sessionStorage.removeItem(SESSION_WORKSPACE_KEY)
}

export const SESSION_EXPIRED_EVENT = 'mosaiq:session-expired'
export const WORKSPACE_UPDATED_EVENT = 'mosaiq:workspace-updated'

/** Keeps the shell in sync after settings/member changes without a reload. */
export function rememberSessionWorkspace(workspace: ApiSessionWorkspace): void {
  window.sessionStorage.setItem(SESSION_WORKSPACE_KEY, JSON.stringify(workspace))
  window.dispatchEvent(new CustomEvent<ApiSessionWorkspace>(WORKSPACE_UPDATED_EVENT, { detail: workspace }))
}

function expireSession(): void {
  // Guard against a burst of parallel 401s dispatching several redirects.
  if (!getSessionToken()) return
  clearSession()
  window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT))
}

export interface ApiWorkspace {
  id: string
  name: string
  plan: string
  pricePerMember: number
  currency: string
  seats: number
  renewsOn: string
  memberCount: number
  boardCount: number
  createdAt: string
}

export interface ApiWorkspaceMember extends ApiUser {
  status: 'active' | 'removed'
  joinedAt: string
  updatedAt: string
}

export interface ApiWorkspaceInvitation {
  id: string
  email: string
  role: 'Admin' | 'Editor' | 'Commenter' | 'Viewer'
  status: 'pending' | 'accepted' | 'revoked' | 'expired'
  createdAt: string
  expiresAt: string
  acceptedAt: string | null
  revokedAt: string | null
  invitedBy: { id: string; name: string }
}

export interface ApiUser {
  id: string
  name: string
  email: string
  role: User['role']
  status?: 'active' | 'removed'
  city: string
  accent: User['accent']
}

/**
 * Board-safe collaborator profile returned by presence, canvas and comment
 * payloads. Only the member directory keeps the email and city.
 */
export interface ApiCanvasAuthor {
  id: string
  name: string
  role: User['role']
  accent: User['accent']
}

export interface ApiBoardMember extends ApiUser {
  boardRole?: string
}

export type ApiBoardStatus = 'active' | 'archived' | 'trashed'

export interface ApiBoard {
  id: string
  title: string
  starred: boolean
  isTemplate: boolean
  status?: ApiBoardStatus
  archivedAt?: string | null
  trashedAt?: string | null
  publicAccess?: boolean
  members: ApiBoardMember[]
  noteColors: string[]
  workspace: { id: string; name: string }
  elementCount: number
  createdAt: string
  updatedAt: string
  elements?: ApiCanvasElement[]
}

export interface ApiCanvasElement {
  id: string
  type: string
  x: number
  y: number
  width: number
  height: number
  rotation: number
  content: string | null
  color: string | null
  metadata: Record<string, unknown> | null
  /** Present when the element was created through an idempotent mutation. */
  mutationId?: string | null
  author: ApiCanvasAuthor
  createdAt: string
}

export interface CanvasElementInput {
  authorId: string
  type: string
  x: number
  y: number
  width: number
  height: number
  rotation?: number
  content?: string | null
  color?: string | null
  metadata?: Record<string, unknown> | null
  /** Idempotency key of this create operation; reuse it on every retry. */
  mutationId?: string
}

export type CanvasElementPatch = Partial<
  Pick<
    CanvasElementInput,
    'x' | 'y' | 'width' | 'height' | 'rotation' | 'content' | 'color' | 'metadata'
  >
>

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const controller = new AbortController()
  const token = getSessionToken()
  const timeout = window.setTimeout(
    () => controller.abort(),
    REQUEST_TIMEOUT_MS,
  )

  try {
    const response = await fetch(`${API_BASE_URL}${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...init?.headers,
      },
      signal: controller.signal,
    })

    if (!response.ok) {
      // Surface the API's own message when it sends one; fall back to transport text.
      let message = `API ${response.status} ${response.statusText} for ${path}`
      try {
        const body: unknown = await response.json()
        if (
          body &&
          typeof body === 'object' &&
          'error' in body &&
          typeof body.error === 'string' &&
          body.error.trim()
        ) {
          message = body.error
        }
      } catch {
        // Non-JSON error body: keep the transport message.
      }
      const error = new Error(message) as Error & { status?: number }
      error.status = response.status

      // A login/registration 401 is a credential error, not an expired session.
      if (
        response.status === 401 &&
        token &&
        path !== '/auth/login' &&
        path !== '/auth/register'
      ) {
        expireSession()
      }

      throw error
    }

    if (response.status === 204) {
      return undefined as T
    }

    return (await response.json()) as T
  } catch (error) {
    if (controller.signal.aborted) {
      throw new Error(`API request timed out after ${REQUEST_TIMEOUT_MS} ms for ${path}`, { cause: error })
    }
    throw error
  } finally {
    window.clearTimeout(timeout)
  }
}

export async function login(email: string, password: string): Promise<ApiSession> {
  const session = await request<ApiSession>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
  saveSession(session)
  return session
}

export async function register(
  name: string,
  email: string,
  password: string,
  invitationToken?: string,
  workspaceInvitationToken?: string,
): Promise<ApiSession> {
  const session = await request<ApiSession>('/auth/register', {
    method: 'POST',
    body: JSON.stringify({
      name,
      email,
      password,
      ...(invitationToken ? { invitationToken } : {}),
      ...(workspaceInvitationToken ? { workspaceInvitationToken } : {}),
    }),
  })
  saveSession(session)
  return session
}

export async function getMe(): Promise<{ user: ApiSessionUser; workspace: { id: string; name: string; plan: string } }> {
  return request('/auth/me')
}

export async function logout(): Promise<void> {
  try {
    if (getSessionToken()) await request('/auth/logout', { method: 'POST' })
  } catch {
    // A failed network call must not trap the user in the signed-in UI. The
    // server-side token still expires at AUTH_TOKEN_TTL if revocation failed.
  } finally {
    clearSession()
  }
}

export async function getWorkspaces(): Promise<ApiWorkspace[]> {
  const data = await request<{ workspaces: ApiWorkspace[] }>('/workspaces')
  return data.workspaces
}

export async function updateWorkspace(id: string, name: string): Promise<ApiWorkspace> {
  return request<ApiWorkspace>(`/workspaces/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify({ name }),
  })
}

export async function getWorkspaceMembers(id: string): Promise<ApiWorkspaceMember[]> {
  const data = await request<{ members: ApiWorkspaceMember[] }>(`/workspaces/${encodeURIComponent(id)}/members`)
  return data.members
}

export async function updateWorkspaceMember(
  workspaceId: string,
  userId: string,
  role: string,
): Promise<ApiWorkspaceMember> {
  const data = await request<{ member: ApiWorkspaceMember }>(
    `/workspaces/${encodeURIComponent(workspaceId)}/members/${encodeURIComponent(userId)}`,
    { method: 'PATCH', body: JSON.stringify({ role }) },
  )
  return data.member
}

export async function removeWorkspaceMember(workspaceId: string, userId: string): Promise<void> {
  await request(`/workspaces/${encodeURIComponent(workspaceId)}/members/${encodeURIComponent(userId)}`, {
    method: 'DELETE',
  })
}

export async function getWorkspaceInvitations(id: string): Promise<ApiWorkspaceInvitation[]> {
  const data = await request<{ invitations: ApiWorkspaceInvitation[] }>(
    `/workspaces/${encodeURIComponent(id)}/invitations`,
  )
  return data.invitations
}

export async function inviteWorkspaceMember(
  workspaceId: string,
  email: string,
  role: string,
): Promise<{ invitation: ApiWorkspaceInvitation; token: string; emailSent: boolean }> {
  return request(`/workspaces/${encodeURIComponent(workspaceId)}/invitations`, {
    method: 'POST',
    body: JSON.stringify({ email, role }),
  })
}

export async function revokeWorkspaceInvitation(workspaceId: string, invitationId: string): Promise<void> {
  await request(
    `/workspaces/${encodeURIComponent(workspaceId)}/invitations/${encodeURIComponent(invitationId)}`,
    { method: 'DELETE' },
  )
}

export async function getBoards(workspaceId?: string, status?: ApiBoardStatus): Promise<ApiBoard[]> {
  const params = new URLSearchParams()
  if (workspaceId) params.set('workspaceId', workspaceId)
  if (status) params.set('status', status)
  const query = params.size > 0 ? `?${params.toString()}` : ''
  const data = await request<{ boards: ApiBoard[] }>(`/boards${query}`)
  return data.boards
}

export async function archiveBoard(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}/archive`, { method: 'POST' })
}

export async function trashBoard(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}/trash`, { method: 'POST' })
}

export async function restoreBoardLifecycle(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}/restore`, { method: 'POST' })
}

export async function createBoard(
  title: string,
  workspaceId?: string,
): Promise<ApiBoard> {
  const targetWorkspace =
    workspaceId ?? (await getWorkspaces())[0]?.id

  if (!targetWorkspace) {
    throw new Error('No workspace is available to own the board.')
  }

  return request<ApiBoard>('/boards', {
    method: 'POST',
    body: JSON.stringify({ title, workspaceId: targetWorkspace }),
  })
}

export async function updateBoard(
  id: string,
  patch: { title?: string; starred?: boolean },
): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  })
}

export async function toggleStarBoard(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}/star`, {
    method: 'POST',
  })
}

export async function getBoard(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}`)
}

export async function getPublicBoard(id: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(id)}`)
}

export async function getTimeline(boardId: string): Promise<ApiTimelineEntry[]> {
  const data = await request<{ timeline: ApiTimelineEntry[] }>(`/boards/${encodeURIComponent(boardId)}/timeline`)
  return data.timeline
}

export async function getTimelineCanvas(boardId: string): Promise<ApiCanvasElement[]> {
  return getCanvasElements(boardId)
}

export async function getBoardDetail(id: string): Promise<ApiBoard> {
  return getBoard(id)
}

export async function restoreTimeline(boardId: string, entryId: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(boardId)}/restore/${encodeURIComponent(entryId)}`, { method: 'POST' })
}

export async function getComments(boardId: string): Promise<ApiCommentThread[]> {
  const data = await request<{ threads: ApiCommentThread[] }>(`/boards/${encodeURIComponent(boardId)}/comments`)
  return data.threads
}

export async function createComment(boardId: string, x: number, y: number, content: string): Promise<ApiCommentThread> {
  return request<ApiCommentThread>(`/boards/${encodeURIComponent(boardId)}/comments`, { method: 'POST', body: JSON.stringify({ x, y, content }) })
}

export async function replyToComment(threadId: string, content: string): Promise<ApiCommentMessage> {
  return request<ApiCommentMessage>(`/comments/threads/${encodeURIComponent(threadId)}/messages`, { method: 'POST', body: JSON.stringify({ content }) })
}

export async function resolveComment(threadId: string, resolved: boolean): Promise<ApiCommentThread> {
  return request<ApiCommentThread>(`/comments/threads/${encodeURIComponent(threadId)}/resolve`, { method: 'PATCH', body: JSON.stringify({ resolved }) })
}

export async function inviteBoardCollaborator(boardId: string, email: string, role: string): Promise<{ token: string; email: string; role: string; expiresAt: string }> {
  return request(`/boards/${encodeURIComponent(boardId)}/invitations`, { method: 'POST', body: JSON.stringify({ email, role }) })
}

export async function acceptBoardInvitation(token: string): Promise<ApiBoard> {
  return request(`/boards/invitations/${encodeURIComponent(token)}/accept`, { method: 'POST' })
}

export async function updateBoardMember(boardId: string, userId: string, role: string): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(boardId)}/members/${encodeURIComponent(userId)}`, { method: 'PATCH', body: JSON.stringify({ role }) })
}

export async function removeBoardMember(boardId: string, userId: string): Promise<void> {
  await request(`/boards/${encodeURIComponent(boardId)}/members/${encodeURIComponent(userId)}`, { method: 'DELETE' })
}

export async function setBoardLinkAccess(boardId: string, publicAccess: boolean): Promise<ApiBoard> {
  return request<ApiBoard>(`/boards/${encodeURIComponent(boardId)}/access`, { method: 'PATCH', body: JSON.stringify({ publicAccess }) })
}

export interface ApiPresence {
  user: ApiCanvasAuthor
  x: number
  y: number
  tool: string
  lastActive: string
}

export async function getPresence(boardId: string): Promise<ApiPresence[]> {
  const data = await request<{ presence: ApiPresence[] }>(`/boards/${encodeURIComponent(boardId)}/presence`)
  return data.presence
}

export async function updatePresence(boardId: string, x: number, y: number, tool: string): Promise<void> {
  await request<void>(`/boards/${encodeURIComponent(boardId)}/presence`, { method: 'POST', body: JSON.stringify({ x, y, tool }) })
}

export interface BoardStreamEvent {
  presence?: ApiPresence[]
  revision?: string
  elementCount?: number
  updatedAt?: string
}

/**
 * Reads the board SSE stream with fetch so the session bearer token stays in an
 * Authorization header instead of leaking into a query string. The server bounds
 * each stream; the caller reconnects when this promise resolves.
 */
export async function streamBoardEvents(
  boardId: string,
  onEvent: (event: BoardStreamEvent) => void,
  signal: AbortSignal,
): Promise<void> {
  const token = getSessionToken()
  const response = await fetch(`${API_BASE_URL}/boards/${encodeURIComponent(boardId)}/stream`, {
    headers: {
      Accept: 'text/event-stream',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    signal,
  })
  if (!response.ok || !response.body) {
    throw new Error(`Stream ${response.status} for ${boardId}`)
  }

  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''

  for (;;) {
    const { value, done } = await reader.read()
    if (done) return
    buffer += decoder.decode(value, { stream: true })
    const frames = buffer.split('\n\n')
    buffer = frames.pop() ?? ''

    for (const frame of frames) {
      const data = frame
        .split('\n')
        .filter((line) => line.startsWith('data:'))
        .map((line) => line.slice(5).trim())
        .join('\n')
      if (!data) continue
      try {
        onEvent(JSON.parse(data) as BoardStreamEvent)
      } catch {
        // Ignore malformed frames; the next event or poll still reconciles state.
      }
    }
  }
}

export function toLiveCursor(presence: ApiPresence) {
  return {
    userId: presence.user.id,
    x: presence.x,
    y: presence.y,
    name: presence.user.name,
    accent: presence.user.accent,
  }
}

export async function getCanvasElements(
  boardId: string,
): Promise<ApiCanvasElement[]> {
  const data = await request<{ elements: ApiCanvasElement[] }>(
    `/boards/${encodeURIComponent(boardId)}/elements`,
  )
  return data.elements
}

export async function createCanvasElement(
  boardId: string,
  element: CanvasElementInput,
): Promise<ApiCanvasElement> {
  return request<ApiCanvasElement>(
    `/boards/${encodeURIComponent(boardId)}/elements`,
    { method: 'POST', body: JSON.stringify(element) },
  )
}

export async function updateCanvasElement(
  id: string,
  patch: CanvasElementPatch,
): Promise<ApiCanvasElement> {
  return request<ApiCanvasElement>(`/elements/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  })
}

export async function deleteCanvasElement(id: string): Promise<void> {
  await request<void>(`/elements/${encodeURIComponent(id)}`, {
    method: 'DELETE',
  })
}

/** Logs once per failed call without interrupting the demo experience. */
export function warnFallback(action: string, error: unknown): void {
  console.warn(
    `[Mosaiq] ${action} is unavailable; continuing with local demo data.`,
    error,
  )
}

const localeTags: Record<Locale, string> = {
  fr: 'fr-FR',
  en: 'en-GB',
  ar: 'ar-TN',
}

const localUserIds = new Map(
  users.map((user) => [user.name.toLowerCase(), user.id]),
)

function localUserId(apiUser: Pick<ApiUser, 'id' | 'name'>): string {
  return localUserIds.get(apiUser.name.toLowerCase()) ?? apiUser.id
}

function formatUpdatedAt(value: string, locale: Locale): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat(localeTags[locale], {
    day: 'numeric',
    month: 'short',
  }).format(date)
}

const noteColors: NoteColor[] = ['amber', 'rose', 'sky', 'mint', 'lilac']
const noteStrokes: NoteStroke[] = ['none', 'thin', 'dashed']
const noteTextSizes: NoteTextSize[] = ['s', 'm', 'l']
const noteAlignments: NoteAlign[] = ['left', 'center', 'right']

function isNoteColor(value: string | null): value is NoteColor {
  return value !== null && noteColors.includes(value as NoteColor)
}

function metadataValue<T extends string>(
  metadata: Record<string, unknown> | null,
  key: string,
  allowed: readonly T[],
  fallback: T,
): T {
  const value = metadata?.[key]
  return typeof value === 'string' && allowed.includes(value as T)
    ? (value as T)
    : fallback
}

/** Maps the API summary onto the typed board model used by the screens. */
export function toBoard(api: ApiBoard, locale: Locale): Board {
  const currentUser = getSessionUser()
  const membership = api.members.find((member) => member.id === currentUser?.id)
  const canManage = Boolean(
    currentUser && (
      currentUser.role === 'Owner' ||
      currentUser.role === 'Admin' ||
      membership?.boardRole === 'Owner' ||
      membership?.boardRole === 'Admin'
    ),
  )

  return {
    id: api.id,
    title: api.title,
    updatedAt: api.updatedAt,
    updatedAtLabel: formatUpdatedAt(api.updatedAt, locale),
    starred: api.starred,
    memberIds: api.members.map(localUserId),
    noteColors: api.noteColors.filter(isNoteColor),
    isTemplate: api.isTemplate,
    canManage,
  }
}

export function toCommentUserId(user: Pick<ApiUser, 'id' | 'name'>): string {
  return localUserId(user)
}

/** Maps a persisted canvas element onto the sticky-note model. */
export function toStickyNote(element: ApiCanvasElement): StickyNote {
  return {
    id: element.id,
    text: element.content ?? '',
    authorId: localUserId(element.author),
    color: isNoteColor(element.color) ? element.color : 'amber',
    stroke: metadataValue(element.metadata, 'stroke', noteStrokes, 'none'),
    textSize: metadataValue(element.metadata, 'textSize', noteTextSizes, 'm'),
    align: metadataValue(element.metadata, 'align', noteAlignments, 'left'),
    x: element.x,
    y: element.y,
    width: element.width,
    height: element.height,
    rotation: element.rotation,
    mutationId: element.mutationId ?? undefined,
  }
}

const remoteIdPattern =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** True for IDs returned by the API, false for local demo IDs. */
export function isRemoteId(id: string): boolean {
  return remoteIdPattern.test(id)
}

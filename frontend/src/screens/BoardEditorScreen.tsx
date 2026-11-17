import {
  Check,
  ChevronLeft,
  History,
  Image as ImageIcon,
  Maximize2,
  MessageSquare,
  MousePointer2,
  PenLine,
  Search,
  Share2,
  Spline,
  Square,
  StickyNote,
  Type,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  boardCanvas,
  boards,
  commentThreads,
  currentUserId,
  users,
} from '@/mocks'
import type {
  CommentThread,
  NoteColor,
  NoteSaveState,
  StickyNote as StickyNoteModel,
} from '@/mocks'
import {
  BoardCanvas,
  CANVAS_HEIGHT,
  CANVAS_WIDTH,
} from '@/components/board/BoardCanvas'
import {
  noteClasses,
  noteColorLabelKeys,
  noteSwatches,
} from '@/components/board/colors'
import {
  PropertiesPanel,
  type NoteStylePatch,
} from '@/components/board/PropertiesPanel'
import { CommentsPanel } from '@/components/comments/CommentsPanel'
import { HeaderControls } from '@/components/layout/HeaderControls'
import { ShareDialog } from '@/components/share/ShareDialog'
import { AvatarStack } from '@/components/ui/avatar-stack'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Tooltip } from '@/components/ui/tooltip'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import { mergeSyncedNotes } from '@/lib/noteSync'
import {
  createCanvasElement,
  createComment,
  getBoard,
  getComments,
  type ApiBoard,
  getPresence,
  getSessionUser,
  toCommentThread,
  toLiveCursor,
  updatePresence,
  inviteBoardCollaborator,
  updateBoardMember,
  removeBoardMember,
  setBoardLinkAccess,
  replyToComment as replyToCommentRequest,
  resolveComment as resolveCommentRequest,
  getCanvasElements,
  isRemoteId,
  streamBoardEvents,
  toStickyNote,
  updateBoard,
  updateCanvasElement,
  warnFallback,
} from '@/api/client'

const tools = [
  {
    id: 'select',
    labelKey: 'editor.tool.select',
    icon: MousePointer2,
    shortcut: 'V',
  },
  { id: 'note', labelKey: 'editor.tool.note', icon: StickyNote, shortcut: 'N' },
  { id: 'comment', labelKey: 'editor.tool.comment', icon: MessageSquare, shortcut: 'M' },
  { id: 'shape', labelKey: 'editor.tool.shape', icon: Square, shortcut: 'S' },
  {
    id: 'connector',
    labelKey: 'editor.tool.connector',
    icon: Spline,
    shortcut: 'C',
  },
  { id: 'text', labelKey: 'editor.tool.text', icon: Type, shortcut: 'T' },
  { id: 'pen', labelKey: 'editor.tool.pen', icon: PenLine, shortcut: 'P' },
  {
    id: 'image',
    labelKey: 'editor.tool.image',
    icon: ImageIcon,
    shortcut: 'I',
  },
] as const

type ToolId = (typeof tools)[number]['id']

function clockTime(locale: string) {
  const tag =
    locale === 'ar' ? 'ar-TN' : locale === 'en' ? 'en-GB' : 'fr-FR'
  return new Date().toLocaleTimeString(tag, {
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function BoardEditorScreen({
  onOpenPalette,
}: {
  onOpenPalette: () => void
}) {
  const { boardId = 'b1' } = useParams()
  const { toast } = useToast()
  const { t, locale } = useI18n()
  const board = boards.find((item) => item.id === boardId)
  const [title, setTitle] = useState(board?.title ?? t('editor.untitled'))
  const [savedTitle, setSavedTitle] = useState(board?.title ?? t('editor.untitled'))
  const [titleSaving, setTitleSaving] = useState(false)
  const [commentDraft, setCommentDraft] = useState<{ x: number; y: number; body: string } | null>(null)
  const [activeTool, setActiveTool] = useState<ToolId>('select')
  const [zoom, setZoom] = useState(0.8)
  const [activeColor, setActiveColor] = useState<NoteColor>('amber')
  const [shareOpen, setShareOpen] = useState(false)
  const [commentsOpen, setCommentsOpen] = useState(false)
  const [activeThreadId, setActiveThreadId] = useState<string | null>(null)
  const [threads, setThreads] = useState<CommentThread[]>(commentThreads)
  const [liveCursors, setLiveCursors] = useState(boardCanvas.cursors)
  const [notes, setNotes] = useState<StickyNoteModel[]>(
    () => boardCanvas.notes,
  )
  const [selectedNoteId, setSelectedNoteId] = useState<string | null>(
    boardCanvas.notes[0]?.id ?? null,
  )
  const [apiReady, setApiReady] = useState(false)
  const [syncStatus, setSyncStatus] = useState<'live' | 'syncing' | 'offline'>('syncing')
  const [remoteBoard, setRemoteBoard] = useState<ApiBoard | null>(null)
  const [apiAuthorId, setApiAuthorId] = useState<string | null>(null)
  const canvasAreaRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    let cancelled = false

    async function loadCanvas() {
      // Optional panels must not block the stream/poll when the single-process
      // dev server is busy handling StrictMode's duplicate startup requests.
      const [boardResult, elementsResult, threadsResult, presenceResult] = await Promise.allSettled([
        getBoard(boardId),
        getCanvasElements(boardId),
        getComments(boardId),
        getPresence(boardId),
      ])
      if (cancelled) return

      if (boardResult.status === 'fulfilled') {
        const loadedBoard = boardResult.value
        setRemoteBoard(loadedBoard)
        setTitle(loadedBoard.title)
        setSavedTitle(loadedBoard.title)
        setApiAuthorId(getSessionUser()?.id ?? null)
      } else {
        warnFallback('Canvas API', boardResult.reason)
      }

      if (elementsResult.status === 'fulfilled') {
        const remoteNotes = elementsResult.value
          .filter((element) => element.type === 'note')
          .map(toStickyNote)
        setNotes(remoteNotes)
        setSelectedNoteId(remoteNotes[0]?.id ?? null)
      } else {
        warnFallback('Canvas elements', elementsResult.reason)
      }
      if (threadsResult.status === 'fulfilled') setThreads(threadsResult.value.map(toCommentThread))
      if (presenceResult.status === 'fulfilled') setLiveCursors(presenceResult.value.map(toLiveCursor))

      if (boardResult.status === 'fulfilled' || elementsResult.status === 'fulfilled') {
        setApiReady(true)
      }
    }

    void loadCanvas()
    return () => {
      cancelled = true
    }
  }, [boardId])

  useEffect(() => {
    if (!apiReady) return
    let cancelled = false
    let lastPublishedAt = 0
    let pendingPresence: { x: number; y: number } | null = null
    let publishTimer = 0
    const area = canvasAreaRef.current
    if (!area) return
    const publish = (event: PointerEvent) => {
      const bounds = area.getBoundingClientRect()
      const x = (event.clientX - bounds.left) / zoom
      const y = (event.clientY - bounds.top) / zoom
      pendingPresence = { x, y }
      const remaining = 250 - (Date.now() - lastPublishedAt)
      if (remaining > 0) {
        if (!publishTimer) publishTimer = window.setTimeout(flushPresence, remaining)
        return
      }
      flushPresence()
    }
    function flushPresence() {
      publishTimer = 0
      const position = pendingPresence
      pendingPresence = null
      if (!position) return
      lastPublishedAt = Date.now()
      void updatePresence(boardId, position.x, position.y, activeTool).catch(() => undefined)
    }
    const poll = window.setInterval(() => {
      void Promise.all([getPresence(boardId), getCanvasElements(boardId)]).then(([items, elements]) => {
        if (cancelled) return
        setLiveCursors(items.map(toLiveCursor))
        const synced = elements.filter((element) => element.type === 'note').map(toStickyNote)
        setNotes((current) => mergeSyncedNotes(current, synced))
      }).catch(() => undefined)
    }, 3000)
    area.addEventListener('pointermove', publish, { passive: true })
    return () => {
      cancelled = true
      window.clearInterval(poll)
      window.clearTimeout(publishTimer)
      area.removeEventListener('pointermove', publish)
    }
  }, [apiReady, boardId, activeTool, zoom])

  useEffect(() => {
    if (!apiReady) return
    let cancelled = false
    let timer = 0
    let revision = ''
    let controller = new AbortController()

    async function connect() {
      controller = new AbortController()
      try {
        await streamBoardEvents(boardId, (event) => {
          if (cancelled) return
          setSyncStatus('live')
          if (event.presence) setLiveCursors(event.presence.map(toLiveCursor))

          if (event.revision && event.revision !== revision) {
            revision = event.revision
            void getCanvasElements(boardId).then((elements) => {
              if (cancelled) return
              const synced = elements.filter((element) => element.type === 'note').map(toStickyNote)
              setNotes((current) => mergeSyncedNotes(current, synced))
            }).catch(() => undefined)
          }
        }, controller.signal)
      } catch {
        if (!cancelled) setSyncStatus(navigator.onLine ? 'syncing' : 'offline')
      }
      // The server closes each short stream after one frame; reconnect to keep ~500 ms updates.
      if (!cancelled) timer = window.setTimeout(() => void connect(), 500)
    }

    void connect()
    return () => {
      cancelled = true
      controller.abort()
      window.clearTimeout(timer)
    }
  }, [apiReady, boardId])

  const selectedIndex = selectedNoteId
    ? notes.findIndex((note) => note.id === selectedNoteId)
    : -1
  const selectedNote = selectedIndex >= 0 ? notes[selectedIndex] : null

  function updateSelectedNote(patch: NoteStylePatch) {
    if (!selectedNoteId) return
    setNotes((current) =>
      current.map((note) =>
        note.id === selectedNoteId ? { ...note, ...patch } : note,
      ),
    )

    if (patch.color) setActiveColor(patch.color)

    if (apiReady && isRemoteId(selectedNoteId) && patch.color) {
      void updateCanvasElement(selectedNoteId, { color: patch.color }).catch(
        (error) => warnFallback('Note style', error),
      )
    }
  }

  function moveNote(id: string, position: { x: number; y: number }) {
    setNotes((current) =>
      current.map((note) =>
        note.id === id ? { ...note, ...position } : note,
      ),
    )

    if (apiReady && isRemoteId(id)) {
      void updateCanvasElement(id, position).catch((error) =>
        warnFallback('Note move', error),
      )
    }
  }

  function editNote(id: string, text: string) {
    setNotes((current) =>
      current.map((note) => (note.id === id ? { ...note, text } : note)),
    )

    if (apiReady && isRemoteId(id)) {
      void updateCanvasElement(id, { content: text }).catch((error) =>
        warnFallback('Note text', error),
      )
    }
  }

  function setNoteSaveState(id: string, saveState: NoteSaveState) {
    setNotes((current) =>
      current.map((note) => (note.id === id ? { ...note, saveState } : note)),
    )
  }

  /**
   * Persists a local note. A failed request keeps the note and its content but
   * marks it as unsaved so it is never mistaken for a server record; retrying
   * reuses the same local id and therefore never creates a second server note.
   */
  async function persistNote(id: string, note: StickyNoteModel) {
    if (!apiAuthorId) return

    setNoteSaveState(id, 'saving')
    try {
      const created = await createCanvasElement(boardId, {
        authorId: apiAuthorId,
        type: 'note',
        x: note.x,
        y: note.y,
        width: note.width,
        height: note.height,
        rotation: note.rotation,
        content: note.text,
        color: note.color,
        metadata: {
          stroke: note.stroke,
          textSize: note.textSize,
          align: note.align,
        },
      })
      const saved: StickyNoteModel = {
        ...toStickyNote(created),
        saveState: 'saved',
      }
      setNotes((current) =>
        current.map((item) => (item.id === id ? saved : item)),
      )
      setSelectedNoteId((current) => (current === id ? saved.id : current))
    } catch (error) {
      warnFallback('Canvas save', error)
      setNoteSaveState(id, 'failed')
      toast({ title: t('editor.noteSaveFailed') })
    }
  }

  function retryNote(id: string) {
    const note = notes.find((item) => item.id === id)
    if (!note || !apiReady || !apiAuthorId || note.saveState === 'saving') return

    void persistNote(id, note)
  }

  async function createNoteAt(position: { x: number; y: number }) {
    const width = 220
    const height = 140
    const id = `local-${Date.now()}`
    const canPersist = apiReady && Boolean(apiAuthorId)
    const note: StickyNoteModel = {
      id,
      text: t('editor.newNote'),
      authorId: currentUserId,
      color: activeColor,
      stroke: 'none',
      textSize: 'm',
      align: 'left',
      x: Math.min(
        Math.max(position.x - width / 2, 0),
        CANVAS_WIDTH - width,
      ),
      y: Math.min(
        Math.max(position.y - height / 2, 0),
        CANVAS_HEIGHT - height,
      ),
      width,
      height,
      rotation: 0,
      saveState: canPersist ? 'saving' : undefined,
    }

    setNotes((current) => [...current, note])
    setSelectedNoteId(id)
    setActiveTool('select')

    // Demo boards have no API record to persist: the note stays local without
    // pretending to be saved.
    if (!canPersist) return

    await persistNote(id, note)
  }

  function fitCanvas() {
    const area = canvasAreaRef.current
    if (!area) return

    const padding = 48
    const scale = Math.min(
      (area.clientWidth - padding) / CANVAS_WIDTH,
      (area.clientHeight - padding) / CANVAS_HEIGHT,
    )
    setZoom(Math.min(1.6, Math.max(0.2, Math.round(scale * 100) / 100)))
  }

  function moveSelectedLayer(direction: 'up' | 'down') {
    if (selectedIndex < 0) return
    const target =
      direction === 'up' ? selectedIndex + 1 : selectedIndex - 1
    if (target < 0 || target >= notes.length) return

    setNotes((current) => {
      const next = [...current]
      const [moved] = next.splice(selectedIndex, 1)
      if (!moved) return current
      next.splice(target, 0, moved)
      return next
    })
  }

  const presence = liveCursors.map((cursor) => ({
    id: cursor.userId,
    name: cursor.name ?? users.find((user) => user.id === cursor.userId)?.name ?? t('common.guest'),
    accent: cursor.accent ?? users.find((user) => user.id === cursor.userId)?.accent ?? 'cursor-1',
  }))

  const openThreads = threads.filter((thread) => !thread.resolved).length

  function resolveThread(id: string, resolved: boolean) {
    setThreads((current) => current.map((thread) => thread.id === id ? { ...thread, resolved } : thread))
    if (apiReady && isRemoteId(id)) {
      void resolveCommentRequest(id, resolved).then((updated) => {
        setThreads((current) => current.map((thread) => thread.id === id ? toCommentThread(updated) : thread))
      }).catch((error) => warnFallback('Comment resolve', error))
    }
    toast({ title: resolved ? t('editor.resolvedToast') : t('editor.reopenedToast') })
  }

  function replyToThread(id: string, body: string) {
    setThreads((current) => current.map((thread) => thread.id === id ? {
      ...thread,
      messages: [...thread.messages, { id: `m-${Date.now()}`, authorId: currentUserId, body, at: clockTime(locale) }],
    } : thread))
    if (apiReady && isRemoteId(id)) {
      void replyToCommentRequest(id, body).catch((error) => warnFallback('Comment reply', error))
    }
  }

  function addCommentAt(x: number, y: number) {
    if (!apiReady) {
      toast({ title: t('comments.offline') })
      return
    }
    setCommentDraft({ x, y, body: '' })
  }

  async function commitTitle() {
    const next = title.trim()
    if (!next) {
      setTitle(savedTitle)
      toast({ title: t('editor.titleRequired') })
      return
    }
    if (next === savedTitle) {
      setTitle(next)
      return
    }
    if (!remoteBoard) {
      // Demo boards are not persisted; keep the label without claiming a save.
      setTitle(next)
      return
    }

    setTitleSaving(true)
    try {
      const updated = await updateBoard(remoteBoard.id, { title: next })
      setRemoteBoard(updated)
      setTitle(updated.title)
      setSavedTitle(updated.title)
      toast({ title: t('editor.titleSaved') })
    } catch (error) {
      warnFallback('Board rename', error)
      setTitle(savedTitle)
      toast({ title: t('editor.titleSaveFailed') })
    } finally {
      setTitleSaving(false)
    }
  }

  async function submitComment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!commentDraft) return
    const body = commentDraft.body.trim()
    if (!body) return

    try {
      const thread = await createComment(boardId, commentDraft.x, commentDraft.y, body)
      setThreads((current) => [...current, toCommentThread(thread)])
      setActiveThreadId(thread.id)
      setCommentsOpen(true)
      setCommentDraft(null)
    } catch (error) {
      warnFallback('Comment create', error)
      toast({ title: t('comments.createFailed') })
    }
  }

  return (
    <div className="flex h-svh flex-col bg-background">
      <header className="flex h-16 shrink-0 items-center gap-2 border-b border-border bg-surface px-3">
        <Tooltip label={t('editor.back')}>
          <Button asChild variant="ghost" size="icon">
            <Link to="/" aria-label={t('editor.back')}>
              <ChevronLeft className="size-5" aria-hidden="true" />
            </Link>
          </Button>
        </Tooltip>

        <div className="flex min-w-0 items-center gap-2">
          <input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            onBlur={() => void commitTitle()}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                event.currentTarget.blur()
              }
            }}
            aria-label={t('editor.titleAria')}
            className="h-9 w-40 truncate rounded-lg border border-transparent bg-transparent px-2 text-sm font-semibold transition-colors hover:border-border focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/25 focus-visible:outline-none sm:w-72"
          />
          <span
            className="hidden items-center gap-1 text-caption text-muted-foreground sm:flex"
            aria-live="polite"
          >
            {titleSaving ? (
              t('editor.saving')
            ) : title.trim() === savedTitle ? (
              <>
                <Check className="size-3.5 text-success" aria-hidden="true" />
                {t('editor.saved')}
              </>
            ) : (
              t('editor.unsaved')
            )}
          </span>
          <Badge
            variant={syncStatus === 'live' ? 'success' : syncStatus === 'syncing' ? 'warning' : 'neutral'}
            aria-live="polite"
            className="hidden sm:inline-flex"
          >
            <span
              className={cn(
                'size-1.5 rounded-full',
                syncStatus === 'live' ? 'bg-success' : syncStatus === 'syncing' ? 'bg-warning' : 'bg-muted-foreground',
              )}
              aria-hidden="true"
            />
            {t(`editor.${syncStatus}`)}
          </Badge>
        </div>

        <div className="ml-auto flex items-center gap-1.5">
          <Tooltip label={t('editor.palette')} shortcut="Ctrl K">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={onOpenPalette}
              aria-label={t('editor.openPalette')}
            >
              <Search className="size-4.5" aria-hidden="true" />
            </Button>
          </Tooltip>

          <Button asChild variant="ghost" size="sm" className="hidden sm:inline-flex">
            <Link to={`/board/${boardId}/replay`}>
              <History className="size-4" aria-hidden="true" />
              {t('editor.replay')}
            </Link>
          </Button>

          <Button
            type="button"
            variant={commentsOpen ? 'secondary' : 'ghost'}
            size="sm"
            onClick={() => setCommentsOpen((open) => !open)}
            aria-pressed={commentsOpen}
          >
            <MessageSquare className="size-4" aria-hidden="true" />
            <span className="hidden sm:inline">{t('editor.comments')}</span>
            <Badge variant="primary" className="ml-0.5">
              {openThreads}
            </Badge>
          </Button>

          <HeaderControls />

          <AvatarStack
            people={presence}
            size="md"
            className="hidden gap-0.5 pl-1 md:flex"
          />

          <Button
            type="button"
            size="sm"
            onClick={() => setShareOpen(true)}
            className="ml-1"
          >
            <Share2 className="size-4" aria-hidden="true" />
            <span className="hidden sm:inline">{t('editor.share')}</span>
          </Button>
        </div>
      </header>

      <div className="flex min-h-0 flex-1">
        <div
          className="flex w-14 shrink-0 flex-col items-center gap-1 overflow-y-auto border-r border-border bg-surface py-3"
          role="toolbar"
          aria-label={t('editor.toolbar')}
          aria-orientation="vertical"
        >
          {tools.map((tool) => {
            const Icon = tool.icon
            const active = activeTool === tool.id
            return (
              <Tooltip
                key={tool.id}
                label={t(tool.labelKey)}
                shortcut={tool.shortcut}
                side="right"
              >
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={t(tool.labelKey)}
                  aria-pressed={active}
                  onClick={() => setActiveTool(tool.id)}
                  className={cn(
                    active &&
                      'bg-primary-soft text-primary-soft-foreground hover:bg-primary-soft hover:text-primary-soft-foreground',
                  )}
                >
                  <Icon className="size-4.5" aria-hidden="true" />
                </Button>
              </Tooltip>
            )
          })}
        </div>

        <div ref={canvasAreaRef} className="relative min-w-0 flex-1">
          <BoardCanvas
            notes={notes}
            connectors={boardCanvas.connectors}
            cursors={liveCursors}
            frame={boardCanvas.frame}
            zoom={zoom}
            tool={activeTool === 'note' ? 'note' : activeTool === 'comment' ? 'comment' : 'select'}
            onCreateComment={addCommentAt}
            selectedNoteId={selectedNoteId}
            onSelectNote={(id) => {
              setSelectedNoteId(id)
              if (id) setActiveTool('select')
            }}
            onCreateNote={(position) => void createNoteAt(position)}
            onRetryNote={retryNote}
            onMoveNote={moveNote}
            onEditNote={editNote}
          >
            {threads.map((thread) => (
              <button
                key={thread.id}
                type="button"
                onClick={() => {
                  setActiveThreadId(thread.id)
                  setCommentsOpen(true)
                }}
                aria-label={t('editor.openThread', {
                  count: thread.messages.length,
                })}
                className={cn(
                  'absolute z-30 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center gap-0.5 rounded-full shadow-card transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                  thread.resolved
                    ? 'border border-border bg-surface text-muted-foreground'
                    : 'bg-primary text-primary-foreground',
                  activeThreadId === thread.id &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-canvas',
                )}
                style={{
                  left: thread.x + 24,
                  top: thread.y - 12,
                }}
              >
                <MessageSquare className="size-4" aria-hidden="true" />
                <span className="text-[10px] font-semibold">
                  {thread.messages.length}
                </span>
              </button>
            ))}
          </BoardCanvas>

          <div className="absolute bottom-4 left-4 hidden rounded-card border border-border bg-surface/95 p-2 shadow-card md:block">
            <div
              className="relative h-24 w-40 overflow-hidden rounded-lg border border-border bg-canvas"
              style={{
                backgroundImage:
                  'radial-gradient(var(--grid-dot) 1px, transparent 1px)',
                backgroundSize: '10px 10px',
              }}
              aria-hidden="true"
            >
              {notes.map((note) => (
                <span
                  key={note.id}
                  className={cn(
                    'absolute rounded-[2px] opacity-80',
                    noteClasses[note.color].surface,
                  )}
                  style={{
                    left: `${(note.x / 1400) * 100}%`,
                    top: `${(note.y / 880) * 100}%`,
                    width: `${(note.width / 1400) * 100}%`,
                    height: `${(note.height / 880) * 100}%`,
                  }}
                />
              ))}
            </div>
            <p className="mt-1 text-center text-[10px] text-muted-foreground">
              {t('editor.overview')}
            </p>
          </div>

          <div className="absolute right-4 bottom-4 flex items-center gap-1 rounded-card border border-border bg-surface p-1 shadow-card">
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={t('editor.zoomOut')}
              onClick={() =>
                setZoom((current) =>
                  Math.max(0.4, Math.round((current - 0.1) * 10) / 10),
                )
              }
            >
              <ZoomOut className="size-4" aria-hidden="true" />
            </Button>
            <span className="w-12 text-center text-caption tabular">
              {Math.round(zoom * 100)} %
            </span>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={t('editor.zoomIn')}
              onClick={() =>
                setZoom((current) =>
                  Math.min(1.6, Math.round((current + 0.1) * 10) / 10),
                )
              }
            >
              <ZoomIn className="size-4" aria-hidden="true" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={t('editor.zoomFit')}
              onClick={fitCanvas}
            >
              <Maximize2 className="size-4" aria-hidden="true" />
            </Button>
          </div>

          <div
            className="absolute right-4 bottom-16 flex items-center gap-1.5 rounded-card border border-border bg-surface p-1.5 shadow-card"
            role="group"
            aria-label={t('properties.fill')}
          >
            {noteSwatches.map((swatch) => (
              <button
                key={swatch.color}
                type="button"
                onClick={() => {
                  setActiveColor(swatch.color)
                  if (selectedNoteId) {
                    updateSelectedNote({ color: swatch.color })
                  }
                }}
                aria-label={t('properties.color', {
                  color: t(noteColorLabelKeys[swatch.color]),
                })}
                aria-pressed={activeColor === swatch.color}
                className={cn(
                  'size-6 rounded-md border border-border/60 transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                  noteClasses[swatch.color].surface,
                  activeColor === swatch.color &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-surface',
                )}
              />
            ))}
          </div>

          <p className="pointer-events-none absolute bottom-5 left-1/2 hidden -translate-x-1/2 items-center gap-2 rounded-full border border-border bg-surface/95 px-3 py-1.5 text-[11px] text-muted-foreground shadow-card lg:flex">
            <kbd className="rounded border border-border bg-muted px-1">
              {t('editor.space')}
            </kbd>
            {t('editor.moveHint')} ·
            <kbd className="rounded border border-border bg-muted px-1">
              Ctrl K
            </kbd>
            {t('editor.commandsHint')}
          </p>
        </div>

        {commentsOpen ? (
          <CommentsPanel
            threads={threads}
            activeThreadId={activeThreadId}
            onSelectThread={setActiveThreadId}
            onResolve={resolveThread}
            onReply={replyToThread}
            onClose={() => setCommentsOpen(false)}
          />
        ) : (
          <PropertiesPanel
            note={selectedNote}
            onChange={updateSelectedNote}
            onMoveLayer={moveSelectedLayer}
            canMoveUp={selectedIndex >= 0 && selectedIndex < notes.length - 1}
            canMoveDown={selectedIndex > 0}
          />
        )}
      </div>

      <ShareDialog
        open={shareOpen}
        onOpenChange={setShareOpen}
        boardId={boardId}
        boardTitle={title}
        memberIds={board?.memberIds ?? [currentUserId]}
        members={remoteBoard?.members ?? []}
        publicAccess={remoteBoard?.publicAccess ?? false}
        onInvite={(email, role) => inviteBoardCollaborator(boardId, email, role)}
        onRoleChange={(userId, role) => updateBoardMember(boardId, userId, role).then(setRemoteBoard)}
        onRemove={(userId) => removeBoardMember(boardId, userId).then(() => setRemoteBoard((current) => current ? { ...current, members: current.members.filter((member) => member.id !== userId) } : current))}
        onPublicAccessChange={(enabled) => setBoardLinkAccess(boardId, enabled).then(setRemoteBoard)}
      />

      <Dialog
        open={Boolean(commentDraft)}
        onOpenChange={(open) => {
          if (!open) setCommentDraft(null)
        }}
      >
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{t('comments.new')}</DialogTitle>
            <DialogDescription>{t('comments.newBody')}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitComment} className="flex flex-col gap-3 px-6 pb-2">
            <Textarea
              autoFocus
              value={commentDraft?.body ?? ''}
              onChange={(event) =>
                setCommentDraft((current) =>
                  current ? { ...current, body: event.target.value } : current,
                )
              }
              aria-label={t('comments.newPrompt')}
              className="min-h-24"
            />
            <DialogFooter className="px-0">
              <Button
                type="button"
                variant="secondary"
                onClick={() => setCommentDraft(null)}
              >
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={!commentDraft?.body.trim()}>
                {t('comments.send')}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}

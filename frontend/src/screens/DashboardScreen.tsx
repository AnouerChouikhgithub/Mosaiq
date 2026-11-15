import {
  Archive,
  Copy,
  LayoutGrid,
  MoreHorizontal,
  Plus,
  RotateCcw,
  Star,
  Trash2,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  boards as initialBoards,
  currentUserId,
  templates,
  users,
  workspace,
} from '@/mocks'
import type { Board, BoardTemplate } from '@/mocks'
import { BoardThumbnail } from '@/components/board/BoardThumbnail'
import { noteClasses } from '@/components/board/colors'
import { AppShell, type WorkspaceView } from '@/components/layout/AppShell'
import { AvatarStack } from '@/components/ui/avatar-stack'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { EmptyState } from '@/components/ui/empty-state'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import {
  archiveBoard,
  createBoard as createBoardRequest,
  getBoards,
  getSessionWorkspace,
  restoreBoardLifecycle,
  toBoard,
  toggleStarBoard,
  trashBoard,
  warnFallback,
  type ApiBoardStatus,
} from '@/api/client'

const userById = new Map(users.map((user) => [user.id, user]))

function membersOf(board: Board) {
  return board.memberIds
    .map((id) => userById.get(id))
    .filter((user) => user !== undefined)
}

type LifecycleAction = 'archive' | 'trash'
type BoardView = 'boards' | 'archive' | 'trash'

function BoardCard({
  board,
  view,
  onToggleStar,
  onArchive,
  onTrash,
  onRestore,
}: {
  board: Board
  view: BoardView
  onToggleStar: (id: string) => void
  onArchive: (board: Board) => void
  onTrash: (board: Board) => void
  onRestore: (board: Board) => void
}) {
  const { toast } = useToast()
  const { t } = useI18n()
  const members = membersOf(board)
  const linkable = view !== 'trash'
  const canManage = Boolean(board.canManage)

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/share/${encodeURIComponent(board.id)}`)
      toast({ title: t('dashboard.copyToast') })
    } catch {
      toast({
        title: t('common.copyFailed'),
        description: t('common.clipboardBlocked'),
      })
    }
  }

  const thumbnail = (
    <BoardThumbnail noteColors={board.noteColors} className="h-32 w-full rounded-none border-0" />
  )

  return (
    <Card className="group overflow-hidden p-0 transition-colors hover:border-border-strong">
      {linkable ? (
        <Link
          to={`/board/${board.id}`}
          className="block rounded-t-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          aria-label={t('dashboard.openAria', { title: board.title })}
        >
          {thumbnail}
        </Link>
      ) : (
        <div className="rounded-t-card" aria-hidden="true">{thumbnail}</div>
      )}
      <div className="flex flex-col gap-3 p-4">
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            {linkable ? (
              <Link
                to={`/board/${board.id}`}
                className="block truncate text-sm font-semibold text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              >
                {board.title}
              </Link>
            ) : (
              <p className="truncate text-sm font-semibold text-foreground">{board.title}</p>
            )}
            <p className="mt-0.5 text-caption text-muted-foreground">
              {t('dashboard.updated', {
                when: board.updatedAtLabel.toLowerCase(),
              })}
            </p>
          </div>
          {view === 'boards' ? (
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={
                board.starred
                  ? t('dashboard.starRemove', { title: board.title })
                  : t('dashboard.starAdd', { title: board.title })
              }
              aria-pressed={board.starred}
              onClick={() => onToggleStar(board.id)}
            >
              <Star
                className={cn(
                  'size-4',
                  board.starred ? 'fill-primary text-primary' : undefined,
                )}
                aria-hidden="true"
              />
            </Button>
          ) : null}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={t('dashboard.actions', { title: board.title })}
              >
                <MoreHorizontal className="size-4" aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {linkable ? (
                <DropdownMenuItem asChild>
                  <Link to={`/board/${board.id}`}>{t('dashboard.open')}</Link>
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuItem onSelect={copyLink}>
                <Copy className="size-4" aria-hidden="true" />
                {t('dashboard.copyLink')}
              </DropdownMenuItem>
              {view === 'boards' && canManage ? (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onSelect={() => onArchive(board)}>
                    <Archive className="size-4" aria-hidden="true" />
                    {t('dashboard.archive')}
                  </DropdownMenuItem>
                  <DropdownMenuItem onSelect={() => onTrash(board)}>
                    <Trash2 className="size-4" aria-hidden="true" />
                    {t('dashboard.trash')}
                  </DropdownMenuItem>
                </>
              ) : null}
              {view !== 'boards' && canManage ? (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onSelect={() => onRestore(board)}>
                    <RotateCcw className="size-4" aria-hidden="true" />
                    {t('dashboard.restore')}
                  </DropdownMenuItem>
                </>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        <div className="flex items-center justify-between gap-2">
          <AvatarStack
            people={members.map((member) => ({
              id: member.id,
              name: member.name,
              accent: member.accent,
            }))}
            max={3}
          />
          <span className="text-[11px] text-muted-foreground">
            {t('dashboard.collaborators', {
              count: board.memberIds.length,
            })}
          </span>
        </div>
      </div>
    </Card>
  )
}

function TemplateCard({
  template,
  onUse,
}: {
  template: BoardTemplate
  onUse: (template: BoardTemplate) => void
}) {
  const { t } = useI18n()

  return (
    <button
      type="button"
      onClick={() => onUse(template)}
      className="flex h-full flex-col items-start gap-2 rounded-card border border-border bg-surface p-4 text-left transition-colors hover:border-primary/40 hover:bg-primary-soft/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
      <span className="flex gap-1" aria-hidden="true">
        {template.noteColors.map((color, index) => (
          <span
            key={`${color}-${index}`}
            className={cn(
              'size-4 rounded-[3px] border border-border/50',
              noteClasses[color].surface,
            )}
          />
        ))}
      </span>
      <span className="text-sm font-semibold">{template.title}</span>
      <span className="text-caption text-muted-foreground">
        {template.description}
      </span>
      <span className="mt-auto pt-2 text-caption font-medium text-primary-soft-foreground">
        {t('dashboard.useTemplate')}
      </span>
    </button>
  )
}

export function DashboardScreen({ onOpenPalette }: { onOpenPalette: () => void }) {
  const navigate = useNavigate()
  const { toast } = useToast()
  const { t, locale } = useI18n()
  const [searchParams] = useSearchParams()
  const [boardList, setBoardList] = useState<Board[]>(initialBoards)
  const [loadedView, setLoadedView] = useState<WorkspaceView>('boards')
  const [loadError, setLoadError] = useState<string | undefined>()
  const [reloadKey, setReloadKey] = useState(0)
  const [lifecycleTarget, setLifecycleTarget] = useState<{ board: Board; action: LifecycleAction } | null>(null)
  const [lifecyclePending, setLifecyclePending] = useState(false)

  const requestedView = searchParams.get('view')
  const view: WorkspaceView =
    requestedView === 'archive' || requestedView === 'templates' || requestedView === 'trash'
      ? requestedView
      : 'boards'
  const boardView: BoardView = view === 'archive' || view === 'trash' ? view : 'boards'
  const status: ApiBoardStatus = boardView === 'archive' ? 'archived' : boardView === 'trash' ? 'trashed' : 'active'

  useEffect(() => {
    if (view === 'templates') return
    let cancelled = false

    getBoards(undefined, status)
      .then((remoteBoards) => {
        if (cancelled) return
        setBoardList(remoteBoards.map((board) => toBoard(board, locale)))
        setLoadedView(view)
        setLoadError(undefined)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        if (view === 'boards') {
          warnFallback('Boards API', error)
          setBoardList(initialBoards)
        } else {
          setBoardList([])
          setLoadError(error instanceof Error ? error.message : t('dashboard.loadFailed'))
        }
        setLoadedView(view)
      })

    return () => {
      cancelled = true
    }
  }, [locale, reloadKey, status, t, view])

  const visibleBoards = useMemo(
    () => (loadedView === view ? boardList : []),
    [boardList, loadedView, view],
  )
  const pendingView = loadedView !== view

  const starred = useMemo(
    () => visibleBoards.filter((board) => board.starred),
    [visibleBoards],
  )

  async function createBoard(title = t('dashboard.titleUntitled')) {
    const id = `b-${Date.now()}`
    const newBoard: Board = {
      id,
      title,
      updatedAt: new Date().toISOString(),
      updatedAtLabel: t('dashboard.justNow'),
      starred: false,
      memberIds: [currentUserId],
      noteColors: ['amber', 'sky', 'mint'],
    }

    try {
      const created = toBoard(
        await createBoardRequest(title),
        locale,
      )
      setBoardList((current) => [created, ...current])
      setLoadedView('boards')
      navigate(`/board/${created.id}`)
    } catch (error) {
      warnFallback('Board creation', error)
      setBoardList((current) => [newBoard, ...current])
      setLoadedView('boards')
      navigate(`/board/${id}`)
    }
  }

  function toggleStar(id: string) {
    setBoardList((current) =>
      current.map((item) =>
        item.id === id ? { ...item, starred: !item.starred } : item,
      ),
    )

    if (!/^[0-9a-f]{8}-/i.test(id)) return

    void toggleStarBoard(id)
      .then((updated) => {
        setBoardList((current) =>
          current.map((item) =>
            item.id === updated.id
              ? { ...item, starred: updated.starred }
              : item,
          ),
        )
      })
      .catch((error) => warnFallback('Star API', error))
  }

  async function restore(board: Board) {
    try {
      await restoreBoardLifecycle(board.id)
      toast({ title: t('dashboard.restoreToast') })
      setReloadKey((key) => key + 1)
    } catch (error) {
      toast({
        title: t('dashboard.lifecycleFailed'),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }

  async function confirmLifecycle() {
    if (!lifecycleTarget) return
    setLifecyclePending(true)
    try {
      if (lifecycleTarget.action === 'archive') {
        await archiveBoard(lifecycleTarget.board.id)
        toast({ title: t('dashboard.archiveToast') })
      } else {
        await trashBoard(lifecycleTarget.board.id)
        toast({ title: t('dashboard.trashToast') })
      }
      setLifecycleTarget(null)
      setReloadKey((key) => key + 1)
    } catch (error) {
      toast({
        title: t('dashboard.lifecycleFailed'),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setLifecyclePending(false)
    }
  }

  const title =
    view === 'templates'
      ? t('dashboard.title.templates')
      : view === 'archive'
        ? t('dashboard.title.archive')
        : view === 'trash'
          ? t('dashboard.title.trash')
          : t('dashboard.title.boards')
  const description =
    view === 'templates'
      ? t('dashboard.desc.templates')
      : view === 'archive'
        ? t('dashboard.desc.archive', { count: visibleBoards.length })
        : view === 'trash'
          ? t('dashboard.desc.trash', { count: visibleBoards.length })
          : t('dashboard.desc.boards', {
              workspace: getSessionWorkspace()?.name ?? workspace.name,
              count: visibleBoards.length,
            })

  const cardActions = {
    onArchive: (board: Board) => setLifecycleTarget({ board, action: 'archive' }),
    onTrash: (board: Board) => setLifecycleTarget({ board, action: 'trash' }),
    onRestore: (board: Board) => void restore(board),
  }

  function renderGrid(boards: Board[], targetView: BoardView) {
    return (
      <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {boards.map((board) => (
          <BoardCard
            key={board.id}
            board={board}
            view={targetView}
            onToggleStar={toggleStar}
            {...cardActions}
          />
        ))}
      </div>
    )
  }

  return (
    <AppShell
      activeView={view}
      title={title}
      description={description}
      onOpenPalette={onOpenPalette}
      actions={
        view === 'boards' ? (
          <Button type="button" onClick={() => createBoard()}>
            <Plus className="size-4" aria-hidden="true" />
            {t('dashboard.newBoard')}
          </Button>
        ) : null
      }
    >
      {view === 'boards' ? (
        <div className="mt-6 flex flex-col gap-8">
          {starred.length > 0 ? (
            <section aria-labelledby="starred-heading">
              <div className="flex items-center gap-2">
                <Star className="size-4 text-primary" aria-hidden="true" />
                <h2 id="starred-heading" className="text-title">
                  {t('dashboard.starred')}
                </h2>
                <Badge variant="neutral">{starred.length}</Badge>
              </div>
              {renderGrid(starred, 'boards')}
            </section>
          ) : null}

          <section aria-labelledby="recent-heading">
            <div className="flex items-center justify-between gap-2">
              <h2 id="recent-heading" className="text-title">
                {t('dashboard.recent')}
              </h2>
              <span className="text-caption text-muted-foreground">
                {t('dashboard.sorted')}
              </span>
            </div>
            {pendingView ? (
              <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
                <Skeleton className="h-56 w-full" />
                <Skeleton className="h-56 w-full" />
                <Skeleton className="h-56 w-full" />
              </div>
            ) : visibleBoards.length === 0 ? (
              <EmptyState
                icon={LayoutGrid}
                title={t('dashboard.empty.title')}
                description={t('dashboard.empty.description')}
                action={
                  <Button type="button" onClick={() => createBoard()}>
                    <Plus className="size-4" aria-hidden="true" />
                    {t('dashboard.newBoard')}
                  </Button>
                }
                className="mt-3"
              />
            ) : (
              renderGrid(visibleBoards, 'boards')
            )}
          </section>

          <section aria-labelledby="templates-heading">
            <div className="flex items-center justify-between gap-2">
              <h2 id="templates-heading" className="text-title">
                {t('dashboard.fromTemplate')}
              </h2>
              <Link
                to="/?view=templates"
                className="text-caption font-medium text-primary-soft-foreground underline-offset-4 hover:underline"
              >
                {t('dashboard.viewAll')}
              </Link>
            </div>
            <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {templates.map((template) => (
                <TemplateCard
                  key={template.id}
                  template={template}
                  onUse={(selected) => createBoard(selected.title)}
                />
              ))}
            </div>
          </section>
        </div>
      ) : null}

      {view === 'templates' ? (
        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {templates.map((template) => (
            <TemplateCard
              key={template.id}
              template={template}
              onUse={(selected) => createBoard(selected.title)}
            />
          ))}
        </div>
      ) : null}

      {view === 'archive' || view === 'trash' ? (
        pendingView ? (
          <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
            <Skeleton className="h-56 w-full" />
            <Skeleton className="h-56 w-full" />
            <Skeleton className="h-56 w-full" />
          </div>
        ) : loadError ? (
          <EmptyState
            icon={Archive}
            title={t('dashboard.loadFailed')}
            description={loadError}
            action={
              <Button type="button" variant="secondary" onClick={() => setReloadKey((key) => key + 1)}>
                {t('dashboard.retry')}
              </Button>
            }
            className="mt-6 bg-surface"
          />
        ) : visibleBoards.length === 0 ? (
          <EmptyState
            icon={view === 'archive' ? Archive : Trash2}
            title={view === 'archive' ? t('dashboard.emptyArchive') : t('dashboard.emptyTrash')}
            description={
              view === 'archive'
                ? t('dashboard.emptyArchiveDesc')
                : t('dashboard.emptyTrashDesc')
            }
            className="mt-6 bg-surface"
          />
        ) : (
          renderGrid(visibleBoards, view)
        )
      ) : null}

      <Dialog
        open={lifecycleTarget !== null}
        onOpenChange={(open) => {
          if (!open) setLifecycleTarget(null)
        }}
      >
        <DialogContent>
          {lifecycleTarget ? (
            <>
              <DialogHeader>
                <DialogTitle>
                  {t(
                    lifecycleTarget.action === 'archive'
                      ? 'dashboard.archiveTitle'
                      : 'dashboard.trashTitle',
                    { title: lifecycleTarget.board.title },
                  )}
                </DialogTitle>
                <DialogDescription>
                  {t(
                    lifecycleTarget.action === 'archive'
                      ? 'dashboard.archiveBody'
                      : 'dashboard.trashBody',
                    { title: lifecycleTarget.board.title },
                  )}
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" variant="secondary" onClick={() => setLifecycleTarget(null)}>
                  {t('common.cancel')}
                </Button>
                <Button
                  type="button"
                  variant={lifecycleTarget.action === 'trash' ? 'destructive' : 'primary'}
                  loading={lifecyclePending}
                  onClick={() => void confirmLifecycle()}
                >
                  {t(
                    lifecycleTarget.action === 'archive'
                      ? 'dashboard.archiveConfirm'
                      : 'dashboard.trashConfirm',
                  )}
                </Button>
              </DialogFooter>
            </>
          ) : null}
        </DialogContent>
      </Dialog>
    </AppShell>
  )
}

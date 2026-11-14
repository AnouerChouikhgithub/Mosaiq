import {
  Copy,
  LayoutGrid,
  MoreHorizontal,
  Plus,
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { EmptyState } from '@/components/ui/empty-state'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import {
  createBoard as createBoardRequest,
  getBoards,
  toBoard,
  toggleStarBoard,
  warnFallback,
} from '@/api/client'

const userById = new Map(users.map((user) => [user.id, user]))

function membersOf(board: Board) {
  return board.memberIds
    .map((id) => userById.get(id))
    .filter((user) => user !== undefined)
}

function BoardCard({
  board,
  onToggleStar,
  onDelete,
}: {
  board: Board
  onToggleStar: (id: string) => void
  onDelete: (id: string) => void
}) {
  const { toast } = useToast()
  const { t } = useI18n()
  const members = membersOf(board)

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

  return (
    <Card className="group overflow-hidden p-0 transition-colors hover:border-border-strong">
      <Link
        to={`/board/${board.id}`}
        className="block rounded-t-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        aria-label={t('dashboard.openAria', { title: board.title })}
      >
        <BoardThumbnail noteColors={board.noteColors} className="h-32 w-full rounded-none border-0" />
      </Link>
      <div className="flex flex-col gap-3 p-4">
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            <Link
              to={`/board/${board.id}`}
              className="block truncate text-sm font-semibold text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
              {board.title}
            </Link>
            <p className="mt-0.5 text-caption text-muted-foreground">
              {t('dashboard.updated', {
                when: board.updatedAtLabel.toLowerCase(),
              })}
            </p>
          </div>
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
              <DropdownMenuItem onSelect={() => undefined} asChild>
                <Link to={`/board/${board.id}`}>{t('dashboard.open')}</Link>
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={copyLink}>
                <Copy className="size-4" aria-hidden="true" />
                {t('dashboard.copyLink')}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onSelect={() => onDelete(board.id)}>
                <Trash2 className="size-4" aria-hidden="true" />
                {t('dashboard.trash')}
              </DropdownMenuItem>
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

  useEffect(() => {
    let cancelled = false

    async function loadBoards() {
      try {
        const remoteBoards = await getBoards()
        if (!cancelled) {
          setBoardList(remoteBoards.map((board) => toBoard(board, locale)))
        }
      } catch (error) {
        warnFallback('Boards API', error)
      }
    }

    void loadBoards()
    return () => {
      cancelled = true
    }
  }, [locale])

  const requestedView = searchParams.get('view')
  const view: WorkspaceView =
    requestedView === 'templates' || requestedView === 'trash'
      ? requestedView
      : 'boards'

  const starred = useMemo(
    () => boardList.filter((board) => board.starred),
    [boardList],
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
      navigate(`/board/${created.id}`)
    } catch (error) {
      warnFallback('Board creation', error)
      setBoardList((current) => [newBoard, ...current])
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

  const title =
    view === 'templates'
      ? t('dashboard.title.templates')
      : view === 'trash'
        ? t('dashboard.title.trash')
        : t('dashboard.title.boards')
  const description =
    view === 'templates'
      ? t('dashboard.desc.templates')
      : view === 'trash'
        ? t('dashboard.desc.trash')
        : t('dashboard.desc.boards', {
            workspace: workspace.name,
            count: boardList.length,
          })

  return (
    <AppShell
      activeView={view}
      title={title}
      description={description}
      onOpenPalette={onOpenPalette}
      actions={
        view === 'trash' ? null : (
          <Button type="button" onClick={() => createBoard()}>
            <Plus className="size-4" aria-hidden="true" />
            {t('dashboard.newBoard')}
          </Button>
        )
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
              <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {starred.map((board) => (
                  <BoardCard
                    key={board.id}
                    board={board}
                    onToggleStar={toggleStar}
                    onDelete={(id) => {
                      setBoardList((current) =>
                        current.filter((item) => item.id !== id),
                      )
                      toast({ title: t('dashboard.trashToast') })
                    }}
                  />
                ))}
              </div>
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
            {boardList.length === 0 ? (
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
              <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {boardList.map((board) => (
                  <BoardCard
                    key={board.id}
                    board={board}
                    onToggleStar={toggleStar}
                    onDelete={(id) => {
                      setBoardList((current) =>
                        current.filter((item) => item.id !== id),
                      )
                      toast({ title: t('dashboard.trashToast') })
                    }}
                  />
                ))}
              </div>
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

      {view === 'trash' ? (
        <EmptyState
          icon={Trash2}
          title={t('dashboard.emptyTrash')}
          description={t('dashboard.emptyTrashDesc')}
          className="mt-6 bg-surface"
        />
      ) : null}
    </AppShell>
  )
}

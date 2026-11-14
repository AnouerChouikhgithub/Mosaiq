import {
  ChevronLeft,
  Pause,
  Play,
  RotateCcw,
  SkipBack,
  Users,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { boardCanvas, boards, timeline, users } from '@/mocks'
import type { TimelineEntry } from '@/mocks'
import { BoardCanvas } from '@/components/board/BoardCanvas'
import { accentClasses } from '@/components/board/colors'
import { HeaderControls } from '@/components/layout/HeaderControls'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { SegmentedControl } from '@/components/ui/toggle-group'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import { getTimeline, restoreTimeline, toStickyNote, toTimelineEntry, type ApiTimelineEntry } from '@/api/client'

type Speed = '1' | '2' | '4'

const userById = new Map(users.map((user) => [user.id, user]))

const contributors = Array.from(
  new Set(timeline.map((entry) => entry.userId)),
)
  .map((id) => userById.get(id))
  .filter((user) => user !== undefined)

export function TimeTravelScreen() {
  const { boardId = 'b1' } = useParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const { t } = useI18n()
  const board = boards.find((item) => item.id === boardId)
  const [remoteTimeline, setRemoteTimeline] = useState<ApiTimelineEntry[]>([])
  const timelineEntries: TimelineEntry[] = remoteTimeline.length ? remoteTimeline.map(toTimelineEntry) : timeline
  const lastIndex = Math.max(0, timelineEntries.length - 1)

  const [progress, setProgress] = useState(0)
  const [playing, setPlaying] = useState(false)
  const [speed, setSpeed] = useState<Speed>('1')
  const [filter, setFilter] = useState<string>('all')

  const current = timelineEntries[progress] ?? timelineEntries[lastIndex]

  useEffect(() => {
    let cancelled = false
    void getTimeline(boardId).then((entries) => {
      if (!cancelled && entries.length) setRemoteTimeline(entries)
    }).catch(() => undefined)
    return () => { cancelled = true }
  }, [boardId])

  useEffect(() => {
    if (!playing || progress >= lastIndex) return
    const next = progress + 1
    const id = window.setTimeout(
      () => {
        setProgress(next)
        if (next >= lastIndex) setPlaying(false)
      },
      900 / Number(speed),
    )
    return () => window.clearTimeout(id)
  }, [playing, progress, speed, lastIndex])

  const visibleNotes = useMemo(() => {
    const revealed = new Set<string>()
    timelineEntries.slice(0, progress + 1).forEach((entry) => {
      if (filter !== 'all' && entry.userId !== filter) return
      entry.reveals?.forEach((noteId) => revealed.add(noteId))
    })
    const snapshot = remoteTimeline[progress]?.snapshot
    if (snapshot) return snapshot.filter((element) => element.type === 'note').map(toStickyNote)
    return boardCanvas.notes.filter((note) => revealed.has(note.id))
  }, [progress, filter, timelineEntries, remoteTimeline])

  const visibleCursors = boardCanvas.cursors.filter(
    (cursor) => filter === 'all' || cursor.userId === filter,
  )

  const frameVisible = timelineEntries
    .slice(0, progress + 1)
    .some((entry) => entry.kind === 'frame')

  const markers = timelineEntries.filter(
    (entry) => filter === 'all' || entry.userId === filter,
  )

  async function restore() {
    if (remoteTimeline[progress]) {
      try {
        await restoreTimeline(boardId, remoteTimeline[progress].id)
        toast({ title: t('replay.restored', { time: current.at }) })
        navigate(`/board/${boardId}`, { replace: true })
      } catch {
        toast({ title: t('replay.restoreFailed') })
      }
      return
    }
    toast({ title: t('replay.restored', { time: current.at }) })
  }

  return (
    <div className="flex h-svh flex-col bg-background">
      <header className="flex h-16 shrink-0 flex-wrap items-center gap-2 border-b border-border bg-surface px-3">
        <Button asChild variant="ghost" size="icon">
          <Link to={`/board/${boardId}`} aria-label={t('replay.exit')}>
            <ChevronLeft className="size-5" aria-hidden="true" />
          </Link>
        </Button>
        <Badge variant="primary">{t('replay.mode')}</Badge>
        <p className="min-w-0 flex-1 truncate text-sm font-semibold">
          {board?.title ?? 'Refonte du site e-commerce'}
        </p>
        <HeaderControls />
        <Button asChild variant="ghost" size="sm" className="hidden sm:inline-flex">
          <Link to={`/board/${boardId}`}>{t('replay.exit')}</Link>
        </Button>
        <Button type="button" size="sm" onClick={restore}>
          <RotateCcw className="size-4" aria-hidden="true" />
          {t('replay.restore')}
        </Button>
      </header>

      <div className="relative min-h-0 flex-1">
        <BoardCanvas
          notes={visibleNotes}
          connectors={boardCanvas.connectors}
          cursors={visibleCursors}
          frame={boardCanvas.frame}
          zoom={0.8}
          noteOpacity={0.95}
          showFrame={frameVisible}
        />
        <div className="absolute top-4 left-1/2 -translate-x-1/2 rounded-card border border-border bg-surface/95 px-4 py-2 text-center shadow-card">
          <p className="text-caption font-semibold tabular">
            {t('replay.step', {
              time: current.at,
              current: progress + 1,
              total: timelineEntries.length,
            })}
          </p>
          <p className="text-[11px] text-muted-foreground">
            {current.authorName ?? userById.get(current.userId)?.name ?? t('common.guest')} —{' '}
            {current.label}
          </p>
        </div>
      </div>

      <section
        className="shrink-0 border-t border-border bg-surface px-4 py-3"
        aria-label={t('replay.timelineAria')}
      >
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-1">
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label={t('replay.start')}
              onClick={() => {
                setPlaying(false)
                setProgress(0)
              }}
            >
              <SkipBack className="size-4" aria-hidden="true" />
            </Button>
            <Button
              type="button"
              size="icon"
              aria-label={
                playing ? t('replay.pause') : t('replay.play')
              }
              onClick={() => {
                if (!playing && progress >= lastIndex) setProgress(0)
                setPlaying((value) => !value)
              }}
            >
              {playing ? (
                <Pause className="size-4" aria-hidden="true" />
              ) : (
                <Play className="size-4" aria-hidden="true" />
              )}
            </Button>
          </div>

          <SegmentedControl<Speed>
            value={speed}
            onValueChange={setSpeed}
            label={t('replay.speed')}
            options={[
              { value: '1', label: '1×' },
              { value: '2', label: '2×' },
              { value: '4', label: '4×' },
            ]}
          />

          <p className="hidden min-w-0 flex-1 truncate text-caption text-muted-foreground tabular md:block">
            {timelineEntries[progress]?.at} → {timelineEntries[lastIndex]?.at} ·{' '}
            {t('replay.visible', { count: visibleNotes.length })}
          </p>

          <div className="flex flex-wrap items-center gap-1.5">
            <Users className="size-3.5 text-muted-foreground" aria-hidden="true" />
            <button
              type="button"
              onClick={() => setFilter('all')}
              aria-pressed={filter === 'all'}
              className={cn(
                'rounded-full border px-2.5 py-1 text-[11px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                filter === 'all'
                  ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                  : 'border-border text-muted-foreground hover:text-foreground',
              )}
            >
              {t('replay.all')}
            </button>
            {contributors.map((user) => (
              <button
                key={user.id}
                type="button"
                onClick={() =>
                  setFilter((current) => (current === user.id ? 'all' : user.id))
                }
                aria-pressed={filter === user.id}
                className={cn(
                  'flex items-center gap-1.5 rounded-full border px-2 py-1 text-[11px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                  filter === user.id
                    ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                    : 'border-border text-muted-foreground hover:text-foreground',
                )}
              >
                <span
                  className={cn(
                    'size-2 rounded-full',
                    accentClasses[user.accent].chip,
                  )}
                  aria-hidden="true"
                />
                {user.name.split(' ')[0]}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-3">
          <div className="relative h-3" aria-hidden="true">
            {markers.map((marker) => {
              const index = timelineEntries.findIndex((entry) => entry.id === marker.id)
              const user = userById.get(marker.userId)
              const passed = index <= progress
              return (
                <span
                  key={marker.id}
                  title={`${marker.at} — ${marker.label}`}
                  className={cn(
                    'absolute top-1 size-2 -translate-x-1/2 rounded-full ring-2 ring-surface',
                    user ? accentClasses[user.accent].chip : 'bg-muted-foreground',
                    passed ? 'opacity-100' : 'opacity-40',
                  )}
                  style={{ left: `${(index / lastIndex) * 100}%` }}
                />
              )
            })}
          </div>
          <input
            type="range"
            min={0}
            max={lastIndex}
            step={1}
            value={progress}
            onChange={(event) => {
              setPlaying(false)
              setProgress(Number(event.target.value))
            }}
            aria-label={t('replay.progressAria')}
            aria-valuetext={`${current.at} — ${current.label}`}
            className="w-full cursor-pointer accent-primary"
          />
        </div>
      </section>
    </div>
  )
}

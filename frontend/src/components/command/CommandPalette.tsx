import {
  ArrowRight,
  CornerDownLeft,
  LayoutGrid,
  Moon,
  Play,
  Search,
  Settings,
  Sun,
} from 'lucide-react'
import { useMemo, useRef, useState, type ComponentType } from 'react'
import { useNavigate } from 'react-router-dom'
import { boards } from '@/mocks'
import { Dialog, DialogContent } from '@/components/ui/dialog'
import { useI18n } from '@/i18n'
import { useTheme } from '@/theme/ThemeContext'
import { cn } from '@/lib/utils'

interface PaletteItem {
  id: string
  group:
    | 'palette.group.boards'
    | 'palette.group.actions'
    | 'palette.group.shortcuts'
  label: string
  hint?: string
  icon: ComponentType<{ className?: string }>
  run?: () => void
}

export interface CommandPaletteProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {open ? <CommandPaletteContent onOpenChange={onOpenChange} /> : null}
    </Dialog>
  )
}

function CommandPaletteContent({
  onOpenChange,
}: Pick<CommandPaletteProps, 'onOpenChange'>) {
  const navigate = useNavigate()
  const { t } = useI18n()
  const { resolved, setMode } = useTheme()
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const listRef = useRef<HTMLDivElement>(null)

  const items = useMemo<PaletteItem[]>(() => {
    const boardItems: PaletteItem[] = boards.map((board) => ({
      id: `board-${board.id}`,
      group: 'palette.group.boards',
      label: board.title,
      hint: board.updatedAtLabel,
      icon: LayoutGrid,
      run: () => navigate(`/board/${board.id}`),
    }))

    const actionItems: PaletteItem[] = [
      {
        id: 'action-replay',
        group: 'palette.group.actions',
        label: t('palette.replay'),
        hint: 'Refonte du site e-commerce',
        icon: Play,
        run: () => navigate('/board/b1/replay'),
      },
      {
        id: 'action-settings',
        group: 'palette.group.actions',
        label: t('palette.settings'),
        icon: Settings,
        run: () => navigate('/settings'),
      },
      {
        id: 'action-theme',
        group: 'palette.group.actions',
        label:
          resolved === 'dark'
            ? t('palette.toLight')
            : t('palette.toDark'),
        icon: resolved === 'dark' ? Sun : Moon,
        run: () => setMode(resolved === 'dark' ? 'light' : 'dark'),
      },
      {
        id: 'action-dashboard',
        group: 'palette.group.actions',
        label: t('palette.dashboard'),
        icon: ArrowRight,
        run: () => navigate('/'),
      },
    ]

    const shortcutItems: PaletteItem[] = [
      {
        id: 'shortcut-palette',
        group: 'palette.group.shortcuts',
        label: t('palette.openPalette'),
        hint: 'Ctrl K',
        icon: Search,
      },
      {
        id: 'shortcut-create',
        group: 'palette.group.shortcuts',
        label: t('palette.create'),
        hint: 'N',
        icon: LayoutGrid,
      },
      {
        id: 'shortcut-undo',
        group: 'palette.group.shortcuts',
        label: t('palette.undo'),
        hint: 'Ctrl Z',
        icon: CornerDownLeft,
      },
    ]

    return [...boardItems, ...actionItems, ...shortcutItems]
  }, [navigate, resolved, setMode, t])

  const normalized = query.trim().toLowerCase()
  const filtered = normalized
    ? items.filter((item) =>
        `${item.label} ${item.hint ?? ''}`.toLowerCase().includes(normalized),
      )
    : items

  // Only runnable items can be highlighted, but every row stays visible.
  const runnableIds = filtered.filter((item) => item.run).map((item) => item.id)
  const activeId = runnableIds[activeIndex] ?? runnableIds[0]

  const groups: PaletteItem['group'][] = [
    'palette.group.boards',
    'palette.group.actions',
    'palette.group.shortcuts',
  ]

  function runItem(item: PaletteItem) {
    if (!item.run) return
    item.run()
    onOpenChange(false)
  }

  function move(direction: 1 | -1) {
    if (runnableIds.length === 0) return
    setActiveIndex((current) => {
      const next = current + direction
      if (next < 0) return runnableIds.length - 1
      if (next >= runnableIds.length) return 0
      return next
    })
  }

  return (
    <DialogContent
      showClose={false}
      className="max-w-xl gap-0 overflow-hidden p-0"
      aria-describedby={undefined}
    >
        <h2 className="sr-only">{t('palette.title')}</h2>
        <div className="flex items-center gap-2.5 border-b border-border px-4 py-3">
          <Search className="size-4 text-muted-foreground" aria-hidden="true" />
          <input
            autoFocus
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setActiveIndex(0)
            }}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown') {
                event.preventDefault()
                move(1)
              }
              if (event.key === 'ArrowUp') {
                event.preventDefault()
                move(-1)
              }
              if (event.key === 'Enter') {
                event.preventDefault()
                const item = filtered.find((entry) => entry.id === activeId)
                if (item) runItem(item)
              }
            }}
            placeholder={t('palette.placeholder')}
            aria-label={t('palette.placeholder')}
            className="h-7 w-full bg-transparent text-body text-foreground outline-none placeholder:text-muted-foreground"
          />
          <kbd className="rounded border border-border bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
            {t('palette.esc')}
          </kbd>
        </div>

        <div ref={listRef} className="max-h-[420px] overflow-y-auto p-2">
          {filtered.length === 0 ? (
            <p className="px-3 py-8 text-center text-sm text-muted-foreground">
              {t('palette.empty', { query })}
            </p>
          ) : (
            groups.map((group) => {
              const groupItems = filtered.filter((item) => item.group === group)
              if (groupItems.length === 0) return null

              return (
                <div key={group} className="mb-1">
                  <p className="px-3 py-1.5 text-caption text-muted-foreground">
                    {t(group)}
                  </p>
                  <ul>
                    {groupItems.map((item) => {
                      const Icon = item.icon
                      const active = item.id === activeId && Boolean(item.run)
                      return (
                        <li key={item.id}>
                          <button
                            type="button"
                            onMouseEnter={() => {
                              if (!item.run) return
                              setActiveIndex(runnableIds.indexOf(item.id))
                            }}
                            onClick={() => runItem(item)}
                            className={cn(
                              'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition-colors',
                              active
                                ? 'bg-primary-soft text-primary-soft-foreground'
                                : 'text-foreground hover:bg-hover',
                              !item.run && 'cursor-default',
                            )}
                          >
                            <Icon className="size-4 shrink-0 opacity-80" />
                            <span className="min-w-0 flex-1 truncate">
                              {item.label}
                            </span>
                            {item.hint ? (
                              <span className="text-caption text-muted-foreground">
                                {item.hint}
                              </span>
                            ) : null}
                          </button>
                        </li>
                      )
                    })}
                  </ul>
                </div>
              )
            })
          )}
        </div>

        <div className="flex items-center justify-between border-t border-border px-4 py-2.5 text-caption text-muted-foreground">
          <span className="flex items-center gap-3">
            <span className="flex items-center gap-1">
              <kbd className="rounded border border-border bg-muted px-1">
                ↑
              </kbd>
              <kbd className="rounded border border-border bg-muted px-1">
                ↓
              </kbd>
              {t('palette.navigate')}
            </span>
            <span className="flex items-center gap-1">
              <kbd className="rounded border border-border bg-muted px-1">
                ↵
              </kbd>
              {t('palette.open')}
            </span>
          </span>
          <span>{t('palette.results', { count: filtered.length })}</span>
        </div>
    </DialogContent>
  )
}

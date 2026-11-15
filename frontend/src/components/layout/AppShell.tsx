import {
  Archive,
  ChevronDown,
  LayoutGrid,
  LayoutTemplate,
  LogOut,
  Search,
  Settings,
  Trash2,
} from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { workspace } from '@/mocks'
import {
  WORKSPACE_UPDATED_EVENT,
  getSessionUser,
  getSessionWorkspace,
  logout,
  type ApiSessionWorkspace,
} from '@/api/client'
import { Logo } from '@/components/brand/Logo'
import { Avatar } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { HeaderControls } from '@/components/layout/HeaderControls'
import { useToast } from '@/components/ui/toast-context'
import { useI18n, type TranslationKey } from '@/i18n'
import { cn } from '@/lib/utils'

export type WorkspaceView = 'boards' | 'archive' | 'templates' | 'trash' | 'settings'

const navigation: {
  view: WorkspaceView
  labelKey: TranslationKey
  icon: typeof LayoutGrid
  to: string
}[] = [
  { view: 'boards', labelKey: 'nav.boards', icon: LayoutGrid, to: '/' },
  {
    view: 'archive',
    labelKey: 'nav.archive',
    icon: Archive,
    to: '/?view=archive',
  },
  {
    view: 'templates',
    labelKey: 'nav.templates',
    icon: LayoutTemplate,
    to: '/?view=templates',
  },
  {
    view: 'trash',
    labelKey: 'nav.trash',
    icon: Trash2,
    to: '/?view=trash',
  },
  {
    view: 'settings',
    labelKey: 'nav.settings',
    icon: Settings,
    to: '/settings',
  },
]

export interface AppShellProps {
  activeView: WorkspaceView
  title: string
  description?: string
  actions?: ReactNode
  onOpenPalette: () => void
  children: ReactNode
}

export function AppShell({
  activeView,
  title,
  description,
  actions,
  onOpenPalette,
  children,
}: AppShellProps) {
  const navigate = useNavigate()
  const { toast } = useToast()
  const { t } = useI18n()
  const currentUser = getSessionUser()
  const [sessionWorkspace, setSessionWorkspace] = useState<ApiSessionWorkspace | null>(() => getSessionWorkspace())

  useEffect(() => {
    function onWorkspaceUpdated(event: Event) {
      setSessionWorkspace((event as CustomEvent<ApiSessionWorkspace>).detail)
    }
    window.addEventListener(WORKSPACE_UPDATED_EVENT, onWorkspaceUpdated)
    return () => window.removeEventListener(WORKSPACE_UPDATED_EVENT, onWorkspaceUpdated)
  }, [])

  const workspaceName = sessionWorkspace?.name ?? workspace.name
  const workspaceMemberCount = sessionWorkspace?.memberCount ?? workspace.seats

  return (
    <div className="flex min-h-svh bg-background text-foreground">
      <aside className="sticky top-0 hidden h-svh w-64 shrink-0 flex-col border-r border-border bg-surface md:flex">
        <div className="px-5 py-5">
          <Link
            to="/"
            className="rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          >
            <Logo />
          </Link>
        </div>

        <div className="px-3">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="flex w-full items-center gap-2.5 rounded-card border border-border bg-background px-3 py-2.5 text-left transition-colors hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              >
                <span className="flex size-8 items-center justify-center rounded-lg bg-primary-soft text-caption font-semibold text-primary-soft-foreground">
                  SC
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">
                    {workspaceName}
                  </span>
                  <span className="block text-caption text-muted-foreground">
                    {t('workspace.members', { count: workspaceMemberCount })}
                  </span>
                </span>
                <ChevronDown
                  className="size-4 text-muted-foreground"
                  aria-hidden="true"
                />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              <DropdownMenuLabel>{t('workspace.current')}</DropdownMenuLabel>
              <DropdownMenuItem
                onSelect={() => navigate('/')}
                className="justify-between"
              >
                {workspaceName}
                <Badge variant="primary">{t('workspace.active')}</Badge>
              </DropdownMenuItem>
              <DropdownMenuItem
                onSelect={() =>
                  toast({
                    title: 'Studio Sfax',
                    description: t('workspace.demoOnly'),
                  })
                }
              >
                Studio Sfax
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() =>
                  toast({
                    title: 'Création d’espace',
                    description: t('workspace.createUnavailable'),
                  })
                }
              >
                {t('workspace.create')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <nav
          className="mt-6 flex flex-col gap-1 px-3"
          aria-label={t('nav.primary')}
        >
          {navigation.map((item) => {
            const Icon = item.icon
            const active = item.view === activeView
            return (
              <Link
                key={item.view}
                to={item.to}
                aria-current={active ? 'page' : undefined}
                className={cn(
                  'flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                  active
                    ? 'bg-primary-soft text-primary-soft-foreground'
                    : 'text-muted-foreground hover:bg-hover hover:text-foreground',
                )}
              >
                <Icon className="size-4" aria-hidden="true" />
                {t(item.labelKey)}
              </Link>
            )
          })}
        </nav>

        <div className="mt-auto border-t border-border p-3">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="flex w-full items-center gap-2.5 rounded-xl px-2 py-2 text-left transition-colors hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              >
                <Avatar
                  name={currentUser?.name ?? t('common.guest')}
                  className="size-8"
                />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">
                    {currentUser?.name ?? t('common.guest')}
                  </span>
                  <span className="block text-caption text-muted-foreground">
                    {currentUser?.role ?? 'Viewer'}
                  </span>
                </span>
                <ChevronDown
                  className="size-4 text-muted-foreground"
                  aria-hidden="true"
                />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="w-56">
              <DropdownMenuLabel>{currentUser?.email}</DropdownMenuLabel>
              <DropdownMenuItem onSelect={() => navigate('/settings')}>
                <Settings className="size-4" aria-hidden="true" />
                {t('nav.settings')}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => {
                  void logout().finally(() => navigate('/sign-in', { replace: true }))
                }}
              >
                <LogOut className="size-4" aria-hidden="true" />
                {t('workspace.signOut')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur">
          <div className="flex items-center gap-3 px-4 py-3 sm:px-6">
            <Link to="/" className="md:hidden">
              <Logo showWordmark={false} size="sm" />
            </Link>
            <button
              type="button"
              onClick={onOpenPalette}
              className="flex h-11 min-w-0 flex-1 items-center gap-2.5 rounded-xl border border-border bg-surface px-3 text-left text-sm text-muted-foreground transition-colors hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:max-w-md"
            >
              <Search className="size-4" aria-hidden="true" />
              <span className="truncate">{t('shell.search')}</span>
              <kbd className="ml-auto hidden rounded border border-border bg-muted px-1.5 py-0.5 text-[11px] font-medium sm:block">
                Ctrl K
              </kbd>
            </button>
            <div className="ml-auto flex items-center gap-2">
              <HeaderControls />
              {actions}
            </div>
          </div>

          <nav
            className="flex gap-1 overflow-x-auto border-t border-border px-3 py-2 md:hidden"
            aria-label={t('nav.mobile')}
          >
            {navigation.map((item) => {
              const Icon = item.icon
              const active = item.view === activeView
              return (
                <Link
                  key={item.view}
                  to={item.to}
                  aria-current={active ? 'page' : undefined}
                  className={cn(
                    'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-caption whitespace-nowrap',
                    active
                      ? 'bg-primary-soft text-primary-soft-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  <Icon className="size-4" aria-hidden="true" />
                  {t(item.labelKey)}
                </Link>
              )
            })}
          </nav>
        </header>

        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
          <div className="mx-auto w-full max-w-6xl">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <h1 className="text-display">{title}</h1>
                {description ? (
                  <p className="mt-1 text-sm text-muted-foreground">
                    {description}
                  </p>
                ) : null}
              </div>
            </div>
            {children}
          </div>
        </main>
      </div>
    </div>
  )
}

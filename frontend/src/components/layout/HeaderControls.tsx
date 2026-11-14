import { Check, Globe, Monitor, Moon, Sun } from 'lucide-react'
import { LOCALES, useI18n } from '@/i18n'
import { useTheme, type ThemeMode } from '@/theme/ThemeContext'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'

const themeModes: {
  mode: ThemeMode
  labelKey: 'theme.light' | 'theme.dark' | 'theme.system'
  icon: typeof Sun
}[] = [
  { mode: 'light', labelKey: 'theme.light', icon: Sun },
  { mode: 'dark', labelKey: 'theme.dark', icon: Moon },
  { mode: 'system', labelKey: 'theme.system', icon: Monitor },
]

/**
 * Language selector (FR/EN/AR) and light/dark/system theme toggle.
 * Shared by the dashboard shell, board editor, replay and sign-in headers.
 */
export function HeaderControls({ className }: { className?: string }) {
  const { locale, setLocale, t } = useI18n()
  const { mode, resolved, setMode } = useTheme()
  const ThemeIcon = resolved === 'dark' ? Moon : Sun

  return (
    <div className={cn('flex items-center gap-1', className)}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="gap-1.5 px-2"
            aria-label={t('language.label')}
          >
            <Globe className="size-4" aria-hidden="true" />
            <span className="text-[11px] font-semibold uppercase">
              {locale}
            </span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-44">
          <DropdownMenuLabel>{t('language.label')}</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {LOCALES.map((option) => (
            <DropdownMenuItem
              key={option.code}
              onSelect={() => setLocale(option.code)}
              className="justify-between"
            >
              <span className="flex items-center gap-2">
                <span className="w-6 text-[11px] font-semibold uppercase text-muted-foreground">
                  {option.code}
                </span>
                {t(`language.${option.code}`)}
              </span>
              {locale === option.code ? (
                <Check className="size-4 text-primary" aria-hidden="true" />
              ) : null}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={t('theme.label')}
          >
            <ThemeIcon className="size-4" aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-44">
          <DropdownMenuLabel>{t('theme.label')}</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {themeModes.map((option) => {
            const Icon = option.icon
            return (
              <DropdownMenuItem
                key={option.mode}
                onSelect={() => setMode(option.mode)}
                className="justify-between"
              >
                <span className="flex items-center gap-2">
                  <Icon className="size-4" aria-hidden="true" />
                  {t(option.labelKey)}
                </span>
                {mode === option.mode ? (
                  <Check className="size-4 text-primary" aria-hidden="true" />
                ) : null}
              </DropdownMenuItem>
            )
          })}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

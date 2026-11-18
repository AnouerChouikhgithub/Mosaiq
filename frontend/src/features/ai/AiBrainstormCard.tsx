import { Sparkles } from 'lucide-react'
import { useI18n } from '@/i18n'

/**
 * Entry point for the AI experience, styled like the template cards of the
 * board creation area so the feature feels native to the dashboard.
 */
export function AiBrainstormCard({ onStart }: { onStart: () => void }) {
  const { t } = useI18n()

  return (
    <button
      type="button"
      onClick={onStart}
      className="flex h-full flex-col items-start gap-2 rounded-card border border-primary/30 bg-primary-soft/40 p-4 text-start transition-colors hover:border-primary/60 hover:bg-primary-soft/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
      <span className="flex items-center gap-1.5 text-caption font-semibold text-primary-soft-foreground">
        <Sparkles className="size-4" aria-hidden="true" />
        {t('ai.entry.badge')}
      </span>
      <span className="text-sm font-semibold">{t('ai.entry.title')}</span>
      <span className="text-caption text-muted-foreground">
        {t('ai.entry.description')}
      </span>
      <span className="mt-auto pt-2 text-caption font-medium text-primary-soft-foreground">
        {t('ai.entry.action')}
      </span>
    </button>
  )
}

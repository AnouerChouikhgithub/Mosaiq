import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  ArrowDown,
  ArrowUp,
  Layers,
  MousePointer2,
} from 'lucide-react'
import type { StickyNote } from '@/mocks'
import { useI18n } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { cn } from '@/lib/utils'
import { noteClasses, noteColorLabelKeys, noteSwatches } from './colors'

export type NoteStylePatch = Partial<
  Pick<StickyNote, 'color' | 'stroke' | 'textSize' | 'align'>
>

export interface PropertiesPanelProps {
  note: StickyNote | null
  onChange: (patch: NoteStylePatch) => void
  onMoveLayer: (direction: 'up' | 'down') => void
  canMoveUp: boolean
  canMoveDown: boolean
}

export function PropertiesPanel({
  note,
  onChange,
  onMoveLayer,
  canMoveUp,
  canMoveDown,
}: PropertiesPanelProps) {
  const { t } = useI18n()

  return (
    <aside
      className="hidden h-full w-80 shrink-0 flex-col overflow-y-auto border-l border-border bg-surface lg:flex"
      aria-label={t('properties.aria')}
    >
      <header className="flex items-center gap-2 border-b border-border px-4 py-3">
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold">{t('properties.title')}</h2>
          <p className="text-caption text-muted-foreground">
            {note
              ? t('properties.selected', {
                  color: t(noteColorLabelKeys[note.color]),
                })
              : t('properties.none')}
          </p>
        </div>
        <Badge variant={note ? 'primary' : 'neutral'}>
          {t('properties.badge')}
        </Badge>
      </header>

      {note ? (
        <div className="flex flex-col gap-5 p-4">
          <section className="flex flex-col gap-2">
            <h3 className="text-caption font-medium text-muted-foreground">
              {t('properties.fill')}
            </h3>
            <div className="flex items-center gap-2">
              {noteSwatches.map((swatch) => (
                <button
                  key={swatch.color}
                  type="button"
                  onClick={() => onChange({ color: swatch.color })}
                  aria-label={t('properties.color', {
                    color: t(noteColorLabelKeys[swatch.color]),
                  })}
                  aria-pressed={note.color === swatch.color}
                  className={cn(
                    'size-8 rounded-lg border border-border/60 transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    noteClasses[swatch.color].surface,
                    note.color === swatch.color &&
                      'ring-2 ring-primary ring-offset-2 ring-offset-surface',
                  )}
                />
              ))}
            </div>
          </section>

          <section className="flex flex-col gap-2">
            <h3 className="text-caption font-medium text-muted-foreground">
              {t('properties.outline')}
            </h3>
            <ToggleGroup
              type="single"
              value={note.stroke}
              onValueChange={(value) => {
                if (value) onChange({ stroke: value as StickyNote['stroke'] })
              }}
              aria-label={t('properties.outlineAria')}
              className="flex gap-1 rounded-xl border border-border bg-muted p-1"
            >
              {(
                [
                  { value: 'none', labelKey: 'properties.outline.none' },
                  { value: 'thin', labelKey: 'properties.outline.thin' },
                  { value: 'dashed', labelKey: 'properties.outline.dashed' },
                ] as const
              ).map((option) => (
                <ToggleGroupItem
                  key={option.value}
                  value={option.value}
                  className="h-8 w-auto flex-1 px-2 text-caption"
                >
                  {t(option.labelKey)}
                </ToggleGroupItem>
              ))}
            </ToggleGroup>
          </section>

          <section className="flex flex-col gap-2">
            <h3 className="text-caption font-medium text-muted-foreground">
              {t('properties.textSize')}
            </h3>
            <ToggleGroup
              type="single"
              value={note.textSize}
              onValueChange={(value) => {
                if (value) {
                  onChange({ textSize: value as StickyNote['textSize'] })
                }
              }}
              aria-label={t('properties.textSize')}
              className="flex gap-1 rounded-xl border border-border bg-muted p-1"
            >
              {(
                [
                  { value: 's', label: 'S' },
                  { value: 'm', label: 'M' },
                  { value: 'l', label: 'L' },
                ] as const
              ).map((option) => (
                <ToggleGroupItem
                  key={option.value}
                  value={option.value}
                  className="h-8 w-auto flex-1 text-caption"
                >
                  {option.label}
                </ToggleGroupItem>
              ))}
            </ToggleGroup>
          </section>

          <section className="flex flex-col gap-2">
            <h3 className="text-caption font-medium text-muted-foreground">
              {t('properties.align')}
            </h3>
            <ToggleGroup
              type="single"
              value={note.align}
              onValueChange={(value) => {
                if (value) onChange({ align: value as StickyNote['align'] })
              }}
              aria-label={t('properties.alignAria')}
              className="flex gap-1 rounded-xl border border-border bg-muted p-1"
            >
              {(
                [
                  {
                    value: 'left',
                    labelKey: 'properties.align.left',
                    icon: AlignLeft,
                  },
                  {
                    value: 'center',
                    labelKey: 'properties.align.center',
                    icon: AlignCenter,
                  },
                  {
                    value: 'right',
                    labelKey: 'properties.align.right',
                    icon: AlignRight,
                  },
                ] as const
              ).map((option) => {
                const Icon = option.icon
                return (
                  <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    aria-label={t(option.labelKey)}
                    className="h-8 w-auto flex-1"
                  >
                    <Icon className="size-4" />
                  </ToggleGroupItem>
                )
              })}
            </ToggleGroup>
          </section>

          <section className="flex flex-col gap-2">
            <h3 className="flex items-center gap-1.5 text-caption font-medium text-muted-foreground">
              <Layers className="size-3.5" aria-hidden="true" />
              {t('properties.layers')}
            </h3>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="flex-1"
                onClick={() => onMoveLayer('up')}
                disabled={!canMoveUp}
              >
                <ArrowUp className="size-4" aria-hidden="true" />
                {t('properties.forward')}
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="flex-1"
                onClick={() => onMoveLayer('down')}
                disabled={!canMoveDown}
              >
                <ArrowDown className="size-4" aria-hidden="true" />
                {t('properties.backward')}
              </Button>
            </div>
          </section>

          <section className="rounded-card border border-border bg-background p-3">
            <p className="text-caption font-medium">{t('properties.tip')}</p>
            <p className="mt-1 text-caption text-muted-foreground">
              {t('properties.tipBody')}
            </p>
          </section>
        </div>
      ) : (
        <div className="flex flex-1 flex-col items-center justify-center px-6 text-center">
          <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <MousePointer2 className="size-5" aria-hidden="true" />
          </span>
          <p className="mt-3 text-sm font-semibold">
            {t('properties.none')}
          </p>
          <p className="mt-1 text-caption text-muted-foreground">
            {t('properties.emptyBody')}
          </p>
        </div>
      )}
    </aside>
  )
}

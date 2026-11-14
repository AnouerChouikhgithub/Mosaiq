import type {
  NoteAlign,
  NoteColor,
  NoteStroke,
  NoteTextSize,
  PresenceAccent,
} from '@/mocks'
import type { TranslationKey } from '@/i18n'

/**
 * Tailwind only ships utilities it can see as literal strings, so the token
 * classes for mock-driven colors live here instead of being interpolated.
 */
export const noteClasses: Record<NoteColor, { surface: string; ink: string }> = {
  amber: { surface: 'bg-note-amber', ink: 'text-note-amber-ink' },
  rose: { surface: 'bg-note-rose', ink: 'text-note-rose-ink' },
  sky: { surface: 'bg-note-sky', ink: 'text-note-sky-ink' },
  mint: { surface: 'bg-note-mint', ink: 'text-note-mint-ink' },
  lilac: { surface: 'bg-note-lilac', ink: 'text-note-lilac-ink' },
}

export const noteSwatches = (
  Object.keys(noteClasses) as NoteColor[]
).map((color) => ({ color, label: color }))

export const noteColorLabelKeys: Record<NoteColor, TranslationKey> = {
  amber: 'properties.color.amber',
  rose: 'properties.color.rose',
  sky: 'properties.color.sky',
  mint: 'properties.color.mint',
  lilac: 'properties.color.lilac',
}

export const noteStrokeClasses: Record<NoteStroke, string> = {
  none: 'border-transparent',
  thin: 'border-border-strong',
  dashed: 'border-dashed border-border-strong',
}

export const noteTextSizeClasses: Record<NoteTextSize, string> = {
  s: 'text-[13px] leading-[1.35]',
  m: 'text-sm leading-snug',
  l: 'text-base leading-snug',
}

export const noteAlignClasses: Record<NoteAlign, string> = {
  left: 'text-left',
  center: 'text-center',
  right: 'text-right',
}

export const accentClasses: Record<
  PresenceAccent,
  { dot: string; chip: string; ring: string; border: string }
> = {
  'cursor-1': {
    dot: 'text-cursor-1',
    chip: 'bg-cursor-1 text-cursor-foreground',
    ring: 'ring-cursor-1',
    border: 'border-cursor-1',
  },
  'cursor-2': {
    dot: 'text-cursor-2',
    chip: 'bg-cursor-2 text-cursor-foreground',
    ring: 'ring-cursor-2',
    border: 'border-cursor-2',
  },
  'cursor-3': {
    dot: 'text-cursor-3',
    chip: 'bg-cursor-3 text-cursor-foreground',
    ring: 'ring-cursor-3',
    border: 'border-cursor-3',
  },
}

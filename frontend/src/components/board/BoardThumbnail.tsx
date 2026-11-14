import type { NoteColor } from '@/mocks'
import { noteClasses } from './colors'
import { cn } from '@/lib/utils'

const positions = [
  'top-3 left-3 h-9 w-16',
  'top-3 right-4 h-8 w-14',
  'top-16 left-6 h-8 w-12',
  'top-14 right-5 h-10 w-16',
  'bottom-3 left-4 h-7 w-14',
  'bottom-4 right-6 h-8 w-12',
] as const

export interface BoardThumbnailProps {
  noteColors: NoteColor[]
  className?: string
}

export function BoardThumbnail({ noteColors, className }: BoardThumbnailProps) {
  return (
    <div
      className={cn(
        'relative overflow-hidden rounded-xl border border-border bg-canvas',
        className,
      )}
      style={{
        backgroundImage:
          'radial-gradient(var(--grid-dot) 1px, transparent 1px)',
        backgroundSize: '16px 16px',
      }}
      aria-hidden="true"
    >
      <div className="absolute inset-x-5 top-2.5 h-px bg-border" />
      {noteColors.slice(0, positions.length).map((color, index) => (
        <span
          key={`${color}-${index}`}
          className={cn(
            'absolute rotate-[-2deg] rounded-lg border border-border/60 shadow-card',
            positions[index],
            noteClasses[color].surface,
          )}
        />
      ))}
    </div>
  )
}

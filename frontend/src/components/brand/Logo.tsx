import { cn } from '@/lib/utils'

export interface LogoProps {
  className?: string
  showWordmark?: boolean
  size?: 'sm' | 'md'
}

export function Logo({ className, showWordmark = true, size = 'md' }: LogoProps) {
  return (
    <span className={cn('inline-flex items-center gap-2.5', className)}>
      <img
        src="/Mosaiq.svg"
        alt=""
        aria-hidden="true"
        className={cn(size === 'sm' ? 'size-7' : 'size-9')}
      />
      {showWordmark ? (
        <span
          className={cn(
            'font-semibold tracking-tight text-foreground',
            size === 'sm' ? 'text-base' : 'text-title',
          )}
        >
          Mosaiq
        </span>
      ) : (
        <span className="sr-only">Mosaiq</span>
      )}
    </span>
  )
}

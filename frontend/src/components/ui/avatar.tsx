import * as AvatarPrimitive from '@radix-ui/react-avatar'
import { cn } from '@/lib/utils'

export interface AvatarProps {
  name: string
  src?: string
  className?: string
  ringClassName?: string
}

function initialsOf(name: string) {
  return name
    .split(' ')
    .filter(Boolean)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .slice(0, 2)
    .join('')
}

export function Avatar({ name, src, className, ringClassName }: AvatarProps) {
  return (
    <AvatarPrimitive.Root
      className={cn(
        'relative flex size-9 shrink-0 overflow-hidden rounded-full border border-border',
        ringClassName,
        className,
      )}
    >
      {src ? (
        <AvatarPrimitive.Image
          src={src}
          alt={name}
          className="size-full object-cover"
        />
      ) : null}
      <AvatarPrimitive.Fallback
        className="flex size-full items-center justify-center rounded-full bg-primary-soft text-caption font-semibold text-primary-soft-foreground"
        aria-label={name}
      >
        {initialsOf(name)}
      </AvatarPrimitive.Fallback>
    </AvatarPrimitive.Root>
  )
}

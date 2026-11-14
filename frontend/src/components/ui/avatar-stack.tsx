import type { PresenceAccent } from '@/mocks'
import { accentClasses } from '@/components/board/colors'
import { Avatar } from './avatar'
import { cn } from '@/lib/utils'

export interface AvatarStackPerson {
  id: string
  name: string
  accent?: PresenceAccent
}

export interface AvatarStackProps {
  people: AvatarStackPerson[]
  max?: number
  className?: string
  size?: 'sm' | 'md'
}

export function AvatarStack({
  people,
  max = 3,
  className,
  size = 'sm',
}: AvatarStackProps) {
  const visible = people.slice(0, max)
  const overflow = people.length - visible.length

  return (
    <div className={cn('flex items-center', className)}>
      {visible.map((person) => (
        <Avatar
          key={person.id}
          name={person.name}
          className={cn(
            'border-2 border-surface first:ml-0',
            size === 'sm' ? 'size-7' : 'size-9',
            person.accent ? 'ring-2 ring-offset-2 ring-offset-surface' : undefined,
            person.accent ? accentClasses[person.accent].ring : undefined,
          )}
        />
      ))}
      {overflow > 0 ? (
        <span
          className={cn(
            'flex items-center justify-center rounded-full border-2 border-surface bg-muted text-caption font-medium text-muted-foreground',
            size === 'sm' ? 'size-7' : 'size-9',
          )}
        >
          +{overflow}
        </span>
      ) : null}
    </div>
  )
}

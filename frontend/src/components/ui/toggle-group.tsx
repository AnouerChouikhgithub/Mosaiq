import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group'
import type { ComponentProps, ReactNode } from 'react'
import { cn } from '@/lib/utils'

export const ToggleGroup = ToggleGroupPrimitive.Root

export function ToggleGroupItem({
  className,
  ...props
}: ComponentProps<typeof ToggleGroupPrimitive.Item>) {
  return (
    <ToggleGroupPrimitive.Item
      className={cn(
        'inline-flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors outline-none hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=on]:bg-surface data-[state=on]:text-foreground data-[state=on]:shadow-card',
        className,
      )}
      {...props}
    />
  )
}

export interface SegmentedOption<T extends string> {
  value: T
  label: string
  icon?: ReactNode
}

export interface SegmentedControlProps<T extends string> {
  value: T
  onValueChange: (value: T) => void
  options: SegmentedOption<T>[]
  label: string
  className?: string
}

export function SegmentedControl<T extends string>({
  value,
  onValueChange,
  options,
  label,
  className,
}: SegmentedControlProps<T>) {
  return (
    <ToggleGroupPrimitive.Root
      type="single"
      value={value}
      onValueChange={(next) => {
        if (next) onValueChange(next as T)
      }}
      aria-label={label}
      className={cn(
        'inline-flex items-center gap-0.5 rounded-xl border border-border bg-muted p-1',
        className,
      )}
    >
      {options.map((option) => (
        <ToggleGroupPrimitive.Item
          key={option.value}
          value={option.value}
          className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg px-3 text-caption text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=on]:bg-surface data-[state=on]:text-foreground data-[state=on]:shadow-card"
        >
          {option.icon}
          {option.label}
        </ToggleGroupPrimitive.Item>
      ))}
    </ToggleGroupPrimitive.Root>
  )
}

import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import type { ReactNode } from 'react'

export const TooltipProvider = TooltipPrimitive.Provider

export interface TooltipProps {
  label: string
  children: ReactNode
  side?: 'top' | 'right' | 'bottom' | 'left'
  shortcut?: string
}

export function Tooltip({ label, children, side = 'bottom', shortcut }: TooltipProps) {
  return (
    <TooltipPrimitive.Root>
      <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
      <TooltipPrimitive.Portal>
        <TooltipPrimitive.Content
          side={side}
          sideOffset={8}
          className="z-50 flex items-center gap-2 rounded-lg border border-border bg-elevated px-2.5 py-1.5 text-caption text-foreground shadow-card"
        >
          {label}
          {shortcut ? (
            <kbd className="rounded border border-border bg-muted px-1 font-sans text-[11px] text-muted-foreground">
              {shortcut}
            </kbd>
          ) : null}
          <TooltipPrimitive.Arrow className="fill-elevated" />
        </TooltipPrimitive.Content>
      </TooltipPrimitive.Portal>
    </TooltipPrimitive.Root>
  )
}

import type { ComponentProps, ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Input({ className, type = 'text', ...props }: ComponentProps<'input'>) {
  return (
    <input
      type={type}
      className={cn(
        'h-11 w-full rounded-xl border border-border bg-surface px-3 text-body text-foreground transition-colors outline-none placeholder:text-muted-foreground/80 hover:border-border-strong focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger',
        className,
      )}
      {...props}
    />
  )
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
  return (
    <textarea
      className={cn(
        'min-h-24 w-full resize-y rounded-xl border border-border bg-surface px-3 py-2.5 text-body text-foreground transition-colors outline-none placeholder:text-muted-foreground/80 hover:border-border-strong focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger',
        className,
      )}
      {...props}
    />
  )
}

export interface FieldProps {
  label: string
  htmlFor: string
  hint?: string
  error?: string
  className?: string
  children: ReactNode
}

export function Field({ label, htmlFor, hint, error, className, children }: FieldProps) {
  const messageId = `${htmlFor}-message`

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <label htmlFor={htmlFor} className="text-caption font-medium text-foreground">
        {label}
      </label>
      {children}
      {error ? (
        <p id={messageId} className="text-caption text-danger-text">
          {error}
        </p>
      ) : hint ? (
        <p id={messageId} className="text-caption text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

export interface NativeSelectProps extends ComponentProps<'select'> {
  wrapperClassName?: string
}

export function NativeSelect({
  className,
  wrapperClassName,
  children,
  ...props
}: NativeSelectProps) {
  return (
    <div className={cn('relative', wrapperClassName)}>
      <select
        className={cn(
          'h-11 w-full appearance-none rounded-xl border border-border bg-surface pr-9 pl-3 text-sm text-foreground transition-colors outline-none hover:border-border-strong focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-60',
          className,
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown
        className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />
    </div>
  )
}

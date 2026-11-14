import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { Loader2 } from 'lucide-react'
import type { ComponentProps } from 'react'
import { cn } from '@/lib/utils'

const buttonVariants = cva(
  'inline-flex shrink-0 items-center justify-center gap-2 rounded-xl font-medium transition-[background-color,border-color,color,transform] duration-150 ease-out outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 focus-visible:ring-offset-background active:translate-y-px disabled:pointer-events-none disabled:opacity-50 motion-reduce:transition-none motion-reduce:active:translate-y-0',
  {
    variants: {
      variant: {
        primary: 'bg-primary text-primary-foreground hover:bg-primary-hover',
        secondary:
          'border border-border bg-surface text-foreground hover:bg-hover',
        ghost: 'text-muted-foreground hover:bg-hover hover:text-foreground',
        destructive:
          'bg-danger text-danger-foreground hover:opacity-90',
        outline:
          'border border-border-strong bg-transparent text-foreground hover:bg-hover',
      },
      size: {
        sm: 'h-9 px-3 text-caption',
        md: 'h-11 px-4 text-sm',
        lg: 'h-13 px-6 text-base',
        icon: 'size-11',
        'icon-sm': 'size-9',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'md',
    },
  },
)

export interface ButtonProps
  extends ComponentProps<'button'>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
  loading?: boolean
}

export function Button({
  className,
  variant,
  size,
  asChild = false,
  loading = false,
  disabled,
  children,
  ...props
}: ButtonProps) {
  const Component = asChild ? Slot : 'button'
  // `disabled` is button-only: asChild may render an anchor, which rejects it.
  const controlProps = asChild ? {} : { disabled: disabled || loading }

  return (
    <Component
      className={cn(buttonVariants({ variant, size }), className)}
      aria-busy={loading || undefined}
      {...controlProps}
      {...props}
    >
      {/* asChild requires exactly one child, so the spinner is button-only. */}
      {asChild ? (
        children
      ) : (
        <>
          {loading ? (
            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
          ) : null}
          {children}
        </>
      )}
    </Component>
  )
}

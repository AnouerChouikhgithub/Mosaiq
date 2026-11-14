import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { CheckCircle2, X } from 'lucide-react'
import { ToastContext, type ToastMessage } from './toast-context'

function ToastCard({
  message,
  onDismiss,
}: {
  message: ToastMessage
  onDismiss: (id: number) => void
}) {
  useEffect(() => {
    const timer = window.setTimeout(() => onDismiss(message.id), 4200)
    return () => window.clearTimeout(timer)
  }, [message.id, onDismiss])

  return (
    <div className="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card border border-border bg-elevated p-4 text-foreground shadow-overlay">
      <CheckCircle2
        className="mt-0.5 size-5 shrink-0 text-success"
        aria-hidden="true"
      />
      <div className="min-w-0 flex-1">
        <p className="text-caption font-semibold">{message.title}</p>
        {message.description ? (
          <p className="mt-0.5 text-caption text-muted-foreground">
            {message.description}
          </p>
        ) : null}
      </div>
      <button
        type="button"
        onClick={() => onDismiss(message.id)}
        className="rounded-full p-1 text-muted-foreground transition-colors hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        aria-label="Fermer la notification"
      >
        <X className="size-4" aria-hidden="true" />
      </button>
    </div>
  )
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<ToastMessage[]>([])
  const nextId = useRef(0)

  const dismiss = useCallback((id: number) => {
    setMessages((current) => current.filter((message) => message.id !== id))
  }, [])

  const value = useMemo(
    () => ({
      toast: (options: { title: string; description?: string }) => {
        nextId.current += 1
        const message: ToastMessage = { id: nextId.current, ...options }
        // Keep at most three toasts on screen at once.
        setMessages((current) => [...current.slice(-2), message])
      },
    }),
    [],
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        className="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex flex-col items-center gap-2 px-4"
        role="status"
        aria-live="polite"
      >
        {messages.map((message) => (
          <ToastCard key={message.id} message={message} onDismiss={dismiss} />
        ))}
      </div>
    </ToastContext.Provider>
  )
}

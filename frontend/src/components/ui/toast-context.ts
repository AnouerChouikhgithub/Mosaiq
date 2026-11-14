import { createContext, useContext } from 'react'

export interface ToastOptions {
  title: string
  description?: string
}

export interface ToastMessage extends ToastOptions {
  id: number
}

export interface ToastContextValue {
  toast: (options: ToastOptions) => void
}

export const ToastContext = createContext<ToastContextValue | null>(null)

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext)
  if (!context) {
    throw new Error('useToast must be used inside <ToastProvider>')
  }
  return context
}

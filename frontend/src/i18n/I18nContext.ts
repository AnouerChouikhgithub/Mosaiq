import { createContext, useContext } from 'react'
import type { Direction, Locale, TranslationKey } from './dictionary'

export type TranslateParams = Record<string, string | number>

export interface I18nContextValue {
  locale: Locale
  dir: Direction
  setLocale: (locale: Locale) => void
  t: (key: TranslationKey, params?: TranslateParams) => string
}

export const I18nContext = createContext<I18nContextValue | null>(null)

export function useI18n(): I18nContextValue {
  const context = useContext(I18nContext)
  if (!context) {
    throw new Error('useI18n must be used inside <I18nProvider>')
  }
  return context
}

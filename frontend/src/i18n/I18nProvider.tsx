import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  DEFAULT_LOCALE,
  LOCALES,
  LOCALE_STORAGE_KEY,
  dictionaries,
  type Direction,
  type Locale,
  type TranslationKey,
} from './dictionary'
import { I18nContext, type TranslateParams } from './I18nContext'

function readStoredLocale(): Locale {
  try {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    if (stored && LOCALES.some((entry) => entry.code === stored)) {
      return stored as Locale
    }
  } catch {
    // Storage can be unavailable (private mode, embedded webviews).
  }
  return DEFAULT_LOCALE
}

function directionOf(locale: Locale): Direction {
  return LOCALES.find((entry) => entry.code === locale)?.dir ?? 'ltr'
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(readStoredLocale)
  const dir = directionOf(locale)

  // Arabic switches the whole document to RTL; FR/EN stay LTR.
  useEffect(() => {
    const root = document.documentElement
    root.lang = locale
    root.dir = dir
    root.dataset.locale = locale
  }, [locale, dir])

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next)
    try {
      window.localStorage.setItem(LOCALE_STORAGE_KEY, next)
    } catch {
      // Persisting the preference is best-effort only.
    }
  }, [])

  const t = useCallback(
    (key: TranslationKey, params?: TranslateParams) => {
      let value = dictionaries[locale][key] ?? dictionaries.fr[key] ?? key
      if (params) {
        for (const [name, replacement] of Object.entries(params)) {
          value = value.split(`{${name}}`).join(String(replacement))
        }
      }
      return value
    },
    [locale],
  )

  const contextValue = useMemo(
    () => ({ locale, dir, setLocale, t }),
    [locale, dir, setLocale, t],
  )

  return (
    <I18nContext.Provider value={contextValue}>{children}</I18nContext.Provider>
  )
}

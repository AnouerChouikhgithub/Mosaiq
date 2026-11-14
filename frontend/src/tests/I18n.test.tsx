import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '@/i18n/I18nProvider'
import { useI18n } from '@/i18n/I18nContext'

function LocaleProbe() {
  const { locale, dir, setLocale, t } = useI18n()
  return <div><output aria-label="locale">{locale}:{dir}:{t('nav.boards')}</output>{(['fr', 'en', 'ar'] as const).map((item) => <button key={item} onClick={() => setLocale(item)}>{item}</button>)}</div>
}

describe('i18n provider', () => {
  it('resolves French, English and Arabic and switches document direction', () => {
    render(<I18nProvider><LocaleProbe /></I18nProvider>)
    fireEvent.click(screen.getByText('fr'))
    expect(screen.getByLabelText('locale').textContent).toContain('fr:ltr:Tableaux')
    fireEvent.click(screen.getByText('en'))
    expect(screen.getByLabelText('locale').textContent).toContain('en:ltr:Boards')
    fireEvent.click(screen.getByText('ar'))
    expect(screen.getByLabelText('locale').textContent).toContain('ar:rtl:اللوحات')
    expect(document.documentElement.dir).toBe('rtl')
  })
})

import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ThemeProvider } from '@/theme/ThemeProvider'
import { useTheme } from '@/theme/ThemeContext'

function ThemeProbe() {
  const { mode, resolved, setMode } = useTheme()
  return <div><output aria-label="theme">{mode}:{resolved}</output><button onClick={() => setMode('light')}>light</button><button onClick={() => setMode('dark')}>dark</button><button onClick={() => setMode('system')}>system</button></div>
}

describe('theme provider', () => {
  it('supports light, dark, and system modes', () => {
    window.matchMedia = window.matchMedia ?? (() => ({
      matches: false,
      media: '(prefers-color-scheme: dark)',
      onchange: null,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      addListener: () => undefined,
      removeListener: () => undefined,
      dispatchEvent: () => false,
    }) as MediaQueryList)
    render(<ThemeProvider><ThemeProbe /></ThemeProvider>)
    fireEvent.click(screen.getByText('light'))
    expect(screen.getByLabelText('theme').textContent).toMatch(/^light:/)
    fireEvent.click(screen.getByText('dark'))
    expect(screen.getByLabelText('theme').textContent).toBe('dark:dark')
    fireEvent.click(screen.getByText('system'))
    expect(screen.getByLabelText('theme').textContent).toMatch(/^system:/)
  })
})

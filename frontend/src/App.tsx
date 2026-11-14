import { useCallback, useEffect, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { CommandPalette } from '@/components/command/CommandPalette'
import { ToastProvider } from '@/components/ui/toast'
import { TooltipProvider } from '@/components/ui/tooltip'
import { I18nProvider } from '@/i18n'
import { ThemeProvider } from '@/theme/ThemeProvider'
import { getSessionToken } from '@/api/client'
import { PublicBoardScreen } from '@/screens/PublicBoardScreen'
import { BoardEditorScreen } from '@/screens/BoardEditorScreen'
import { DashboardScreen } from '@/screens/DashboardScreen'
import { SettingsScreen } from '@/screens/SettingsScreen'
import { SignInScreen } from '@/screens/SignInScreen'
import { TimeTravelScreen } from '@/screens/TimeTravelScreen'

function AppRoutes() {
  const [paletteOpen, setPaletteOpen] = useState(false)
  const openPalette = useCallback(() => setPaletteOpen(true), [])
  // Guards read the session token; subscribe to location so they re-evaluate after sign-in.
  const location = useLocation()

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setPaletteOpen((open) => !open)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  return (
    <>
      <Routes location={location}>
        <Route path="/sign-in" element={<SignInScreen />} />
        <Route path="/share/:boardId" element={<PublicBoardScreen />} />
        <Route
          path="/"
          element={getSessionToken() ? <DashboardScreen onOpenPalette={openPalette} /> : <Navigate to="/sign-in" replace />}
        />
        <Route
          path="/settings"
          element={getSessionToken() ? <SettingsScreen onOpenPalette={openPalette} /> : <Navigate to="/sign-in" replace />}
        />
        <Route
          path="/board/:boardId"
          element={getSessionToken() ? <BoardEditorScreen onOpenPalette={openPalette} /> : <PublicBoardScreen />}
        />
        <Route path="/board/:boardId/replay" element={getSessionToken() ? <TimeTravelScreen /> : <Navigate to="/sign-in" replace />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
      <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
    </>
  )
}

export default function App() {
  return (
    <I18nProvider>
      <ThemeProvider>
        <TooltipProvider delayDuration={350}>
          <ToastProvider>
            <BrowserRouter>
              <AppRoutes />
            </BrowserRouter>
          </ToastProvider>
        </TooltipProvider>
      </ThemeProvider>
    </I18nProvider>
  )
}

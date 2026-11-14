import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { BoardCanvas } from '@/components/board/BoardCanvas'
import { HeaderControls } from '@/components/layout/HeaderControls'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { getPublicBoard, toStickyNote, type ApiBoard } from '@/api/client'
import type { StickyNote } from '@/mocks'
import { useI18n } from '@/i18n'

export function PublicBoardScreen() {
  const { boardId = '' } = useParams()
  const { t } = useI18n()
  const [board, setBoard] = useState<ApiBoard | null>(null)
  const [notes, setNotes] = useState<StickyNote[]>([])
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false
    void getPublicBoard(boardId)
      .then((loadedBoard: ApiBoard) => {
        if (cancelled) return
        if (!loadedBoard.publicAccess) {
          setError(true)
          return
        }
        setBoard(loadedBoard)
        const elements = loadedBoard.elements ?? []
        setNotes(elements.filter((element) => element.type === 'note').map(toStickyNote))
      })
      .catch(() => { if (!cancelled) setError(true) })
    return () => { cancelled = true }
  }, [boardId])

  return (
    <main className="flex h-svh flex-col bg-background">
      <header className="flex h-16 shrink-0 items-center gap-3 border-b border-border bg-surface px-4">
        <h1 className="min-w-0 flex-1 truncate text-title">{board?.title ?? 'Mosaiq'}</h1>
        {!error ? (
          <Badge variant="neutral" className="hidden shrink-0 sm:inline-flex">{t('publicBoard.readOnly')}</Badge>
        ) : null}
        <HeaderControls />
        <Button asChild variant="secondary" size="sm"><Link to="/sign-in">{t('publicBoard.signInToEdit')}</Link></Button>
      </header>
      {error ? (
        <section className="m-auto max-w-md px-6 text-center" role="alert">
          <h2 className="text-title">{t('publicBoard.unavailable')}</h2>
          <p className="mt-2 text-sm text-muted-foreground">{t('publicBoard.unavailableDescription')}</p>
        </section>
      ) : (
        <div className="min-h-0 flex-1">
          <BoardCanvas notes={notes} connectors={[]} cursors={[]} frame={{ id: 'public', label: '', x: 40, y: 40, width: 1320, height: 800 }} showFrame={false} />
        </div>
      )}
    </main>
  )
}

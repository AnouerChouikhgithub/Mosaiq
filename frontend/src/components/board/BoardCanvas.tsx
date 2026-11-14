import { MousePointer2 } from 'lucide-react'
import { useRef, useState } from 'react'
import type { ReactNode } from 'react'
import type {
  CanvasFrame,
  Connector,
  LiveCursor,
  StickyNote,
} from '@/mocks'
import { users } from '@/mocks'
import { cn } from '@/lib/utils'
import {
  accentClasses,
  noteAlignClasses,
  noteClasses,
  noteStrokeClasses,
  noteTextSizeClasses,
} from './colors'

export const CANVAS_WIDTH = 1400
export const CANVAS_HEIGHT = 880

const userById = new Map(users.map((user) => [user.id, user]))

function initials(name: string) {
  return name
    .split(' ')
    .filter(Boolean)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .slice(0, 2)
    .join('')
}

function centerOf(note: StickyNote) {
  return { x: note.x + note.width / 2, y: note.y + note.height / 2 }
}

function clamp(value: number, min: number, max: number) {
  return Math.min(Math.max(value, min), Math.max(min, max))
}

function connectorPath(connector: Connector, notes: StickyNote[]) {
  const from = notes.find((note) => note.id === connector.fromId)
  const to = notes.find((note) => note.id === connector.toId)
  if (!from || !to) return null

  const start = centerOf(from)
  const end = centerOf(to)
  const bend = (end.x - start.x) * 0.45

  return `M ${start.x} ${start.y} C ${start.x + bend} ${start.y}, ${end.x - bend} ${end.y}, ${end.x} ${end.y}`
}

interface DragState {
  id: string
  pointerId: number
  clientX: number
  clientY: number
  originX: number
  originY: number
  x: number
  y: number
  moved: boolean
}

export interface BoardCanvasProps {
  notes: StickyNote[]
  connectors: Connector[]
  cursors: LiveCursor[]
  frame: CanvasFrame
  zoom?: number
  showCursors?: boolean
  showFrame?: boolean
  noteOpacity?: number
  className?: string
  children?: ReactNode
  /** Providing a selection handler makes the notes interactive. */
  selectedNoteId?: string | null
  onSelectNote?: (id: string | null) => void
  /** Active editor tool: with `note`, clicking the canvas creates a note. */
  tool?: 'select' | 'note' | 'comment'
  onCreateNote?: (position: { x: number; y: number }) => void
  onCreateComment?: (x: number, y: number) => void
  onMoveNote?: (id: string, position: { x: number; y: number }) => void
  onEditNote?: (id: string, text: string) => void
}

export function BoardCanvas({
  notes,
  connectors,
  cursors,
  frame,
  zoom = 1,
  showCursors = true,
  showFrame = true,
  noteOpacity,
  className,
  children,
  selectedNoteId,
  onSelectNote,
  tool = 'select',
  onCreateNote,
  onCreateComment,
  onMoveNote,
  onEditNote,
}: BoardCanvasProps) {
  const interactive = Boolean(onSelectNote)
  const [editing, setEditing] = useState<{ id: string; text: string } | null>(
    null,
  )
  const [drag, setDrag] = useState<{ id: string; x: number; y: number } | null>(
    null,
  )
  const dragState = useRef<DragState | null>(null)

  return (
    <div
      className={cn(
        'relative h-full w-full overflow-hidden bg-canvas',
        className,
      )}
      style={{
        backgroundImage:
          'radial-gradient(var(--grid-dot) 1px, transparent 1px)',
        backgroundSize: '24px 24px',
      }}
      role={interactive ? 'group' : 'img'}
      aria-label={
        interactive
          ? `Canevas du tableau : ${notes.length} notes`
          : `Aperçu du tableau : ${notes.length} notes`
      }
    >
      <div
        className={cn(
          'absolute top-0 left-0 origin-top-left',
          interactive && (tool === 'note' || tool === 'comment') && 'cursor-crosshair',
        )}
        style={{
          width: CANVAS_WIDTH,
          height: CANVAS_HEIGHT,
          transform: `scale(${zoom})`,
        }}
        onClick={(event) => {
          if (!interactive || event.target !== event.currentTarget) return

          if (tool === 'note' && onCreateNote) {
            const bounds = event.currentTarget.getBoundingClientRect()
            onCreateNote({
              x: (event.clientX - bounds.left) / zoom,
              y: (event.clientY - bounds.top) / zoom,
            })
            return
          }

          if (tool === 'comment' && onCreateComment) {
            const bounds = event.currentTarget.getBoundingClientRect()
            onCreateComment((event.clientX - bounds.left) / zoom, (event.clientY - bounds.top) / zoom)
            return
          }

          onSelectNote?.(null)
        }}
      >
        {showFrame ? (
          <div
            className="pointer-events-none absolute rounded-panel border border-dashed border-border-strong"
            style={{
              left: frame.x,
              top: frame.y,
              width: frame.width,
              height: frame.height,
            }}
          >
            <span className="absolute -top-3 left-4 rounded-full border border-border bg-surface px-2.5 py-1 text-[11px] font-medium text-muted-foreground">
              {frame.label}
            </span>
          </div>
        ) : null}

        <svg
          className="pointer-events-none absolute inset-0"
          width={CANVAS_WIDTH}
          height={CANVAS_HEIGHT}
          aria-hidden="true"
        >
          <defs>
            <marker
              id="mosaiq-connector-arrow"
              viewBox="0 0 10 10"
              refX="8"
              refY="5"
              markerWidth="6"
              markerHeight="6"
              orient="auto-start-reverse"
            >
              <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--border-strong)" />
            </marker>
          </defs>
          {connectors.map((connector) => {
            const path = connectorPath(connector, notes)
            if (!path) return null
            return (
              <path
                key={connector.id}
                d={path}
                fill="none"
                stroke="var(--border-strong)"
                strokeWidth={2}
                strokeDasharray={connector.dashed ? '7 7' : undefined}
                markerEnd="url(#mosaiq-connector-arrow)"
              />
            )
          })}
        </svg>

        {notes.map((note) => {
          const author = userById.get(note.authorId)
          const tone = noteClasses[note.color]
          const selected = interactive && note.id === selectedNoteId
          const editingNote = editing?.id === note.id
          const position =
            drag?.id === note.id ? drag : { x: note.x, y: note.y }

          const className = cn(
            'absolute flex flex-col rounded-lg border p-3.5 text-left shadow-card outline-none transition-[border-color,box-shadow] duration-150 ease-out',
            tone.surface,
            tone.ink,
            noteStrokeClasses[note.stroke],
            interactive &&
              !editingNote &&
              'cursor-grab touch-none hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing',
            selected &&
              'ring-2 ring-primary ring-offset-2 ring-offset-canvas',
          )

          const style = {
            left: position.x,
            top: position.y,
            width: note.width,
            height: note.height,
            transform: `rotate(${note.rotation}deg)`,
            opacity: noteOpacity,
          }

          return (
            <article
              key={note.id}
              className={className}
              style={style}
              role={interactive && !editingNote ? 'button' : undefined}
              tabIndex={interactive && !editingNote ? 0 : undefined}
              aria-pressed={interactive && !editingNote ? selected : undefined}
              aria-label={
                interactive && !editingNote
                  ? `Sélectionner la note : ${note.text}`
                  : undefined
              }
              onClick={
                interactive
                  ? (event) => {
                      event.stopPropagation()
                      onSelectNote?.(note.id)
                    }
                  : undefined
              }
              onDoubleClick={
                interactive && onEditNote
                  ? (event) => {
                      event.stopPropagation()
                      setEditing({ id: note.id, text: note.text })
                    }
                  : undefined
              }
              onPointerDown={
                interactive && !editingNote
                  ? (event) => {
                      event.stopPropagation()
                      onSelectNote?.(note.id)

                      if (!onMoveNote) return

                      event.currentTarget.setPointerCapture(event.pointerId)
                      dragState.current = {
                        id: note.id,
                        pointerId: event.pointerId,
                        clientX: event.clientX,
                        clientY: event.clientY,
                        originX: note.x,
                        originY: note.y,
                        x: note.x,
                        y: note.y,
                        moved: false,
                      }
                    }
                  : undefined
              }
              onPointerMove={
                interactive && onMoveNote
                  ? (event) => {
                      const state = dragState.current
                      if (!state || state.id !== note.id) return

                      const deltaX = (event.clientX - state.clientX) / zoom
                      const deltaY = (event.clientY - state.clientY) / zoom
                      if (
                        !state.moved &&
                        (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3)
                      ) {
                        state.moved = true
                      }
                      if (!state.moved) return

                      state.x = clamp(
                        state.originX + deltaX,
                        0,
                        CANVAS_WIDTH - note.width,
                      )
                      state.y = clamp(
                        state.originY + deltaY,
                        0,
                        CANVAS_HEIGHT - note.height,
                      )
                      setDrag({ id: note.id, x: state.x, y: state.y })
                    }
                  : undefined
              }
              onPointerUp={
                interactive && onMoveNote
                  ? (event) => {
                      const state = dragState.current
                      if (!state || state.id !== note.id) return

                      dragState.current = null
                      setDrag(null)
                      if (
                        event.currentTarget.hasPointerCapture(event.pointerId)
                      ) {
                        event.currentTarget.releasePointerCapture(
                          event.pointerId,
                        )
                      }
                      if (state.moved) {
                        onMoveNote(note.id, { x: state.x, y: state.y })
                      }
                    }
                  : undefined
              }
              onPointerCancel={() => {
                dragState.current = null
                setDrag(null)
              }}
              onKeyDown={
                interactive && !editingNote
                  ? (event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault()
                        onSelectNote?.(note.id)
                      }
                    }
                  : undefined
              }
            >
              {editingNote ? (
                <textarea
                  autoFocus
                  value={editing.text}
                  aria-label="Texte de la note"
                  onChange={(event) =>
                    setEditing({ id: note.id, text: event.target.value })
                  }
                  onBlur={(event) => {
                    onEditNote?.(note.id, event.currentTarget.value.trim())
                    setEditing(null)
                  }}
                  onClick={(event) => event.stopPropagation()}
                  onPointerDown={(event) => event.stopPropagation()}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                      event.preventDefault()
                      event.currentTarget.blur()
                    }
                  }}
                  className={cn(
                    'h-full w-full resize-none bg-transparent font-medium outline-none',
                    noteTextSizeClasses[note.textSize],
                    noteAlignClasses[note.align],
                  )}
                />
              ) : (
                <p
                  className={cn(
                    'line-clamp-4 font-medium',
                    noteTextSizeClasses[note.textSize],
                    noteAlignClasses[note.align],
                  )}
                >
                  {note.text}
                </p>
              )}
              <div className="mt-auto flex items-center gap-1.5 pt-2">
                <span className="flex size-5 items-center justify-center rounded-full text-[10px] font-semibold ring-1 ring-border-strong/60">
                  {author ? initials(author.name) : '?'}
                </span>
                <span className="truncate text-[11px] font-medium opacity-70">
                  {author?.name ?? 'Invité'}
                </span>
              </div>
            </article>
          )
        })}

        {children}

        {showCursors
          ? cursors.map((cursor) => {
              const user = userById.get(cursor.userId)
              const accent = accentClasses[cursor.accent ?? user?.accent ?? 'cursor-1']
              const name = cursor.name ?? user?.name
              if (!name) return null

              return (
                <div
                  key={cursor.userId}
                  className="absolute z-20 flex items-start"
                  style={{ left: cursor.x, top: cursor.y }}
                >
                  <MousePointer2
                    className={cn('size-4 -rotate-12', accent.dot)}
                    fill="currentColor"
                    aria-hidden="true"
                  />
                  <span
                    className={cn(
                      'mt-3 ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                      accent.chip,
                    )}
                  >
                    {name.split(' ')[0]}
                  </span>
                </div>
              )
            })
          : null}
      </div>
    </div>
  )
}

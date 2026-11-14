import { describe, expect, it } from 'vitest'
import { toStickyNote, type ApiCanvasElement } from '@/api/client'
import { noteClasses } from '@/components/board/colors'

const element: ApiCanvasElement = {
  id: 'remote-id', type: 'note', x: 48, y: 96, width: 220, height: 140,
  rotation: 0, content: 'Coordinate test', color: 'sky',
  metadata: { stroke: 'thin', textSize: 'l', align: 'center' },
  author: { id: 'author', name: 'Test Person', email: 'test@example.test', role: 'Editor', city: 'Tunis', accent: 'cursor-2' },
  createdAt: '2026-01-01T00:00:00Z',
}

describe('canvas API mapping', () => {
  it('preserves board coordinates and applies the requested color class', () => {
    const note = toStickyNote(element)
    expect(note).toMatchObject({ x: 48, y: 96, width: 220, height: 140, color: 'sky' })
    expect(noteClasses[note.color].surface).toBe('bg-note-sky')
  })
})

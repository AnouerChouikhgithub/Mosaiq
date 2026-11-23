import { describe, expect, it } from 'vitest'
import { CANVAS_HEIGHT, CANVAS_WIDTH } from '@/components/board/BoardCanvas'
import {
  AI_LAYOUT_NOTE,
  AI_LAYOUT_SAFE_AREA,
  layoutNotesOverlap,
  layoutProposalSections,
} from '@/features/ai/layout'

/**
 * Contract §7.3 was replaced in Sprint 2 because its formula placed notes
 * outside the real 1400×880 canvas. These tests pin the replacement: exact
 * coordinates for the common case and the invariants for every allowed shape.
 */

const proposalShapes: { label: string; counts: number[] }[] = [
  { label: 'single section, three notes', counts: [3] },
  { label: '4 sections x 3 notes', counts: [3, 3, 3, 3] },
  { label: 'balanced default 3 sections', counts: [5, 5, 5] },
  { label: 'maximum 5 sections x 5 notes', counts: [5, 5, 5, 5, 5] },
  { label: 'skewed maximum', counts: [10, 10, 1, 1, 3] },
  { label: 'single saturated section', counts: [10] },
]

describe('AI proposal layout', () => {
  it('places a 4x3 proposal on the reviewed grid', () => {
    const layout = layoutProposalSections([3, 3, 3, 3])

    expect(layout.columns).toBe(4)
    expect(layout.rows).toBe(4)
    expect(layout.noteWidth).toBe(220)
    expect(layout.noteHeight).toBe(140)
    expect(layout.notes).toHaveLength(12)

    // origin x = 80 + (1240 - 940)/2, origin y = 160 + (680 - 656)/2
    expect(layout.notes[0]).toMatchObject({ x: 230, y: 172, width: 220, height: 140 })
    expect(layout.notes[1]).toMatchObject({ x: 470, y: 172 })
    expect(layout.notes[2]).toMatchObject({ x: 710, y: 172 })
    // The second section starts a new band with the section gap.
    expect(layout.notes[3]).toMatchObject({ x: 230, y: 344 })
    expect(layout.notes[3].sectionIndex).toBe(1)
  })

  it('is deterministic for the same note counts', () => {
    expect(layoutProposalSections([4, 6, 2])).toEqual(layoutProposalSections([4, 6, 2]))
  })

  it.each(proposalShapes)('stays inside the canvas with no overlap: $label', ({ counts }) => {
    const layout = layoutProposalSections(counts)

    expect(layout.notes).toHaveLength(counts.reduce((sum, count) => sum + count, 0))
    expect(layout.noteWidth).toBeGreaterThanOrEqual(AI_LAYOUT_NOTE.minWidth)
    expect(layout.noteHeight).toBeGreaterThanOrEqual(AI_LAYOUT_NOTE.minHeight)

    for (const note of layout.notes) {
      expect(note.x).toBeGreaterThanOrEqual(AI_LAYOUT_SAFE_AREA.left)
      expect(note.y).toBeGreaterThanOrEqual(AI_LAYOUT_SAFE_AREA.top)
      expect(note.x + note.width).toBeLessThanOrEqual(
        AI_LAYOUT_SAFE_AREA.left + AI_LAYOUT_SAFE_AREA.width,
      )
      expect(note.y + note.height).toBeLessThanOrEqual(
        AI_LAYOUT_SAFE_AREA.top + AI_LAYOUT_SAFE_AREA.height,
      )
      expect(note.x + note.width).toBeLessThanOrEqual(CANVAS_WIDTH)
      expect(note.y + note.height).toBeLessThanOrEqual(CANVAS_HEIGHT)
      expect(Number.isInteger(note.x) && Number.isInteger(note.y)).toBe(true)
    }

    for (let first = 0; first < layout.notes.length; first += 1) {
      for (let second = first + 1; second < layout.notes.length; second += 1) {
        expect(layoutNotesOverlap(layout.notes[first], layout.notes[second])).toBe(false)
      }
    }
  })

  it('groups notes by section in band order', () => {
    const layout = layoutProposalSections([4, 1, 2])
    const sectionIndexes = layout.notes.map((note) => note.sectionIndex)

    expect(sectionIndexes).toEqual([0, 0, 0, 0, 1, 2, 2])
    expect(layout.notes[4].y).toBeGreaterThan(layout.notes[0].y)
  })

  it('does not invent a board when there is nothing to place', () => {
    const layout = layoutProposalSections([0, 0])

    expect(layout.notes).toEqual([])
    expect(layout.columns).toBe(0)
    expect(layout.noteWidth).toBe(0)
  })
})

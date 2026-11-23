import { CANVAS_HEIGHT, CANVAS_WIDTH } from '@/components/board/BoardCanvas'

/**
 * Deterministic layout for an approved proposal (contract §7.3, revised in
 * Sprint 2).
 *
 * Sprint 1B shipped the contract's first formula: one column per section, one
 * row per note (`x = 80 + s * 268`, `y = 160 + n * 164`). On Mosaiq's real
 * 1400×880 canvas that places the tenth note of a section at y ≈ 1636, far
 * outside the usable area, so the formula and the requirement "nothing outside
 * the canvas" could not both hold. This module replaces the formula with one
 * that fits every proposal the contract allows (≤ 5 sections, ≤ 25 notes) while
 * keeping the editor's conventions: top-left origin, positive float
 * coordinates, 220×140 notes at the default size and no rotation.
 *
 * The algorithm is a pure function of the note counts, so the preview and the
 * import path cannot disagree and tests can assert exact coordinates:
 *
 * 1. `columns = clamp(ceil(sqrt(totalNotes)), 1, 5)`.
 * 2. Every section starts a new band (clear vertical separation); its notes flow
 *    left to right and wrap after `columns`.
 * 3. Note size is the largest that fits the band count in the safe area, capped
 *    at 220×140 so small proposals keep the usual note size.
 * 4. The whole block is centred in the safe area.
 *
 * The safe area is inset from the canvas edges so note shadows and the editor's
 * frame are not clipped: x 80‥1320, y 160‥840.
 */

export const AI_LAYOUT_SAFE_AREA = {
  left: 80,
  top: 160,
  width: CANVAS_WIDTH - 160,
  height: CANVAS_HEIGHT - 200,
} as const

export const AI_LAYOUT_GAPS = {
  column: 20,
  row: 16,
  /** Extra vertical space before a new section band. */
  section: 32,
} as const

export const AI_LAYOUT_NOTE = {
  maxWidth: 220,
  maxHeight: 140,
  /** Bounds asserted by the layout tests for every contract-valid proposal. */
  minWidth: 140,
  minHeight: 72,
} as const

export interface ProposalLayoutNote {
  sectionIndex: number
  noteIndex: number
  x: number
  y: number
  width: number
  height: number
}

export interface ProposalLayout {
  columns: number
  rows: number
  noteWidth: number
  noteHeight: number
  /** Size of the placed block, used to reason about the viewport. */
  width: number
  height: number
  notes: ProposalLayoutNote[]
}

const emptyLayout: ProposalLayout = {
  columns: 0,
  rows: 0,
  noteWidth: 0,
  noteHeight: 0,
  width: 0,
  height: 0,
  notes: [],
}

/**
 * Places the notes of `noteCounts` (one entry per non-empty section).
 *
 * All results are integers: the API stores floats, but whole pixels keep the
 * preview, the tests and a reloaded board byte-identical.
 */
export function layoutProposalSections(
  noteCounts: readonly number[],
): ProposalLayout {
  const counts = noteCounts.filter((count) => count > 0)
  const total = counts.reduce((sum, count) => sum + count, 0)
  if (total === 0) return emptyLayout

  const columns = Math.min(
    5,
    Math.max(1, Math.ceil(Math.sqrt(total))),
  )
  const rowsBySection = counts.map((count) => Math.ceil(count / columns))
  const rows = rowsBySection.reduce((sum, count) => sum + count, 0)

  // Vertical gaps: one between consecutive rows of a band, a wider one between
  // bands. Both are removed from the available height before sizing the notes.
  const sectionBreaks = Math.max(0, counts.length - 1)
  const bandGaps = (rows - counts.length) * AI_LAYOUT_GAPS.row
  const sectionGaps = sectionBreaks * AI_LAYOUT_GAPS.section
  const totalHeight = AI_LAYOUT_SAFE_AREA.height - bandGaps - sectionGaps

  const noteHeight = Math.max(
    1,
    Math.min(AI_LAYOUT_NOTE.maxHeight, Math.floor(totalHeight / rows)),
  )
  const noteWidth = Math.max(
    1,
    Math.min(
      AI_LAYOUT_NOTE.maxWidth,
      Math.floor(
        (AI_LAYOUT_SAFE_AREA.width -
          (columns - 1) * AI_LAYOUT_GAPS.column) /
          columns,
      ),
    ),
  )

  const blockWidth =
    columns * noteWidth + (columns - 1) * AI_LAYOUT_GAPS.column
  const blockHeight =
    rows * noteHeight + bandGaps + sectionGaps
  const originX = Math.round(
    AI_LAYOUT_SAFE_AREA.left + (AI_LAYOUT_SAFE_AREA.width - blockWidth) / 2,
  )
  const originY = Math.round(
    AI_LAYOUT_SAFE_AREA.top + (AI_LAYOUT_SAFE_AREA.height - blockHeight) / 2,
  )

  const notes: ProposalLayoutNote[] = []
  let bandTop = originY

  counts.forEach((count, sectionIndex) => {
    const sectionRows = rowsBySection[sectionIndex]
    for (let noteIndex = 0; noteIndex < count; noteIndex += 1) {
      const row = Math.floor(noteIndex / columns)
      const column = noteIndex % columns
      notes.push({
        sectionIndex,
        noteIndex,
        x: originX + column * (noteWidth + AI_LAYOUT_GAPS.column),
        y: bandTop + row * (noteHeight + AI_LAYOUT_GAPS.row),
        width: noteWidth,
        height: noteHeight,
      })
    }
    bandTop +=
      sectionRows * noteHeight +
      Math.max(0, sectionRows - 1) * AI_LAYOUT_GAPS.row +
      AI_LAYOUT_GAPS.section
  })

  return {
    columns,
    rows,
    noteWidth,
    noteHeight,
    width: blockWidth,
    height: blockHeight,
    notes,
  }
}

/** True when two placed notes share any surface (they must never overlap). */
export function layoutNotesOverlap(
  first: Pick<ProposalLayoutNote, 'x' | 'y' | 'width' | 'height'>,
  second: Pick<ProposalLayoutNote, 'x' | 'y' | 'width' | 'height'>,
): boolean {
  return (
    first.x < second.x + second.width &&
    second.x < first.x + first.width &&
    first.y < second.y + second.height &&
    second.y < first.y + first.height
  )
}

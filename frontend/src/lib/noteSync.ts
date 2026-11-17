import { isRemoteId } from '@/api/client'
import type { StickyNote } from '@/mocks'

/**
 * Server notes always win for ids the API owns. Local-only notes (saving or
 * failed) are appended so a poll or stream refresh never erases work that has
 * not reached the API yet. Once a note's temporary id was replaced by the
 * server id it is no longer "local", so it cannot be duplicated here.
 */
export function mergeSyncedNotes(
  current: StickyNote[],
  synced: StickyNote[],
): StickyNote[] {
  return [...synced, ...current.filter((note) => !isRemoteId(note.id))]
}

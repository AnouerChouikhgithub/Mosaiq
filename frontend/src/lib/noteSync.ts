import { isRemoteId } from '@/api/client'
import type { StickyNote } from '@/mocks'

/**
 * Server notes always win for ids the API owns. Local-only notes (saving or
 * failed) are appended so a poll or stream refresh never erases work that has
 * not reached the API yet. Once a note's temporary id was replaced by the
 * server id it is no longer "local", so it cannot be duplicated here.
 *
 * A failed note also disappears once the server reports an element carrying its
 * mutation id: the create was committed after all and only the response was
 * lost, so the server copy replaces the local one instead of showing it twice.
 */
export function mergeSyncedNotes(
  current: StickyNote[],
  synced: StickyNote[],
): StickyNote[] {
  const syncedMutations = new Set(
    synced
      .map((note) => note.mutationId)
      .filter((mutationId): mutationId is string => Boolean(mutationId)),
  )

  return [
    ...synced,
    ...current.filter(
      (note) =>
        !isRemoteId(note.id) &&
        !(note.mutationId && syncedMutations.has(note.mutationId)),
    ),
  ]
}

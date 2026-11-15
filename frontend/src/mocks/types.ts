/**
 * Typed mock data shared by every screen. Nothing here talks to a backend:
 * the API contract will replace this module once persistence exists.
 */

export type WorkspaceRole = 'Owner' | 'Admin' | 'Editor' | 'Commenter' | 'Viewer'
export type ShareRole = 'Viewer' | 'Commenter' | 'Editor'
export type NoteColor = 'amber' | 'rose' | 'sky' | 'mint' | 'lilac'
export type NoteStroke = 'none' | 'thin' | 'dashed'
export type NoteTextSize = 's' | 'm' | 'l'
export type NoteAlign = 'left' | 'center' | 'right'
export type PresenceAccent = 'cursor-1' | 'cursor-2' | 'cursor-3'

export interface User {
  id: string
  name: string
  email: string
  role: WorkspaceRole
  city: string
  accent: PresenceAccent
}

export interface Workspace {
  id: string
  name: string
  plan: string
  pricePerMember: number
  currency: 'TND'
  seats: number
  renewsOn: string
}

export interface Board {
  id: string
  title: string
  updatedAt: string
  updatedAtLabel: string
  starred: boolean
  memberIds: string[]
  noteColors: NoteColor[]
  isTemplate?: boolean
  /** UI hint only; the backend remains the authorization boundary. */
  canManage?: boolean
}

export interface BoardTemplate {
  id: string
  title: string
  description: string
  noteColors: NoteColor[]
}

export interface StickyNote {
  id: string
  text: string
  authorId: string
  color: NoteColor
  stroke: NoteStroke
  textSize: NoteTextSize
  align: NoteAlign
  x: number
  y: number
  width: number
  height: number
  rotation: number
}

export interface Connector {
  id: string
  fromId: string
  toId: string
  dashed?: boolean
}

export interface CanvasFrame {
  id: string
  label: string
  x: number
  y: number
  width: number
  height: number
}

export interface LiveCursor {
  userId: string
  x: number
  y: number
  name?: string
  accent?: PresenceAccent
}

export interface BoardCanvas {
  frame: CanvasFrame
  notes: StickyNote[]
  connectors: Connector[]
  cursors: LiveCursor[]
}

export type TimelineKind = 'board' | 'note' | 'connector' | 'frame' | 'rename'

export interface TimelineEntry {
  id: string
  userId: string
  authorName?: string
  at: string
  label: string
  kind: TimelineKind
  reveals?: string[]
}

export interface CommentMessage {
  id: string
  authorId: string
  authorName?: string
  body: string
  at: string
}

export interface CommentThread {
  id: string
  x: number
  y: number
  resolved: boolean
  messages: CommentMessage[]
}

export interface TeamMember {
  id: string
  name: string
  email: string
  role: WorkspaceRole
  city: string
  lastActive: string
  accent: PresenceAccent
}

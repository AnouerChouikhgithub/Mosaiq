import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => {
  const owner = {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Amine Ben Salah',
    email: 'amine.bensalah@example.test',
    role: 'Owner' as const,
    status: 'active' as const,
    city: 'Tunis',
    accent: 'cursor-1' as const,
    joinedAt: '2026-01-10T09:00:00Z',
    updatedAt: '2026-01-10T09:00:00Z',
  }
  const editor = {
    id: '11111111-1111-4111-8111-222222222222',
    name: 'Yasmine Ben Salah',
    email: 'yasmine@example.test',
    role: 'Editor' as const,
    status: 'active' as const,
    city: 'Sousse',
    accent: 'cursor-2' as const,
    joinedAt: '2026-02-10T09:00:00Z',
    updatedAt: '2026-02-10T09:00:00Z',
  }
  const viewer = {
    id: '11111111-1111-4111-8111-333333333333',
    name: 'Ons Trabelsi',
    email: 'ons@example.test',
    role: 'Viewer' as const,
    status: 'active' as const,
    city: 'Sfax',
    accent: 'cursor-3' as const,
    joinedAt: '2026-03-10T09:00:00Z',
    updatedAt: '2026-03-10T09:00:00Z',
  }
  return {
    owner,
    editor,
    viewer,
    currentUser: owner as typeof owner | typeof editor | typeof viewer,
    workspace: {
      id: '11111111-1111-4111-8111-444444444444',
      name: 'Studio Carthage',
      plan: 'Pro',
      pricePerMember: 24,
      currency: 'TND',
      seats: 8,
      renewsOn: '2026-12-01',
      memberCount: 3,
      boardCount: 4,
      createdAt: '2026-01-01T09:00:00Z',
    },
    invitation: {
      id: '11111111-1111-4111-8111-555555555555',
      email: 'pending@example.test',
      role: 'Commenter' as const,
      status: 'pending' as const,
      createdAt: '2026-11-10T09:00:00Z',
      expiresAt: '2026-11-17T09:00:00Z',
      acceptedAt: null,
      revokedAt: null,
      invitedBy: { id: '11111111-1111-4111-8111-111111111111', name: 'Amine Ben Salah' },
    },
    getWorkspaces: vi.fn(),
    updateWorkspace: vi.fn(),
    getWorkspaceMembers: vi.fn(),
    updateWorkspaceMember: vi.fn(),
    removeWorkspaceMember: vi.fn(),
    getWorkspaceInvitations: vi.fn(),
    inviteWorkspaceMember: vi.fn(),
    revokeWorkspaceInvitation: vi.fn(),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getSessionUser: () => api.currentUser,
    getSessionWorkspace: () => api.workspace,
    getWorkspaces: api.getWorkspaces,
    updateWorkspace: api.updateWorkspace,
    getWorkspaceMembers: api.getWorkspaceMembers,
    updateWorkspaceMember: api.updateWorkspaceMember,
    removeWorkspaceMember: api.removeWorkspaceMember,
    getWorkspaceInvitations: api.getWorkspaceInvitations,
    inviteWorkspaceMember: api.inviteWorkspaceMember,
    revokeWorkspaceInvitation: api.revokeWorkspaceInvitation,
  }
})

import App from '@/App'

beforeAll(() => {
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
  Element.prototype.scrollIntoView = () => undefined
  window.HTMLElement.prototype.hasPointerCapture = () => false
  window.HTMLElement.prototype.setPointerCapture = () => undefined
  window.HTMLElement.prototype.releasePointerCapture = () => undefined
})

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  window.sessionStorage.setItem('mosaiq-api-token', 'settings-test-token')
  window.history.pushState({}, '', '/settings')
  api.currentUser = api.owner
  api.getWorkspaces.mockResolvedValue([api.workspace])
  api.getWorkspaceMembers.mockResolvedValue([api.owner, api.editor, api.viewer])
  api.getWorkspaceInvitations.mockResolvedValue([api.invitation])
  api.updateWorkspace.mockResolvedValue({ ...api.workspace, name: 'Studio Carthage renommé' })
  api.updateWorkspaceMember.mockResolvedValue({ ...api.editor, role: 'Viewer' })
  api.removeWorkspaceMember.mockResolvedValue(undefined)
  api.inviteWorkspaceMember.mockResolvedValue({
    invitation: { ...api.invitation, email: 'new@example.test' },
    token: 'new-invitation-token',
    emailSent: true,
  })
  api.revokeWorkspaceInvitation.mockResolvedValue(undefined)
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.sessionStorage.clear()
  window.localStorage.clear()
})

describe('workspace settings administration', () => {
  it('loads persisted members and pending invitations for an owner', async () => {
    render(<App />)

    expect(await screen.findByText('Yasmine Ben Salah')).toBeTruthy()
    expect(screen.getByText('pending@example.test')).toBeTruthy()
    expect(screen.getByLabelText('Rôle de Yasmine Ben Salah')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Retirer Yasmine Ben Salah' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Retirer Amine Ben Salah' })).toBeNull()
    expect(api.getWorkspaceInvitations).toHaveBeenCalledWith(api.workspace.id)
  })

  it('persists role changes and removes a member after confirmation', async () => {
    render(<App />)

    const role = await screen.findByLabelText('Rôle de Yasmine Ben Salah')
    fireEvent.change(role, { target: { value: 'Viewer' } })
    await waitFor(() => expect(api.updateWorkspaceMember).toHaveBeenCalledWith(
      api.workspace.id,
      api.editor.id,
      'Viewer',
    ))

    fireEvent.click(screen.getByRole('button', { name: 'Retirer Yasmine Ben Salah' }))
    expect(await screen.findByText('Retirer Yasmine Ben Salah ?')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Retirer le membre' }))
    await waitFor(() => expect(api.removeWorkspaceMember).toHaveBeenCalledWith(
      api.workspace.id,
      api.editor.id,
    ))
    await waitFor(() => expect(screen.queryByText('Yasmine Ben Salah')).toBeNull())
  })

  it('sends an invitation and validates the email before calling the API', async () => {
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Inviter un membre' }))
    const dialog = await screen.findByRole('dialog')
    const email = within(dialog).getByLabelText('Adresse e-mail')
    fireEvent.change(email, { target: { value: 'invalid' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Envoyer l’invitation' }))
    expect(await within(dialog).findByText('Saisissez une adresse e-mail valide.')).toBeTruthy()
    expect(api.inviteWorkspaceMember).not.toHaveBeenCalled()

    fireEvent.change(email, { target: { value: 'new@example.test' } })
    fireEvent.change(within(dialog).getByLabelText('Rôle'), { target: { value: 'Viewer' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Envoyer l’invitation' }))
    await waitFor(() => expect(api.inviteWorkspaceMember).toHaveBeenCalledWith(
      api.workspace.id,
      'new@example.test',
      'Viewer',
    ))
    expect(await screen.findByText('new@example.test')).toBeTruthy()
  })

  it('persists the workspace name and reflects it after reload', async () => {
    render(<App />)

    const name = await screen.findByLabelText('Nom de l’espace')
    fireEvent.change(name, { target: { value: 'Studio Carthage renommé' } })
    fireEvent.click(screen.getByRole('button', { name: /Enregistrer/ }))

    await waitFor(() => expect(api.updateWorkspace).toHaveBeenCalledWith(
      api.workspace.id,
      'Studio Carthage renommé',
    ))
    expect(await screen.findByText('Paramètres enregistrés')).toBeTruthy()
  })

  it('hides administration actions from an ordinary member', async () => {
    api.currentUser = api.viewer
    render(<App />)

    expect(await screen.findByText('Yasmine Ben Salah')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Inviter un membre' })).toBeNull()
    expect(screen.queryByLabelText('Rôle de Yasmine Ben Salah')).toBeNull()
    expect(screen.queryByText('Invitations en attente')).toBeNull()
    expect((screen.getByLabelText('Nom de l’espace') as HTMLInputElement).disabled).toBe(true)
    expect(api.getWorkspaceInvitations).not.toHaveBeenCalled()
  })

  it('shows an error and retry action when loading fails', async () => {
    api.getWorkspaces.mockRejectedValueOnce(new Error('Serveur indisponible'))
    render(<App />)

    expect(await screen.findByText('Impossible de charger cet espace.')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy()
  })

  it('keeps the invitation dialog open when the API rejects it', async () => {
    api.inviteWorkspaceMember.mockRejectedValueOnce(new Error('Action non autorisée.'))
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Inviter un membre' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Adresse e-mail'), { target: { value: 'new@example.test' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Envoyer l’invitation' }))

    expect(await within(dialog).findByText('Action non autorisée.')).toBeTruthy()
    expect(screen.queryByText('Invitation envoyée')).toBeNull()
  })
})

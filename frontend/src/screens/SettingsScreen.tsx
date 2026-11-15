import {
  AlertCircle,
  CreditCard,
  Mail,
  Monitor,
  Moon,
  Save,
  Sun,
  Trash2,
  UserPlus,
  Users,
} from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import {
  getSessionUser,
  getWorkspaceInvitations,
  getWorkspaceMembers,
  getWorkspaces,
  inviteWorkspaceMember,
  rememberSessionWorkspace,
  removeWorkspaceMember,
  revokeWorkspaceInvitation,
  updateWorkspace,
  updateWorkspaceMember,
  type ApiWorkspace,
  type ApiWorkspaceInvitation,
  type ApiWorkspaceMember,
} from '@/api/client'
import { AppShell } from '@/components/layout/AppShell'
import { Avatar } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { EmptyState } from '@/components/ui/empty-state'
import { Field, Input, NativeSelect } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { SegmentedControl } from '@/components/ui/toggle-group'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { useTheme, type ThemeMode } from '@/theme/ThemeContext'

type WorkspaceRole = 'Owner' | 'Admin' | 'Editor' | 'Commenter' | 'Viewer'

const memberRoles: WorkspaceRole[] = ['Editor', 'Commenter', 'Viewer']

function shortDate(value: string, locale: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(date)
}

function editableRoles(actorRole: WorkspaceRole, target: ApiWorkspaceMember, currentUserId?: string): WorkspaceRole[] {
  if (target.id === currentUserId || target.role === 'Owner') return []
  if (actorRole === 'Owner') return ['Admin', ...memberRoles]
  if (actorRole === 'Admin' && memberRoles.includes(target.role)) return memberRoles
  return []
}

export function SettingsScreen({
  onOpenPalette,
}: {
  onOpenPalette: () => void
}) {
  const { mode, resolved, setMode } = useTheme()
  const { toast } = useToast()
  const { t, locale } = useI18n()
  const currentUser = getSessionUser()
  const [workspace, setWorkspace] = useState<ApiWorkspace | null>(null)
  const [members, setMembers] = useState<ApiWorkspaceMember[]>([])
  const [invitations, setInvitations] = useState<ApiWorkspaceInvitation[]>([])
  const [actorRole, setActorRole] = useState<WorkspaceRole>(currentUser?.role ?? 'Viewer')
  const [name, setName] = useState('')
  const [nameError, setNameError] = useState<string | undefined>()
  const [savingName, setSavingName] = useState(false)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | undefined>()
  const [rolePending, setRolePending] = useState<string | null>(null)
  const [removeTarget, setRemoveTarget] = useState<ApiWorkspaceMember | null>(null)
  const [removing, setRemoving] = useState(false)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<WorkspaceRole>('Editor')
  const [inviteError, setInviteError] = useState<string | undefined>()
  const [inviteWarning, setInviteWarning] = useState<string | undefined>()
  const [invitePending, setInvitePending] = useState(false)

  const canManage = actorRole === 'Owner' || actorRole === 'Admin'
  const invitableRoles: WorkspaceRole[] = actorRole === 'Owner' ? ['Admin', ...memberRoles] : memberRoles

  const fetchWorkspace = useCallback(async () => {
    const workspaces = await getWorkspaces()
    const first = workspaces[0]
    if (!first) throw new Error(t('settings.loadFailed'))
    const loadedMembers = await getWorkspaceMembers(first.id)
    const me = loadedMembers.find((member) => member.id === currentUser?.id)
    const role = (me?.role ?? currentUser?.role ?? 'Viewer') as WorkspaceRole
    const loadedInvitations = role === 'Owner' || role === 'Admin'
      ? await getWorkspaceInvitations(first.id)
      : []
    return { workspace: first, members: loadedMembers, role, invitations: loadedInvitations }
  }, [currentUser?.id, currentUser?.role, t])

  const applyWorkspace = useCallback(
    (data: { workspace: ApiWorkspace; members: ApiWorkspaceMember[]; role: WorkspaceRole; invitations: ApiWorkspaceInvitation[] }) => {
      setWorkspace(data.workspace)
      setName(data.workspace.name)
      setMembers(data.members)
      setActorRole(data.role)
      setInvitations(data.invitations)
      rememberSessionWorkspace({
        id: data.workspace.id,
        name: data.workspace.name,
        plan: data.workspace.plan,
        memberCount: data.workspace.memberCount,
      })
    },
    [],
  )

  useEffect(() => {
    let cancelled = false
    fetchWorkspace()
      .then((data) => {
        if (!cancelled) applyWorkspace(data)
      })
      .catch((cause: unknown) => {
        if (!cancelled) setLoadError(cause instanceof Error ? cause.message : t('settings.loadFailed'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [applyWorkspace, fetchWorkspace, t])

  async function load() {
    try {
      applyWorkspace(await fetchWorkspace())
    } catch (cause) {
      setLoadError(cause instanceof Error ? cause.message : t('settings.loadFailed'))
    } finally {
      setLoading(false)
    }
  }

  async function saveName(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!workspace) return
    const value = name.trim()
    if (value.length < 2 || value.length > 120) {
      setNameError(t('settings.workspaceNameError'))
      return
    }
    setNameError(undefined)
    setSavingName(true)
    try {
      const updated = await updateWorkspace(workspace.id, value)
      setWorkspace(updated)
      setName(updated.name)
      rememberSessionWorkspace({ id: updated.id, name: updated.name, plan: updated.plan, memberCount: updated.memberCount })
      toast({ title: t('settings.saved') })
    } catch (cause) {
      setNameError(cause instanceof Error ? cause.message : t('settings.saveFailed'))
    } finally {
      setSavingName(false)
    }
  }

  async function changeRole(member: ApiWorkspaceMember, role: WorkspaceRole) {
    if (!workspace) return
    setRolePending(member.id)
    try {
      const updated = await updateWorkspaceMember(workspace.id, member.id, role)
      setMembers((current) => current.map((item) => (item.id === updated.id ? updated : item)))
      toast({
        title: t('settings.roleUpdated'),
        description: t('settings.roleUpdatedBody', { name: updated.name, role: updated.role.toLowerCase() }),
      })
    } catch (cause) {
      toast({ title: t('settings.roleFailed'), description: cause instanceof Error ? cause.message : undefined })
    } finally {
      setRolePending(null)
    }
  }

  async function confirmRemove() {
    if (!workspace || !removeTarget) return
    setRemoving(true)
    try {
      await removeWorkspaceMember(workspace.id, removeTarget.id)
      setMembers((current) => current.filter((member) => member.id !== removeTarget.id))
      const nextCount = Math.max(0, workspace.memberCount - 1)
      const updatedWorkspace = { ...workspace, memberCount: nextCount }
      setWorkspace(updatedWorkspace)
      rememberSessionWorkspace({ id: updatedWorkspace.id, name: updatedWorkspace.name, plan: updatedWorkspace.plan, memberCount: nextCount })
      toast({ title: t('settings.removed') })
      setRemoveTarget(null)
    } catch (cause) {
      toast({ title: t('settings.removeFailed'), description: cause instanceof Error ? cause.message : undefined })
    } finally {
      setRemoving(false)
    }
  }

  async function submitInvite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!workspace) return
    const email = inviteEmail.trim()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setInviteError(t('settings.inviteInvalidEmail'))
      return
    }
    setInviteError(undefined)
    setInviteWarning(undefined)
    setInvitePending(true)
    try {
      const result = await inviteWorkspaceMember(workspace.id, email, inviteRole)
      setInvitations((current) => [result.invitation, ...current.filter((item) => item.id !== result.invitation.id)])
      setInviteEmail('')
      if (!result.emailSent) {
        const link = `${window.location.origin}/sign-in?workspaceInvite=${encodeURIComponent(result.token)}`
        setInviteWarning(t('settings.inviteEmailFailedBody', { link }))
        toast({ title: t('settings.inviteEmailFailed') })
        return
      }
      toast({ title: t('settings.inviteCreated') })
      setInviteOpen(false)
    } catch (cause) {
      setInviteError(cause instanceof Error ? cause.message : t('settings.inviteFailed'))
    } finally {
      setInvitePending(false)
    }
  }

  async function revoke(invitation: ApiWorkspaceInvitation) {
    if (!workspace) return
    try {
      await revokeWorkspaceInvitation(workspace.id, invitation.id)
      setInvitations((current) => current.filter((item) => item.id !== invitation.id))
      toast({ title: t('settings.revoked') })
    } catch (cause) {
      toast({ title: t('settings.revokeFailed'), description: cause instanceof Error ? cause.message : undefined })
    }
  }

  function canRemove(member: ApiWorkspaceMember): boolean {
    if (!canManage || member.id === currentUser?.id || member.role === 'Owner') return false
    return actorRole === 'Owner' || memberRoles.includes(member.role)
  }

  const pendingInvitations = invitations.filter((invitation) => invitation.status === 'pending')
  const description = workspace
    ? t('settings.description', {
        workspace: workspace.name,
        count: workspace.memberCount,
        plan: workspace.plan,
      })
    : undefined

  return (
    <AppShell
      activeView="settings"
      title={t('settings.title')}
      description={description}
      onOpenPalette={onOpenPalette}
      actions={
        canManage && workspace ? (
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              setInviteError(undefined)
              setInviteWarning(undefined)
              setInviteOpen(true)
            }}
          >
            <UserPlus className="size-4" aria-hidden="true" />
            {t('settings.invite')}
          </Button>
        ) : null
      }
    >
      {loading ? (
        <div className="mt-6 flex max-w-4xl flex-col gap-4" aria-busy="true">
          <Skeleton className="h-40 w-full" />
          <Skeleton className="h-52 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : loadError || !workspace ? (
        <EmptyState
          icon={AlertCircle}
          title={t('settings.loadFailed')}
          description={loadError ?? t('settings.loadFailed')}
          action={
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setLoading(true)
                setLoadError(undefined)
                void load()
              }}
            >
              {t('settings.retry')}
            </Button>
          }
          className="mt-6 bg-surface"
        />
      ) : (
        <div className="mt-6 flex max-w-4xl flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.workspace')}</CardTitle>
              <CardDescription>{t('settings.workspaceDesc')}</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={saveName} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <Field
                  label={t('settings.workspaceName')}
                  htmlFor="workspace-name"
                  error={nameError}
                  className="flex-1"
                >
                  <Input
                    id="workspace-name"
                    value={name}
                    onChange={(event) => {
                      setName(event.target.value)
                      if (nameError) setNameError(undefined)
                    }}
                    maxLength={120}
                    disabled={!canManage}
                    aria-invalid={Boolean(nameError)}
                  />
                </Field>
                {canManage ? (
                  <Button type="submit" loading={savingName} className="sm:mb-0">
                    <Save className="size-4" aria-hidden="true" />
                    {t('settings.save')}
                  </Button>
                ) : null}
              </form>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <CardTitle>{t('settings.appearance')}</CardTitle>
                  <CardDescription>{t('settings.appearanceDesc')}</CardDescription>
                </div>
                <Badge variant="outline">
                  {t('settings.activeMode', {
                    mode: resolved === 'dark' ? t('settings.dark') : t('settings.light'),
                  })}
                </Badge>
              </div>
            </CardHeader>
            <CardContent>
              <SegmentedControl<ThemeMode>
                value={mode}
                onValueChange={setMode}
                label={t('settings.themeLabel')}
                options={[
                  { value: 'light', label: t('settings.theme.light'), icon: <Sun className="size-4" aria-hidden="true" /> },
                  { value: 'dark', label: t('settings.theme.dark'), icon: <Moon className="size-4" aria-hidden="true" /> },
                  { value: 'system', label: t('settings.theme.system'), icon: <Monitor className="size-4" aria-hidden="true" /> },
                ]}
              />
              <p className="mt-3 text-caption text-muted-foreground">{t('settings.systemHint')}</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <CardTitle>{t('settings.plan')}</CardTitle>
                  <CardDescription>
                    {t('settings.planDesc', {
                      plan: workspace.plan,
                      seats: workspace.seats,
                      date: workspace.renewsOn,
                    })}
                  </CardDescription>
                </div>
                <Badge variant="success">{t('settings.active')}</Badge>
              </div>
            </CardHeader>
            <CardContent className="flex flex-wrap items-end justify-between gap-4">
              <div>
                <p className="text-display tabular">
                  {workspace.pricePerMember} <span className="text-title">{workspace.currency}</span>
                </p>
                <p className="text-caption text-muted-foreground">{t('settings.perMember')}</p>
              </div>
              <Button
                type="button"
                variant="secondary"
                onClick={() => toast({ title: t('settings.billingDisconnected'), description: t('settings.billingBody') })}
              >
                <CreditCard className="size-4" aria-hidden="true" />
                {t('settings.manageBilling')}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.members')}</CardTitle>
              <CardDescription>{t('settings.membersDesc')}</CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto px-0 pb-0">
              {members.length === 0 ? (
                <EmptyState
                  icon={Users}
                  title={t('settings.membersEmpty')}
                  description={t('settings.membersEmptyDesc')}
                  className="mx-5 mb-5"
                />
              ) : (
                <table className="w-full min-w-[720px] text-left">
                  <thead>
                    <tr className="border-y border-border text-caption text-muted-foreground">
                      <th scope="col" className="px-5 py-2 font-medium">{t('settings.member')}</th>
                      <th scope="col" className="px-3 py-2 font-medium">{t('settings.city')}</th>
                      <th scope="col" className="px-3 py-2 font-medium">{t('settings.joined')}</th>
                      <th scope="col" className="px-3 py-2 font-medium">{t('settings.role')}</th>
                      <th scope="col" className="px-5 py-2 text-right font-medium">{t('settings.actions')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {members.map((member) => {
                      const options = editableRoles(actorRole, member, currentUser?.id)
                      return (
                        <tr key={member.id}>
                          <td className="px-5 py-3">
                            <div className="flex items-center gap-2.5">
                              <Avatar name={member.name} className="size-8" />
                              <div className="min-w-0">
                                <p className="truncate text-sm font-medium">{member.name}</p>
                                <p className="truncate text-caption text-muted-foreground">{member.email}</p>
                              </div>
                            </div>
                          </td>
                          <td className="px-3 py-3 text-sm text-muted-foreground">{member.city}</td>
                          <td className="px-3 py-3 text-caption text-muted-foreground">{shortDate(member.joinedAt, locale)}</td>
                          <td className="px-3 py-3">
                            {options.length === 0 ? (
                              <Badge variant={member.role === 'Owner' ? 'primary' : 'neutral'}>
                                {member.role === 'Owner' ? t('common.owner') : member.role}
                              </Badge>
                            ) : (
                              <NativeSelect
                                aria-label={t('settings.roleAria', { name: member.name })}
                                value={member.role}
                                wrapperClassName="w-36"
                                className="h-9 text-caption"
                                disabled={rolePending === member.id}
                                onChange={(event) => void changeRole(member, event.target.value as WorkspaceRole)}
                              >
                                {options.map((option) => (
                                  <option key={option} value={option}>{option}</option>
                                ))}
                              </NativeSelect>
                            )}
                          </td>
                          <td className="px-5 py-3 text-right">
                            {canRemove(member) ? (
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={t('settings.removeAria', { name: member.name })}
                                onClick={() => setRemoveTarget(member)}
                              >
                                <Trash2 className="size-4" aria-hidden="true" />
                              </Button>
                            ) : member.role === 'Owner' ? (
                              <span className="text-caption text-muted-foreground">{t('settings.ownerHint')}</span>
                            ) : null}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>

          {canManage ? (
            <Card>
              <CardHeader>
                <CardTitle>{t('settings.pending')}</CardTitle>
                <CardDescription>{t('settings.pendingDesc')}</CardDescription>
              </CardHeader>
              <CardContent className="px-0 pb-0">
                {pendingInvitations.length === 0 ? (
                  <EmptyState
                    icon={Mail}
                    title={t('settings.pendingEmpty')}
                    description={t('settings.pendingEmptyDesc')}
                    className="mx-5 mb-5"
                  />
                ) : (
                  <ul className="divide-y divide-border border-t border-border">
                    {pendingInvitations.map((invitation) => (
                      <li key={invitation.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                        <span className="flex size-9 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground">
                          <Mail className="size-4" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{invitation.email}</p>
                          <p className="text-caption text-muted-foreground">
                            {t('settings.expires', { date: shortDate(invitation.expiresAt, locale) })}
                          </p>
                        </div>
                        <Badge variant="warning">{invitation.role}</Badge>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          aria-label={t('settings.revokeAria', { email: invitation.email })}
                          onClick={() => void revoke(invitation)}
                        >
                          {t('settings.revoke')}
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          ) : null}
        </div>
      )}

      <Dialog open={inviteOpen} onOpenChange={(open) => { setInviteOpen(open); if (!open) setInviteWarning(undefined) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('settings.inviteTitle')}</DialogTitle>
            <DialogDescription>{t('settings.inviteDesc')}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitInvite} noValidate className="flex flex-col gap-4 px-6 pb-6">
            <Field label={t('settings.inviteEmail')} htmlFor="workspace-invite-email" error={inviteError}>
              <Input
                id="workspace-invite-email"
                type="email"
                value={inviteEmail}
                onChange={(event) => { setInviteEmail(event.target.value); if (inviteError) setInviteError(undefined) }}
                autoComplete="email"
                required
                aria-invalid={Boolean(inviteError)}
              />
            </Field>
            <Field label={t('settings.inviteRole')} htmlFor="workspace-invite-role">
              <NativeSelect
                id="workspace-invite-role"
                value={inviteRole}
                onChange={(event) => setInviteRole(event.target.value as WorkspaceRole)}
              >
                {invitableRoles.map((role) => (
                  <option key={role} value={role}>{role}</option>
                ))}
              </NativeSelect>
            </Field>
            {inviteWarning ? (
              <p role="alert" className="rounded-xl border border-warning/40 bg-warning-soft px-3 py-2 text-caption text-warning-text">
                {inviteWarning}
              </p>
            ) : null}
            <DialogFooter className="mx-0 px-0">
              <Button type="button" variant="secondary" onClick={() => setInviteOpen(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" loading={invitePending}>
                <UserPlus className="size-4" aria-hidden="true" />
                {t('settings.inviteSubmit')}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={removeTarget !== null} onOpenChange={(open) => { if (!open) setRemoveTarget(null) }}>
        <DialogContent>
          {removeTarget ? (
            <>
              <DialogHeader>
                <DialogTitle>{t('settings.removeTitle', { name: removeTarget.name })}</DialogTitle>
                <DialogDescription>{t('settings.removeBody', { name: removeTarget.name })}</DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" variant="secondary" onClick={() => setRemoveTarget(null)}>
                  {t('common.cancel')}
                </Button>
                <Button type="button" variant="destructive" loading={removing} onClick={() => void confirmRemove()}>
                  {t('settings.removeConfirm')}
                </Button>
              </DialogFooter>
            </>
          ) : null}
        </DialogContent>
      </Dialog>
    </AppShell>
  )
}

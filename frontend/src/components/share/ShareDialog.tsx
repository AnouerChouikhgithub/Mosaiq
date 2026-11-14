import { Check, Copy, Link2, Mail, UserPlus, UserMinus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import type { ShareRole } from '@/mocks'
import { users, workspace } from '@/mocks'
import type { ApiUser } from '@/api/client'
import { Avatar } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, Input, NativeSelect } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'

const shareRoles: ShareRole[] = ['Viewer', 'Commenter', 'Editor']

interface Invite {
  id: string
  email: string
  role: ShareRole
}

export interface ShareDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  boardTitle: string
  memberIds: string[]
  boardId: string
  members: (ApiUser & { boardRole?: string })[]
  publicAccess: boolean
  onInvite: (email: string, role: ShareRole) => Promise<{ token: string; email: string; role: string; expiresAt: string }>
  onRoleChange: (userId: string, role: ShareRole) => Promise<unknown>
  onRemove: (userId: string) => Promise<unknown>
  onPublicAccessChange: (enabled: boolean) => Promise<unknown>
}

export function ShareDialog({
  open,
  onOpenChange,
  boardTitle,
  memberIds,
  boardId,
  members,
  publicAccess,
  onInvite,
  onRoleChange,
  onRemove,
  onPublicAccessChange,
}: ShareDialogProps) {
  const { toast } = useToast()
  const { t } = useI18n()
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<ShareRole>('Editor')
  const [error, setError] = useState<string | undefined>()
  const [linkAccess, setLinkAccess] = useState(publicAccess)
  const [invites, setInvites] = useState<Invite[]>([])
  const [roles, setRoles] = useState<Record<string, ShareRole>>({})

  const collaborators: (ApiUser & { boardRole?: string })[] = members.length
    ? members
    : users.filter((user) => memberIds.includes(user.id)).map((user) => ({ ...user, boardRole: user.role }))
  const shareLink = `${window.location.origin}/share/${encodeURIComponent(boardId)}`

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = email.trim()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      setError(t('share.invalidEmail'))
      return
    }

    try {
      const result = await onInvite(value, role)
      const invitationLink = `${window.location.origin}/sign-in?invite=${encodeURIComponent(result.token)}`
      setInvites((current) => [...current.filter((invite) => invite.email !== value), { id: result.token, email: value, role }])
      setEmail('')
      setError(undefined)
      // The link is shown in the toast even when clipboard permission is unavailable.
      await navigator.clipboard.writeText(invitationLink).catch(() => undefined)
      toast({ title: t('share.invited'), description: invitationLink })
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('share.inviteFailed'))
    }
  }

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(shareLink)
      toast({ title: t('share.copied'), description: shareLink })
    } catch {
      toast({
        title: t('share.copyFailed'),
        description: t('share.clipboardBlocked'),
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl p-0">
        <DialogHeader>
          <DialogTitle>{t('share.title', { board: boardTitle })}</DialogTitle>
          <DialogDescription>
            {t('share.description', { workspace: workspace.name })}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={invite} className="flex flex-col gap-3 px-6 pb-5">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <Field
              label={t('share.inviteEmail')}
              htmlFor="invite-email"
              error={error}
              className="flex-1"
            >
              <div className="relative">
                <Mail
                  className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden="true"
                />
                <Input
                  id="invite-email"
                  type="email"
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value)
                    if (error) setError(undefined)
                  }}
                  placeholder={t('share.emailPlaceholder')}
                  aria-invalid={Boolean(error)}
                  className="pl-9"
                />
              </div>
            </Field>
            <Field
              label={t('common.role')}
              htmlFor="invite-role"
              className="sm:w-36"
            >
              <NativeSelect
                id="invite-role"
                value={role}
                onChange={(event) => setRole(event.target.value as ShareRole)}
              >
                {shareRoles.map((shareRole) => (
                  <option key={shareRole} value={shareRole}>
                    {shareRole}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Button
              type="submit"
              variant="primary"
              className="sm:mt-[26px]"
              aria-label={t('share.send')}
            >
              <UserPlus className="size-4" aria-hidden="true" />
              {t('share.invite')}
            </Button>
          </div>
        </form>

        <div className="border-t border-border px-6 py-4">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-start gap-2.5">
              <Link2
                className="mt-0.5 size-4 text-muted-foreground"
                aria-hidden="true"
              />
              <div>
                <p className="text-sm font-medium">{t('share.linkTitle')}</p>
                <p className="text-caption text-muted-foreground">
                  {t('share.linkBody')}
                </p>
              </div>
            </div>
            <Switch
              checked={linkAccess}
              onCheckedChange={(enabled) => {
                setLinkAccess(enabled)
                void onPublicAccessChange(enabled).catch((cause) => {
                  setLinkAccess(!enabled)
                  toast({ title: cause instanceof Error ? cause.message : t('share.accessFailed') })
                })
              }}
              aria-label={t('share.allowLink')}
            />
          </div>

          {linkAccess ? (
            <div className="mt-3 flex gap-2">
              <Input
                readOnly
                value={shareLink}
                aria-label={t('share.shareLink')}
                className="text-caption text-muted-foreground"
              />
              <Button
                type="button"
                variant="secondary"
                onClick={copyLink}
                aria-label={t('common.copy')}
              >
                <Copy className="size-4" aria-hidden="true" />
                {t('common.copy')}
              </Button>
            </div>
          ) : null}
        </div>

        <div className="border-t border-border px-6 py-4">
          <p className="text-caption font-medium text-muted-foreground">
            {t('share.peopleCount', {
              count: collaborators.length + invites.length,
            })}
          </p>
          <ul className="mt-2 flex flex-col divide-y divide-border">
            {collaborators.map((user) => (
              <li key={user.id} className="flex items-center gap-3 py-2.5">
                <Avatar name={user.name} className="size-9" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{user.name}</p>
                  <p className="truncate text-caption text-muted-foreground">
                    {user.email}
                  </p>
                </div>
                {user.boardRole === 'Owner' || user.role === 'Owner' ? (
                  <Badge variant="primary">{t('common.owner')}</Badge>
                ) : (
                  <NativeSelect
                    aria-label={t('share.roleAria', { name: user.name })}
                    value={roles[user.id] ?? user.boardRole ?? 'Editor'}
                    onChange={(event) => {
                      const nextRole = event.target.value as ShareRole
                      const previousRole: ShareRole = roles[user.id] ?? (user.boardRole as ShareRole | undefined) ?? 'Editor'
                      setRoles((current) => ({ ...current, [user.id]: nextRole }))
                      void onRoleChange(user.id, nextRole).catch((cause) => {
                        setRoles((current) => ({ ...current, [user.id]: previousRole }))
                        toast({ title: cause instanceof Error ? cause.message : t('share.roleFailed') })
                      })
                    }}
                    wrapperClassName="w-32"
                    className="h-9 text-caption"
                  >
                    {shareRoles.map((shareRole) => (
                      <option key={shareRole} value={shareRole}>
                        {shareRole}
                      </option>
                    ))}
                  </NativeSelect>
                )}
                {user.boardRole !== 'Owner' && user.role !== 'Owner' ? (
                  <Button type="button" variant="ghost" size="icon-sm" aria-label={t('share.remove', { name: user.name })} onClick={() => {
                    void onRemove(user.id).catch((cause) => toast({ title: cause instanceof Error ? cause.message : t('share.removeFailed') }))
                  }}>
                    <UserMinus className="size-4" aria-hidden="true" />
                  </Button>
                ) : null}
              </li>
            ))}

            {invites.map((invite) => (
              <li key={invite.id} className="flex items-center gap-3 py-2.5">
                <span className="flex size-9 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground">
                  <Mail className="size-4" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{invite.email}</p>
                  <p className="text-caption text-muted-foreground">
                    {t('share.pending')}
                  </p>
                </div>
                <Button type="button" variant="secondary" size="sm" onClick={() => {
                  const invitationLink = `${window.location.origin}/sign-in?invite=${encodeURIComponent(invite.id)}`
                  void navigator.clipboard.writeText(invitationLink).then(() => toast({ title: t('share.copied'), description: invitationLink })).catch(() => toast({ title: t('share.copyFailed') }))
                }}><Copy className="size-4" aria-hidden="true" />{t('common.copy')}</Button>
                <Badge variant="warning">{invite.role}</Badge>
              </li>
            ))}
          </ul>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => onOpenChange(false)}
          >
            <Check className="size-4" aria-hidden="true" />
            {t('share.done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

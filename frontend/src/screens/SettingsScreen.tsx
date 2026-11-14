import { CreditCard, Monitor, Moon, Sun, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { teamMembers, users, workspace } from '@/mocks'
import type { WorkspaceRole } from '@/mocks'
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
import { NativeSelect } from '@/components/ui/input'
import { SegmentedControl } from '@/components/ui/toggle-group'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { useTheme, type ThemeMode } from '@/theme/ThemeContext'

const assignableRoles: WorkspaceRole[] = [
  'Admin',
  'Editor',
  'Commenter',
  'Viewer',
]

export function SettingsScreen({
  onOpenPalette,
}: {
  onOpenPalette: () => void
}) {
  const { mode, resolved, setMode } = useTheme()
  const { toast } = useToast()
  const { t } = useI18n()
  const [roles, setRoles] = useState<Record<string, WorkspaceRole>>(() =>
    Object.fromEntries(teamMembers.map((member) => [member.id, member.role])),
  )

  async function copyInviteLink() {
    try {
      await navigator.clipboard.writeText('https://mosaiq.tn/invite/studio-carthage')
      toast({
        title: t('settings.inviteCopied'),
        description: t('settings.inviteCopiedBody'),
      })
    } catch {
      toast({
        title: t('common.copyFailed'),
        description: t('common.clipboardBlocked'),
      })
    }
  }

  return (
    <AppShell
      activeView="settings"
      title={t('settings.title')}
      description={t('settings.description', {
        workspace: workspace.name,
        count: users.length,
        plan: workspace.plan,
      })}
      onOpenPalette={onOpenPalette}
      actions={
        <Button type="button" variant="secondary" onClick={copyInviteLink}>
          <UserPlus className="size-4" aria-hidden="true" />
          {t('settings.invite')}
        </Button>
      }
    >
      <div className="mt-6 flex max-w-4xl flex-col gap-6">
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <CardTitle>{t('settings.appearance')}</CardTitle>
                <CardDescription>
                  {t('settings.appearanceDesc')}
                </CardDescription>
              </div>
              <Badge variant="outline">
                {t('settings.activeMode', {
                  mode:
                    resolved === 'dark'
                      ? t('settings.dark')
                      : t('settings.light'),
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
                {
                  value: 'light',
                  label: t('settings.theme.light'),
                  icon: <Sun className="size-4" aria-hidden="true" />,
                },
                {
                  value: 'dark',
                  label: t('settings.theme.dark'),
                  icon: <Moon className="size-4" aria-hidden="true" />,
                },
                {
                  value: 'system',
                  label: t('settings.theme.system'),
                  icon: <Monitor className="size-4" aria-hidden="true" />,
                },
              ]}
            />
            <p className="mt-3 text-caption text-muted-foreground">
              {t('settings.systemHint')}
            </p>
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
                {workspace.pricePerMember}{' '}
                <span className="text-title">TND</span>
              </p>
              <p className="text-caption text-muted-foreground">
                {t('settings.perMember')}
              </p>
            </div>
            <Button
              type="button"
              variant="secondary"
              onClick={() =>
                toast({
                  title: t('settings.billingDisconnected'),
                  description: t('settings.billingBody'),
                })
              }
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
            <table className="w-full min-w-[560px] text-left">
              <thead>
                <tr className="border-y border-border text-caption text-muted-foreground">
                  <th scope="col" className="px-5 py-2 font-medium">
                    {t('settings.member')}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t('settings.city')}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t('settings.lastActive')}
                  </th>
                  <th scope="col" className="px-5 py-2 font-medium">
                    {t('settings.role')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {teamMembers.map((member) => {
                  const role = roles[member.id] ?? member.role
                  return (
                    <tr key={member.id}>
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-2.5">
                          <Avatar name={member.name} className="size-8" />
                          <div className="min-w-0">
                            <p className="truncate text-sm font-medium">
                              {member.name}
                            </p>
                            <p className="truncate text-caption text-muted-foreground">
                              {member.email}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="px-3 py-3 text-sm text-muted-foreground">
                        {member.city}
                      </td>
                      <td className="px-3 py-3 text-caption text-muted-foreground">
                        {member.lastActive}
                      </td>
                      <td className="px-5 py-3">
                        {member.role === 'Owner' ? (
                          <Badge variant="primary">{t('common.owner')}</Badge>
                        ) : (
                          <NativeSelect
                            aria-label={t('settings.roleAria', {
                              name: member.name,
                            })}
                            value={role}
                            wrapperClassName="w-36"
                            className="h-9 text-caption"
                            onChange={(event) => {
                              const next = event.target.value as WorkspaceRole
                              setRoles((current) => ({
                                ...current,
                                [member.id]: next,
                              }))
                              toast({
                                title: t('settings.roleUpdated'),
                                description: t('settings.roleUpdatedBody', {
                                  name: member.name,
                                  role: next.toLowerCase(),
                                }),
                              })
                            }}
                          >
                            {assignableRoles.map((option) => (
                              <option key={option} value={option}>
                                {option}
                              </option>
                            ))}
                          </NativeSelect>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </CardContent>
        </Card>

        <p className="text-caption text-muted-foreground">
          {t('settings.demoNote')}
        </p>
      </div>
    </AppShell>
  )
}

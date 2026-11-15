import { ArrowRight, Mail, Sparkles, Users } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { boardCanvas, users } from '@/mocks'
import { Logo } from '@/components/brand/Logo'
import { BoardCanvas } from '@/components/board/BoardCanvas'
import { accentClasses } from '@/components/board/colors'
import { HeaderControls } from '@/components/layout/HeaderControls'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/input'
import { SegmentedControl } from '@/components/ui/toggle-group'
import { useToast } from '@/components/ui/toast-context'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'
import { acceptBoardInvitation, login, register } from '@/api/client'

type AuthMode = 'signin' | 'signup'

export function SignInScreen() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const invitationToken = searchParams.get('invite') ?? undefined
  const workspaceInvitationToken = searchParams.get('workspaceInvite') ?? undefined
  const expired = searchParams.get('expired') === '1'
  const { toast } = useToast()
  const { t } = useI18n()
  const [mode, setMode] = useState<AuthMode>(invitationToken || workspaceInvitationToken ? 'signup' : 'signin')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | undefined>()
  const [pending, setPending] = useState(false)
  const [selectedDemo, setSelectedDemo] = useState('')

  const demoAccounts = [
    { name: 'Amine Ben Salah', email: 'amine.bensalah@example.test' },
    { name: 'Ons Trabelsi', email: 'ons.trabelsi@example.test' },
  ]

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const passwordBytes = new TextEncoder().encode(password).length
    if (
      mode === 'signup' &&
      (passwordBytes < 12 ||
        passwordBytes > 72 ||
        !/\p{L}/u.test(password) ||
        !/\d/.test(password))
    ) {
      setError(t('signin.passwordLengthError'))
      return
    }
    if (password.length < 8) {
      setError(t('signin.passwordError'))
      return
    }
    setError(undefined)
    setPending(true)
    try {
      if (mode === 'signin') {
        await login(email, password)
        if (invitationToken) await acceptBoardInvitation(invitationToken)
      } else {
        await register(name, email, password, invitationToken, workspaceInvitationToken)
      }
      navigate('/', { replace: true })
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('signin.passwordError'))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="grid min-h-svh bg-background lg:grid-cols-2">
      <div className="flex flex-col px-6 py-8 sm:px-10">
        <header className="flex items-center justify-between">
          <Logo />
          <div className="flex items-center gap-2">
            <HeaderControls />
            <Badge variant="outline">{t('signin.demo')}</Badge>
          </div>
        </header>

        <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10">
          <span className="flex size-11 items-center justify-center rounded-card bg-primary-soft text-primary-soft-foreground">
            <Sparkles className="size-5" aria-hidden="true" />
          </span>
          <h1 className="mt-4 text-display">
            {mode === 'signin'
              ? t('signin.welcome')
              : t('signin.createTitle')}
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            {mode === 'signin'
              ? t('signin.welcomeDesc')
              : t('signin.createDesc')}
          </p>

          {expired ? (
            <p
              role="status"
              className="mt-4 rounded-lg border border-border bg-muted px-3 py-2 text-sm text-muted-foreground"
            >
              {t('signin.sessionExpired')}
            </p>
          ) : null}

          <SegmentedControl<AuthMode>
            value={mode}
            onValueChange={setMode}
            label={t('signin.modeLabel')}
            className="mt-6 w-full"
            options={[
              { value: 'signin', label: t('signin.signin') },
              { value: 'signup', label: t('signin.signup') },
            ]}
          />

          {mode === 'signin' ? (
            <div className="mt-4 flex flex-wrap gap-2" aria-label={t('signin.demoAccounts')}>
              {demoAccounts.map((account) => (
                <Button key={account.email} type="button" variant="secondary" size="sm" onClick={() => {
                  setEmail(account.email)
                  setPassword('MosaiqDemo2026!')
                  setSelectedDemo(account.email)
                }} aria-pressed={selectedDemo === account.email}>
                  {account.name}
                </Button>
              ))}
            </div>
          ) : null}

          <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
            {mode === 'signup' ? (
              <Field label={t('signin.name')} htmlFor="full-name">
                <Input
                  id="full-name"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  placeholder="Amine Ben Salah"
                  autoComplete="name"
                  required
                />
              </Field>
            ) : null}

            <Field label={t('signin.email')} htmlFor="email">
              <div className="relative">
                <Mail
                  className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden="true"
                />
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(event) => { setEmail(event.target.value); setSelectedDemo('') }}
                  placeholder={t('signin.emailPlaceholder')}
                  autoComplete="email"
                  className="pl-9"
                  required
                />
              </div>
            </Field>

            <Field
              label={t('signin.password')}
              htmlFor="password"
              error={error}
              hint={error || mode === 'signin' ? undefined : t('signin.passwordHint')}
            >
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(event) => {
                  setPassword(event.target.value)
                  if (error) setError(undefined)
                }}
                placeholder="••••••••"
                autoComplete={
                  mode === 'signin' ? 'current-password' : 'new-password'
                }
                aria-invalid={Boolean(error)}
                required
              />
            </Field>

            <Button type="submit" size="lg" loading={pending} className="w-full">
              {mode === 'signin' ? t('signin.submit') : t('signin.createSpace')}
              {!pending ? (
                <ArrowRight className="size-4" aria-hidden="true" />
              ) : null}
            </Button>
          </form>

          <div className="my-5 flex items-center gap-3">
            <span className="h-px flex-1 bg-border" />
            <span className="text-caption text-muted-foreground">
              {t('signin.or')}
            </span>
            <span className="h-px flex-1 bg-border" />
          </div>

          <Button
            type="button"
            variant="secondary"
            size="lg"
            className="w-full"
            onClick={() =>
              toast({
                title: t('signin.googleToast'),
                description: t('signin.googleToastBody'),
              })
            }
          >
            {t('signin.google')}
          </Button>

          <button
            type="button"
            onClick={() =>
              toast({
                title: t('signin.forgotToast'),
                description: t('signin.forgotToastBody'),
              })
            }
            className="mt-4 self-center text-caption text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          >
            {t('signin.forgot')}
          </button>

          <p className="mt-8 text-caption text-muted-foreground">
            {t('signin.terms')}
          </p>
        </div>
      </div>

      <aside className="relative hidden overflow-hidden border-l border-border bg-surface lg:flex lg:flex-col lg:justify-between">
        <div className="flex items-center gap-2 px-8 pt-8">
          <Badge variant="success">
            <span className="size-1.5 rounded-full bg-success" aria-hidden="true" />
            {t('signin.live')}
          </Badge>
          <span className="text-caption text-muted-foreground">
            Studio Carthage · Tunis
          </span>
        </div>

        <div className="mx-8 my-6 overflow-hidden rounded-panel border border-border shadow-card">
          <div className="flex items-center justify-between border-b border-border bg-surface px-4 py-2.5">
            <p className="text-caption font-medium">
              Refonte du site e-commerce
            </p>
            <Users className="size-4 text-muted-foreground" aria-hidden="true" />
          </div>
          <div className="h-[420px]">
            <BoardCanvas
              notes={boardCanvas.notes}
              connectors={boardCanvas.connectors}
              cursors={boardCanvas.cursors}
              frame={boardCanvas.frame}
              zoom={0.62}
            />
          </div>
        </div>

        <div className="px-8 pb-8">
          <div className="flex items-center gap-2">
            {users.slice(0, 4).map((user) => (
              <span
                key={user.id}
                className={cn(
                  'flex size-7 items-center justify-center rounded-full text-[10px] font-semibold ring-2 ring-offset-2 ring-offset-surface',
                  accentClasses[user.accent].chip,
                  accentClasses[user.accent].ring,
                )}
              >
                {user.name
                  .split(' ')
                  .slice(0, 2)
                  .map((part) => part[0])
                  .join('')}
              </span>
            ))}
            <span className="text-caption text-muted-foreground">
              {t('signin.editing', { count: 4 })}
            </span>
          </div>
          <blockquote className="mt-4 text-sm text-foreground">
            {t('signin.quote')}
          </blockquote>
          <p className="mt-1 text-caption text-muted-foreground">
            {t('signin.quoteBy')}
          </p>
        </div>
      </aside>
    </div>
  )
}

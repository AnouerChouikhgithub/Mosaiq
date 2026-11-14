import { Check, MessageSquare, Send, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import type { CommentThread } from '@/mocks'
import { users } from '@/mocks'
import { Avatar } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/ui/empty-state'
import { Input } from '@/components/ui/input'
import { useI18n } from '@/i18n'
import { cn } from '@/lib/utils'

const userById = new Map(users.map((user) => [user.id, user]))

function ThreadCard({
  thread,
  active,
  onSelect,
  onResolve,
  onReply,
}: {
  thread: CommentThread
  active: boolean
  onSelect: () => void
  onResolve: (resolved: boolean) => void
  onReply: (body: string) => void
}) {
  const { t } = useI18n()
  const [reply, setReply] = useState('')
  const first = thread.messages[0]
  const author = first ? userById.get(first.authorId) : undefined

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = reply.trim()
    if (!value) return
    onReply(value)
    setReply('')
  }

  return (
    <article
      onClick={onSelect}
      className={cn(
        'rounded-card border bg-surface p-3.5 transition-colors',
        active ? 'border-primary ring-1 ring-primary/30' : 'border-border',
        thread.resolved && 'opacity-80',
      )}
    >
      <header className="flex items-center gap-2">
        {author ? <Avatar name={author.name} className="size-7" /> : null}
        <div className="min-w-0 flex-1">
          <p className="truncate text-caption font-semibold">
            {first?.authorName ?? author?.name ?? t('common.guest')}
          </p>
          <p className="text-[11px] text-muted-foreground">
            {thread.messages.length > 1
              ? t('comments.metaMany', {
                  time: first?.at ?? '',
                  count: thread.messages.length,
                })
              : t('comments.metaOne', { time: first?.at ?? '' })}
          </p>
        </div>
        {thread.resolved ? (
          <Badge variant="success">{t('comments.resolved')}</Badge>
        ) : (
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={t('comments.resolve')}
            onClick={(event) => {
              event.stopPropagation()
              onResolve(true)
            }}
          >
            <Check className="size-4" aria-hidden="true" />
          </Button>
        )}
      </header>

      <ul className="mt-3 flex flex-col gap-2.5">
        {thread.messages.map((message) => {
              const messageAuthor = userById.get(message.authorId)
          return (
            <li key={message.id} className="flex gap-2">
              <Avatar
                name={messageAuthor?.name ?? t('common.guest')}
                className="size-6"
              />
              <div className="min-w-0">
                <p className="text-[11px] text-muted-foreground">
                  {(message.authorName ?? messageAuthor?.name)?.split(' ')[0] ?? t('common.guest')} ·{' '}
                  {message.at}
                </p>
                <p className="text-caption text-foreground">{message.body}</p>
              </div>
            </li>
          )
        })}
      </ul>

      {thread.resolved ? (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="mt-2"
          onClick={(event) => {
            event.stopPropagation()
            onResolve(false)
          }}
        >
          {t('comments.reopen')}
        </Button>
      ) : (
        <form
          onSubmit={submit}
          className="mt-3 flex items-center gap-2"
          onClick={(event) => event.stopPropagation()}
        >
          <Input
            value={reply}
            onChange={(event) => setReply(event.target.value)}
            placeholder={t('comments.reply')}
            aria-label={t('comments.replyAria', {
              name: author?.name ?? t('comments.title'),
            })}
            className="h-9 text-caption"
          />
          <Button
            type="submit"
            size="icon-sm"
            aria-label={t('comments.send')}
            disabled={!reply.trim()}
          >
            <Send className="size-4" aria-hidden="true" />
          </Button>
        </form>
      )}
    </article>
  )
}

export interface CommentsPanelProps {
  threads: CommentThread[]
  activeThreadId: string | null
  onSelectThread: (id: string) => void
  onResolve: (id: string, resolved: boolean) => void
  onReply: (id: string, body: string) => void
  onClose: () => void
}

export function CommentsPanel({
  threads,
  activeThreadId,
  onSelectThread,
  onResolve,
  onReply,
  onClose,
}: CommentsPanelProps) {
  const { t } = useI18n()
  const openCount = threads.filter((thread) => !thread.resolved).length

  return (
    <aside
      className="flex h-full w-80 shrink-0 flex-col border-l border-border bg-surface"
      aria-label={t('comments.aria')}
    >
      <header className="flex items-center gap-3 border-b border-border px-4 py-3">
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold">{t('comments.title')}</h2>
          <p className="text-caption text-muted-foreground">
            {openCount > 1
              ? t('comments.openMany', { count: openCount })
              : t('comments.openOne')}
          </p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={onClose}
          aria-label={t('comments.close')}
        >
          <X className="size-4" aria-hidden="true" />
        </Button>
      </header>

      <div className="flex-1 overflow-y-auto p-3">
        {threads.length === 0 ? (
          <EmptyState
            icon={MessageSquare}
            title={t('comments.empty')}
            description={t('comments.emptyBody')}
            className="h-full border-0"
          />
        ) : (
          <div className="flex flex-col gap-3">
            {threads.map((thread) => (
              <ThreadCard
                key={thread.id}
                thread={thread}
                active={thread.id === activeThreadId}
                onSelect={() => onSelectThread(thread.id)}
                onResolve={(resolved) => onResolve(thread.id, resolved)}
                onReply={(body) => onReply(thread.id, body)}
              />
            ))}
          </div>
        )}
      </div>

      <footer className="border-t border-border px-4 py-2.5 text-caption text-muted-foreground">
        {t('comments.footer')}
      </footer>
    </aside>
  )
}

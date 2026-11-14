<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\CommentMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentMessageRepository::class)]
#[ORM\Table(name: 'comment_messages')]
#[ORM\Index(name: 'idx_comment_messages_thread', fields: ['thread'])]
class CommentMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CommentThread::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'thread_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommentThread $thread;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        CommentThread $thread,
        User $author,
        string $content,
        ?\DateTimeImmutable $now = null,
    ) {
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Comment content is required.');
        }

        $this->thread = $thread;
        $this->author = $author;
        $this->content = trim($content);
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getThread(): CommentThread
    {
        return $this->thread;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

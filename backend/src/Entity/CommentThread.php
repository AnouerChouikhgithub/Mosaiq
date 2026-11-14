<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\CommentThreadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentThreadRepository::class)]
#[ORM\Table(name: 'comment_threads')]
#[ORM\Index(name: 'idx_comment_threads_board', fields: ['board'])]
class CommentThread
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'commentThreads')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Board $board;

    #[ORM\Column]
    private bool $resolved = false;

    #[ORM\Column(type: 'float')]
    private float $x;

    #[ORM\Column(type: 'float')]
    private float $y;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /** @var Collection<int, CommentMessage> */
    #[ORM\OneToMany(mappedBy: 'thread', targetEntity: CommentMessage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Board $board, User $author, float $x, float $y, ?\DateTimeImmutable $now = null)
    {
        $this->board = $board;
        $this->author = $author;
        $this->x = $x;
        $this->y = $y;
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, CommentMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(User $author, string $content, ?\DateTimeImmutable $now = null): CommentMessage
    {
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Comment content is required.');
        }

        $message = new CommentMessage($this, $author, $content, $now);
        $this->messages->add($message);

        return $message;
    }

    public function resolve(): void
    {
        $this->resolved = true;
    }

    public function reopen(): void
    {
        $this->resolved = false;
    }
}

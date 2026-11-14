<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\TimelineEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TimelineEntryRepository::class)]
#[ORM\Table(name: 'timeline_entries')]
#[ORM\Index(name: 'idx_timeline_entries_board', fields: ['board'])]
#[ORM\Index(name: 'idx_timeline_entries_at', fields: ['at'])]
class TimelineEntry
{
    public const KINDS = ['board', 'note', 'connector', 'frame', 'rename'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'timelineEntries')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Board $board;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(name: 'at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $at;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 16)]
    private string $kind;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $reveals = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $snapshot = null;

    /**
     * @param list<string>|null $reveals
     */
    public function __construct(
        Board $board,
        User $author,
        \DateTimeImmutable $at,
        string $label,
        string $kind,
        ?array $reveals = null,
        ?array $snapshot = null,
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Timeline label is required.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Timeline kind is not supported.');
        }

        $this->board = $board;
        $this->author = $author;
        $this->at = $at;
        $this->label = trim($label);
        $this->kind = $kind;
        $this->reveals = $reveals;
        $this->snapshot = $snapshot;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** @return list<string>|null */
    public function getReveals(): ?array
    {
        return $this->reveals;
    }

    /** @return array<string, mixed>|null */
    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\BoardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'boards')]
#[ORM\Index(name: 'idx_boards_workspace', fields: ['workspace'])]
#[ORM\Index(name: 'idx_boards_updated', fields: ['updatedAt'])]
#[ORM\Index(name: 'idx_boards_workspace_lifecycle', fields: ['workspace', 'lifecycleStatus'])]
class Board
{
    public const NOTE_COLORS = ['amber', 'rose', 'sky', 'mint', 'lilac'];
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_TRASHED = 'trashed';
    public const LIFECYCLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED, self::STATUS_TRASHED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class, inversedBy: 'boards')]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column]
    private bool $starred = false;

    #[ORM\Column(name: 'is_template')]
    private bool $isTemplate = false;

    /** @var list<string> */
    #[ORM\Column(name: 'note_colors', type: 'json')]
    private array $noteColors;

    /** @var Collection<int, BoardMembership> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: BoardMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    /** @var Collection<int, CanvasElement> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: CanvasElement::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $elements;

    /** @var Collection<int, CommentThread> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: CommentThread::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $commentThreads;

    /** @var Collection<int, TimelineEntry> */
    #[ORM\OneToMany(mappedBy: 'board', targetEntity: TimelineEntry::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $timelineEntries;

    #[ORM\Column(name: 'public_access', type: 'boolean', options: ['default' => false])]
    private bool $publicAccess = false;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'lifecycle_status', length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $lifecycleStatus = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'archived_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'trashed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    /** @param list<string> $noteColors */
    public function __construct(Workspace $workspace, string $title, array $noteColors = ['amber', 'sky', 'mint'], bool $isTemplate = false, ?\DateTimeImmutable $now = null)
    {
        if ('' === trim($title)) {
            throw new \InvalidArgumentException('Board title is required.');
        }
        if ([] === $noteColors) {
            throw new \InvalidArgumentException('A board needs at least one note color.');
        }
        foreach ($noteColors as $color) {
            if (!in_array($color, self::NOTE_COLORS, true)) {
                throw new \InvalidArgumentException(sprintf('Note color "%s" is not supported.', (string) $color));
            }
        }

        $now ??= new \DateTimeImmutable();
        $this->workspace = $workspace;
        $this->title = trim($title);
        $this->noteColors = array_values(array_unique($noteColors));
        $this->isTemplate = $isTemplate;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->memberships = new ArrayCollection();
        $this->elements = new ArrayCollection();
        $this->commentThreads = new ArrayCollection();
        $this->timelineEntries = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isStarred(): bool
    {
        return $this->starred;
    }

    public function isTemplate(): bool
    {
        return $this->isTemplate;
    }

    public function getLifecycleStatus(): string
    {
        return $this->lifecycleStatus;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->lifecycleStatus;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->lifecycleStatus;
    }

    public function isTrashed(): bool
    {
        return self::STATUS_TRASHED === $this->lifecycleStatus;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getTrashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function archive(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isActive()) {
            throw new \InvalidArgumentException('Only an active board can be archived.');
        }
        $this->lifecycleStatus = self::STATUS_ARCHIVED;
        $this->archivedAt = $now ?? new \DateTimeImmutable();
        $this->trashedAt = null;
        $this->touch($now);
    }

    public function trash(?\DateTimeImmutable $now = null): void
    {
        if ($this->isTrashed()) {
            throw new \InvalidArgumentException('The board is already in the trash.');
        }
        // An archived board may be discarded deliberately; it never keeps both
        // archive and trash timestamps once it enters the trash.
        $this->lifecycleStatus = self::STATUS_TRASHED;
        $this->trashedAt = $now ?? new \DateTimeImmutable();
        $this->archivedAt = null;
        $this->touch($now);
    }

    public function restoreLifecycle(?\DateTimeImmutable $now = null): void
    {
        if ($this->isActive()) {
            return;
        }
        $this->lifecycleStatus = self::STATUS_ACTIVE;
        $this->archivedAt = null;
        $this->trashedAt = null;
        $this->touch($now);
    }

    /** @return list<string> */
    public function getNoteColors(): array
    {
        return $this->noteColors;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, BoardMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return new ArrayCollection(array_map(static fn (BoardMembership $membership): User => $membership->getUser(), $this->memberships->toArray()));
    }

    /** @return Collection<int, CanvasElement> */
    public function getElements(): Collection
    {
        return $this->elements;
    }

    /** @return Collection<int, CommentThread> */
    public function getCommentThreads(): Collection
    {
        return $this->commentThreads;
    }

    /** @return Collection<int, TimelineEntry> */
    public function getTimelineEntries(): Collection
    {
        return $this->timelineEntries;
    }

    public function hasPublicAccess(): bool
    {
        return $this->publicAccess;
    }

    public function setPublicAccess(bool $publicAccess): void
    {
        $this->publicAccess = $publicAccess;
        $this->touch();
    }

    public function addMember(User $member, ?string $role = null): void
    {
        $role ??= $member->getRole();
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId() === $member->getId()) {
                $membership->changeRole($role);

                return;
            }
        }
        $this->memberships->add(new BoardMembership($this, $member, $role));
    }

    public function removeMember(User $member): void
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId() === $member->getId()) {
                $this->memberships->removeElement($membership);
            }
        }
    }

    public function isMember(User $user): bool
    {
        return null !== $this->membershipFor($user);
    }

    public function roleFor(User $user): ?string
    {
        return $this->membershipFor($user)?->getRole();
    }

    private function membershipFor(User $user): ?BoardMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId() === $user->getId()) {
                return $membership;
            }
        }

        return null;
    }

    public function rename(string $title, ?\DateTimeImmutable $now = null): void
    {
        if ('' === trim($title)) {
            throw new \InvalidArgumentException('Board title is required.');
        }
        $this->title = trim($title);
        $this->touch($now);
    }

    public function setStarred(bool $starred, ?\DateTimeImmutable $now = null): void
    {
        $this->starred = $starred;
        $this->touch($now);
    }

    public function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}

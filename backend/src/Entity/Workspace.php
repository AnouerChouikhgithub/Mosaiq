<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\WorkspaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceRepository::class)]
#[ORM\Table(name: 'workspaces')]
#[ORM\UniqueConstraint(name: 'uniq_workspaces_name', fields: ['name'])]
class Workspace
{
    public const PLANS = ['Starter', 'Pro', 'Enterprise'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $plan;

    /** Monthly price per seat, in TND minor-unit-free integer dinars. */
    #[ORM\Column(name: 'price_per_member')]
    private int $pricePerMember;

    #[ORM\Column(length: 8)]
    private string $currency = 'TND';

    #[ORM\Column]
    private int $seats;

    #[ORM\Column(name: 'renews_on', type: 'date_immutable')]
    private \DateTimeImmutable $renewsOn;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(mappedBy: 'workspace', targetEntity: User::class)]
    private Collection $users;

    /** @var Collection<int, Board> */
    #[ORM\OneToMany(mappedBy: 'workspace', targetEntity: Board::class)]
    private Collection $boards;

    public function __construct(
        string $name,
        string $plan,
        int $pricePerMember,
        int $seats,
        \DateTimeImmutable $renewsOn,
        ?\DateTimeImmutable $now = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Workspace name is required.');
        }
        if (!in_array($plan, self::PLANS, true)) {
            throw new \InvalidArgumentException('Workspace plan is not supported.');
        }
        if ($pricePerMember < 0) {
            throw new \InvalidArgumentException('Workspace price cannot be negative.');
        }
        if ($seats < 1) {
            throw new \InvalidArgumentException('Workspace needs at least one seat.');
        }

        $this->name = trim($name);
        $this->plan = $plan;
        $this->pricePerMember = $pricePerMember;
        $this->seats = $seats;
        $this->renewsOn = $renewsOn;
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->users = new ArrayCollection();
        $this->boards = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function getPricePerMember(): int
    {
        return $this->pricePerMember;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSeats(): int
    {
        return $this->seats;
    }

    public function getRenewsOn(): \DateTimeImmutable
    {
        return $this->renewsOn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /** @return Collection<int, Board> */
    public function getBoards(): Collection
    {
        return $this->boards;
    }

    public function rename(string $name): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Workspace name is required.');
        }
        $this->name = trim($name);
    }

    public function changePlan(string $plan, int $pricePerMember): void
    {
        if (!in_array($plan, self::PLANS, true)) {
            throw new \InvalidArgumentException('Workspace plan is not supported.');
        }
        if ($pricePerMember < 0) {
            throw new \InvalidArgumentException('Workspace price cannot be negative.');
        }
        $this->plan = $plan;
        $this->pricePerMember = $pricePerMember;
    }
}

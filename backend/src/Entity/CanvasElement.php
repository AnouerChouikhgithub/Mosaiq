<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\CanvasElementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CanvasElementRepository::class)]
#[ORM\Table(name: 'canvas_elements')]
#[ORM\Index(name: 'idx_canvas_elements_board', fields: ['board'])]
#[ORM\Index(name: 'idx_canvas_elements_author', fields: ['author'])]
class CanvasElement
{
    public const TYPES = ['note', 'shape', 'connector', 'frame'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'elements')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Board $board;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(type: 'float')]
    private float $x;

    #[ORM\Column(type: 'float')]
    private float $y;

    #[ORM\Column(type: 'float')]
    private float $width;

    #[ORM\Column(type: 'float')]
    private float $height;

    #[ORM\Column(type: 'float')]
    private float $rotation = 0.0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Board $board,
        User $author,
        string $type,
        float $x,
        float $y,
        float $width,
        float $height,
        float $rotation = 0.0,
        ?string $content = null,
        ?string $color = null,
        ?array $metadata = null,
        ?\DateTimeImmutable $now = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Canvas element type is not supported.');
        }
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Canvas element dimensions cannot be negative.');
        }
        if (null !== $color && !in_array($color, Board::NOTE_COLORS, true)) {
            throw new \InvalidArgumentException('Canvas element color is not supported.');
        }

        $this->board = $board;
        $this->author = $author;
        $this->type = $type;
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
        $this->rotation = $rotation;
        $this->content = null === $content ? null : trim($content);
        $this->color = $color;
        $this->metadata = $metadata;
        $this->createdAt = $now ?? new \DateTimeImmutable();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getRotation(): float
    {
        return $this->rotation;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updateGeometry(float $x, float $y, float $width, float $height, float $rotation): void
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Canvas element dimensions cannot be negative.');
        }
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
        $this->rotation = $rotation;
        $this->board->touch();
    }

    public function updateContent(?string $content): void
    {
        $this->content = null === $content ? null : trim($content);
        $this->board->touch();
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function updateStyle(?string $color, ?array $metadata = null): void
    {
        if (null !== $color && !in_array($color, Board::NOTE_COLORS, true)) {
            throw new \InvalidArgumentException('Canvas element color is not supported.');
        }
        $this->color = $color;
        $this->metadata = $metadata;
        $this->board->touch();
    }
}

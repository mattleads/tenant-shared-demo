<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** Which worker process handled the NoteCreated message (hostname:pid). */
    #[ORM\Column(nullable: true)]
    private ?string $processedBy = null;

    public function __construct(string $body)
    {
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function markProcessed(string $by): void
    {
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $by;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'processedAt' => $this->processedAt?->format(\DATE_ATOM),
            'processedBy' => $this->processedBy,
        ];
    }
}

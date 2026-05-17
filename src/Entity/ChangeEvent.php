<?php

namespace App\Entity;

use App\Enum\ChangeEventType;
use App\Repository\ChangeEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChangeEventRepository::class)]
#[ORM\Index(columns: ['emailed_at'], name: 'IDX_CHANGE_EVENT_EMAILED_AT')]
#[ORM\Index(columns: ['created_at'], name: 'IDX_CHANGE_EVENT_CREATED_AT')]
class ChangeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'changeEvents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 120)]
    private string $tableName = '';

    #[ORM\Column(length: 120)]
    private string $recordId = '';

    #[ORM\Column(enumType: ChangeEventType::class)]
    private ChangeEventType $eventType = ChangeEventType::Updated;

    #[ORM\Column]
    private array $oldValues = [];

    #[ORM\Column]
    private array $newValues = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $humanSummary = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s %s #%s', $this->eventType->value, $this->tableName, $this->recordId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function setTableName(string $tableName): self
    {
        $this->tableName = trim($tableName);

        return $this;
    }

    public function getRecordId(): string
    {
        return $this->recordId;
    }

    public function setRecordId(string $recordId): self
    {
        $this->recordId = trim($recordId);

        return $this;
    }

    public function getEventType(): ChangeEventType
    {
        return $this->eventType;
    }

    public function setEventType(ChangeEventType $eventType): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function getOldValues(): array
    {
        return $this->oldValues;
    }

    public function setOldValues(array $oldValues): self
    {
        $this->oldValues = $oldValues;

        return $this;
    }

    public function getNewValues(): array
    {
        return $this->newValues;
    }

    public function setNewValues(array $newValues): self
    {
        $this->newValues = $newValues;

        return $this;
    }

    public function getHumanSummary(): ?string
    {
        return $this->humanSummary;
    }

    public function setHumanSummary(?string $humanSummary): self
    {
        $this->humanSummary = $humanSummary;

        return $this;
    }

    public function getEmailedAt(): ?\DateTimeImmutable
    {
        return $this->emailedAt;
    }

    public function setEmailedAt(?\DateTimeImmutable $emailedAt): self
    {
        $this->emailedAt = $emailedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

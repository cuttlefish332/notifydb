<?php

namespace App\Entity;

use App\Enum\NotificationFrequency;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $apiTokenHash = '';

    #[ORM\Column(length: 16)]
    private string $apiTokenPrefix = '';

    #[ORM\Column(enumType: NotificationFrequency::class)]
    private NotificationFrequency $notificationFrequency = NotificationFrequency::Instant;

    #[ORM\Column]
    private bool $aiSummariesEnabled = false;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $notificationEmail = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, ChangeEvent>
     */
    #[ORM\OneToMany(targetEntity: ChangeEvent::class, mappedBy: 'project', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $changeEvents;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->changeEvents = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getApiTokenHash(): string
    {
        return $this->apiTokenHash;
    }

    public function setApiTokenHash(string $apiTokenHash): self
    {
        $this->apiTokenHash = $apiTokenHash;

        return $this;
    }

    public function getApiTokenPrefix(): string
    {
        return $this->apiTokenPrefix;
    }

    public function setApiTokenPrefix(string $apiTokenPrefix): self
    {
        $this->apiTokenPrefix = $apiTokenPrefix;

        return $this;
    }

    public function getNotificationFrequency(): NotificationFrequency
    {
        return $this->notificationFrequency;
    }

    public function setNotificationFrequency(NotificationFrequency $notificationFrequency): self
    {
        $this->notificationFrequency = $notificationFrequency;

        return $this;
    }

    public function isAiSummariesEnabled(): bool
    {
        return $this->aiSummariesEnabled;
    }

    public function setAiSummariesEnabled(bool $aiSummariesEnabled): self
    {
        $this->aiSummariesEnabled = $aiSummariesEnabled;

        return $this;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->notificationEmail;
    }

    public function setNotificationEmail(?string $notificationEmail): self
    {
        $notificationEmail = $notificationEmail !== null ? trim($notificationEmail) : null;
        $this->notificationEmail = $notificationEmail !== '' ? $notificationEmail : null;

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

    /**
     * @return Collection<int, ChangeEvent>
     */
    public function getChangeEvents(): Collection
    {
        return $this->changeEvents;
    }
}

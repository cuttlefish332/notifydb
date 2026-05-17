<?php

namespace App\Entity;

use App\Enum\BillingPlan;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const FREE_WEEKLY_EMAIL_LIMIT = 5;
    public const PRO_WEEKLY_EMAIL_LIMIT = 100;
    public const FREE_WEEKLY_EVENT_LIMIT = 100;
    public const PRO_WEEKLY_EVENT_LIMIT = 5000;
    public const FREE_WEEKLY_AI_SUMMARY_LIMIT = 0;
    public const PRO_WEEKLY_AI_SUMMARY_LIMIT = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(enumType: BillingPlan::class, options: ['default' => 'free'])]
    private BillingPlan $plan = BillingPlan::Free;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeSubscriptionStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $stripeCurrentPeriodEnd = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $stripeCancelAtPeriodEnd = false;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $projects;

    /**
     * @var Collection<int, NotificationEmailLog>
     */
    #[ORM\OneToMany(targetEntity: NotificationEmailLog::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $notificationEmailLogs;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->notificationEmailLogs = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getPlan(): BillingPlan
    {
        return $this->plan;
    }

    public function setPlan(BillingPlan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function isPro(): bool
    {
        return $this->plan === BillingPlan::Pro;
    }

    public function getWeeklyEmailLimit(): int
    {
        return $this->isPro() ? self::PRO_WEEKLY_EMAIL_LIMIT : self::FREE_WEEKLY_EMAIL_LIMIT;
    }

    public function getWeeklyEventLimit(): int
    {
        return $this->isPro() ? self::PRO_WEEKLY_EVENT_LIMIT : self::FREE_WEEKLY_EVENT_LIMIT;
    }

    public function getWeeklyAiSummaryLimit(): int
    {
        return $this->isPro() ? self::PRO_WEEKLY_AI_SUMMARY_LIMIT : self::FREE_WEEKLY_AI_SUMMARY_LIMIT;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): self
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getStripeSubscriptionStatus(): ?string
    {
        return $this->stripeSubscriptionStatus;
    }

    public function setStripeSubscriptionStatus(?string $stripeSubscriptionStatus): self
    {
        $this->stripeSubscriptionStatus = $stripeSubscriptionStatus;

        return $this;
    }

    public function getStripeCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->stripeCurrentPeriodEnd;
    }

    public function setStripeCurrentPeriodEnd(?\DateTimeImmutable $stripeCurrentPeriodEnd): self
    {
        $this->stripeCurrentPeriodEnd = $stripeCurrentPeriodEnd;

        return $this;
    }

    public function isStripeCancelAtPeriodEnd(): bool
    {
        return $this->stripeCancelAtPeriodEnd;
    }

    public function setStripeCancelAtPeriodEnd(bool $stripeCancelAtPeriodEnd): self
    {
        $this->stripeCancelAtPeriodEnd = $stripeCancelAtPeriodEnd;

        return $this;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): self
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setOwner($this);
        }

        return $this;
    }

    public function removeProject(Project $project): self
    {
        if ($this->projects->removeElement($project) && $project->getOwner() === $this) {
            $project->setOwner(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, NotificationEmailLog>
     */
    public function getNotificationEmailLogs(): Collection
    {
        return $this->notificationEmailLogs;
    }
}

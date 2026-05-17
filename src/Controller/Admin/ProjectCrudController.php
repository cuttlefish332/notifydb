<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\NotificationFrequency;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

final class ProjectCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
        private readonly ApiTokenManager $apiTokenManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Project::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Project')
            ->setEntityLabelInPlural('Projects')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('apiTokenPrefix', 'API token prefix')->hideOnForm();
        yield ChoiceField::new('notificationFrequency')
            ->setChoices([
                'Instant' => NotificationFrequency::Instant,
                'Daily' => NotificationFrequency::Daily,
                'Weekly' => NotificationFrequency::Weekly,
                'Off' => NotificationFrequency::Off,
            ])
            ->renderAsBadges();
        yield BooleanField::new('aiSummariesEnabled', 'AI summaries');
        yield EmailField::new('notificationEmail');

        if ($this->security->isGranted('ROLE_ADMIN')) {
            yield AssociationField::new('owner');
        }

        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $queryBuilder
                ->andWhere('entity.owner = :currentUser')
                ->setParameter('currentUser', $this->security->getUser());
        }

        return $queryBuilder;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Project) {
            return;
        }

        if ($entityInstance->getOwner() === null && $this->security->getUser() instanceof User) {
            $entityInstance->setOwner($this->security->getUser());
        }

        $plainToken = $this->apiTokenManager->generateToken();
        $entityInstance
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken));

        parent::persistEntity($entityManager, $entityInstance);

        $this->addFlash('success', sprintf('Project created. Save this API token now: %s', $plainToken));
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->denyUnlessManageable($context);

        return parent::detail($context);
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $this->denyUnlessManageable($context);

        return parent::edit($context);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof Project && !$this->canManage($entityInstance)) {
            throw $this->createAccessDeniedException();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof Project && !$this->canManage($entityInstance)) {
            throw $this->createAccessDeniedException();
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function canManage(Project $project): bool
    {
        $user = $this->security->getUser();

        return $this->security->isGranted('ROLE_ADMIN')
            || ($user instanceof User && $project->getOwner()?->getId() === $user->getId());
    }

    private function denyUnlessManageable(AdminContext $context): void
    {
        $project = $context->getEntity()->getInstance();
        if (!$project instanceof Project || !$this->canManage($project)) {
            throw $this->createAccessDeniedException();
        }
    }
}

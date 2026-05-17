<?php

namespace App\Controller\Admin;

use App\Entity\ChangeEvent;
use App\Entity\User;
use App\Enum\ChangeEventType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

final class ChangeEventCrudController extends AbstractCrudController
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ChangeEvent::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('project');
        yield TextField::new('tableName');
        yield TextField::new('recordId');
        yield TextField::new('eventType', 'Event type')
            ->formatValue(fn ($value): string => $value instanceof ChangeEventType ? $value->value : (string) $value);
        yield ArrayField::new('oldValues');
        yield ArrayField::new('newValues');
        yield TextareaField::new('humanSummary');
        yield DateTimeField::new('emailedAt');
        yield DateTimeField::new('createdAt');
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
                ->join('entity.project', 'project')
                ->andWhere('project.owner = :currentUser')
                ->setParameter('currentUser', $this->security->getUser());
        }

        return $queryBuilder;
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->denyUnlessViewable($context);

        return parent::detail($context);
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException();
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException();
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        throw $this->createAccessDeniedException();
    }

    private function denyUnlessViewable(AdminContext $context): void
    {
        $event = $context->getEntity()->getInstance();
        if (!$event instanceof ChangeEvent) {
            throw $this->createAccessDeniedException();
        }

        $project = $event->getProject();
        $user = $this->security->getUser();
        if (!$this->security->isGranted('ROLE_ADMIN') && (!$user instanceof User || $project?->getOwner()?->getId() !== $user->getId())) {
            throw $this->createAccessDeniedException();
        }
    }
}

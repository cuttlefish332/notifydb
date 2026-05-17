<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\BillingPlan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield ChoiceField::new('plan')
            ->setChoices([
                'Free' => BillingPlan::Free,
                'Pro' => BillingPlan::Pro,
            ])
            ->renderAsBadges();
        yield ArrayField::new('roles');
        yield TextField::new('stripeCustomerId', 'Stripe customer')->hideOnForm();
        yield TextField::new('stripeSubscriptionId', 'Stripe subscription')->hideOnForm();
        yield TextField::new('stripeSubscriptionStatus', 'Subscription status')->hideOnForm();
        yield BooleanField::new('stripeCancelAtPeriodEnd', 'Cancels at period end')->hideOnForm();
        yield DateTimeField::new('stripeCurrentPeriodEnd', 'Current period end')->hideOnForm();
    }
}

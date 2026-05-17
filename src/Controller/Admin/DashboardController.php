<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator
            ->setController(ProjectCrudController::class)
            ->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('NotifyDB');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Dashboard', 'fa fa-home', 'app_dashboard');
        yield MenuItem::linkTo(ProjectCrudController::class, 'Projects', 'fa fa-database');
        yield MenuItem::linkTo(ChangeEventCrudController::class, 'Change Events', 'fa fa-bell');
        yield MenuItem::linkToRoute('Billing', 'fa fa-credit-card', 'billing_index');

        if ($this->isGranted('ROLE_ADMIN')) {
            yield MenuItem::section('Admin');
            yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        }

        yield MenuItem::linkToLogout('Logout', 'fa fa-sign-out');
    }
}

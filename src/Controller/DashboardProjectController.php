<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\NotificationFrequency;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/projects')]
final class DashboardProjectController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly ApiTokenManager $apiTokenManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'dashboard_project_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('project_create', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Project could not be created. Please try again.');

            return $this->redirectToRoute('app_dashboard');
        }

        $project = (new Project())->setOwner($user);
        $this->applyProjectSettings($project, $request);

        if ($project->getName() === '') {
            $this->addFlash('warning', 'Project name is required.');

            return $this->redirectToRoute('app_dashboard');
        }

        $plainToken = $this->apiTokenManager->generateToken();
        $project
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken));

        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $this->flashToken($project, $plainToken, 'Project created. Save this API token now; it will only be shown once.');

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/{id}/settings', name: 'dashboard_project_update', methods: ['POST'])]
    public function update(Project $project, Request $request): RedirectResponse
    {
        $this->denyUnlessProjectOwner($project);
        if (!$this->isCsrfTokenValid('project_update_'.$project->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Project settings could not be saved. Please try again.');

            return $this->redirectToRoute('app_dashboard');
        }

        $this->applyProjectSettings($project, $request);
        if ($project->getName() === '') {
            $this->addFlash('warning', 'Project name is required.');

            return $this->redirectToRoute('app_dashboard');
        }

        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%s settings saved.', $project->getName()));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/{id}/regenerate-token', name: 'dashboard_project_regenerate_token', methods: ['POST'])]
    public function regenerateToken(Project $project, Request $request): RedirectResponse
    {
        $this->denyUnlessProjectOwner($project);
        if (!$this->isCsrfTokenValid('project_regenerate_'.$project->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'API token could not be regenerated. Please try again.');

            return $this->redirectToRoute('app_dashboard');
        }

        $plainToken = $this->apiTokenManager->generateToken();
        $project
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken));

        $this->entityManager->flush();
        $this->flashToken($project, $plainToken, 'API token regenerated. Save it now; the old token no longer works.');

        return $this->redirectToRoute('app_dashboard');
    }

    private function applyProjectSettings(Project $project, Request $request): void
    {
        $frequency = NotificationFrequency::tryFrom((string) $request->request->get('notificationFrequency'));

        $project
            ->setName((string) $request->request->get('name'))
            ->setNotificationEmail($request->request->get('notificationEmail') !== null ? (string) $request->request->get('notificationEmail') : null)
            ->setNotificationFrequency($frequency ?? NotificationFrequency::Instant)
            ->setAiSummariesEnabled($request->request->getBoolean('aiSummariesEnabled'));
    }

    private function flashToken(Project $project, string $plainToken, string $message): void
    {
        $this->addFlash('success', $message);
        $this->addFlash('project_token', json_encode([
            'projectId' => $project->getId(),
            'projectName' => $project->getName(),
            'token' => $plainToken,
        ], JSON_THROW_ON_ERROR));
    }

    private function denyUnlessProjectOwner(Project $project): void
    {
        if ($project->getOwner()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChangeEventRepository;
use App\Repository\ProjectRepository;
use App\Service\NotificationQuotaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppDashboardController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly ProjectRepository $projectRepository,
        private readonly ChangeEventRepository $changeEventRepository,
        private readonly NotificationQuotaService $quotaService,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $projectRows = [];
        foreach ($this->projectRepository->findBy(['owner' => $user], ['createdAt' => 'DESC']) as $project) {
            $projectRows[] = [
                'project' => $project,
                'recentEvents' => $this->changeEventRepository->findRecentForProject($project),
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'projectRows' => $projectRows,
            'tokenReveal' => $this->tokenReveal($request),
            'sentThisWeek' => $this->quotaService->emailsSentThisWeek($user),
            'remainingEmailsThisWeek' => $this->quotaService->remainingThisWeek($user),
            'eventsThisWeek' => $this->quotaService->eventsAcceptedThisWeek($user),
            'remainingEventsThisWeek' => $this->quotaService->remainingEventsThisWeek($user),
            'aiSummariesThisWeek' => $this->quotaService->aiSummariesUsedThisWeek($user),
            'remainingAiSummariesThisWeek' => $this->quotaService->remainingAiSummariesThisWeek($user),
            'user' => $user,
        ]);
    }

    /**
     * @return array{projectId: int|null, projectName: string, token: string}|null
     */
    private function tokenReveal(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $flashes = $request->getSession()->getFlashBag()->get('project_token');
        $payload = $flashes[0] ?? null;
        if (!is_string($payload)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['token'], $data['projectName']) || !is_string($data['token']) || !is_string($data['projectName'])) {
            return null;
        }

        return [
            'projectId' => isset($data['projectId']) && is_numeric($data['projectId']) ? (int) $data['projectId'] : null,
            'projectName' => $data['projectName'],
            'token' => $data['token'],
        ];
    }
}

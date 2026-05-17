<?php

namespace App\Controller\Api;

use App\Entity\ChangeEvent;
use App\Enum\ChangeEventType;
use App\Enum\NotificationFrequency;
use App\Message\SendInstantChangeEventEmailMessage;
use App\Repository\ProjectRepository;
use App\Service\AiSummaryServiceInterface;
use App\Service\NotificationQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ChangeEventController extends AbstractController
{
    #[Route('/api/events', name: 'api_change_events_create', methods: ['POST'])]
    public function create(
        Request $request,
        ProjectRepository $projectRepository,
        AiSummaryServiceInterface $aiSummaryService,
        NotificationQuotaService $quotaService,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
    ): JsonResponse {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->json(['error' => 'Missing bearer token.'], Response::HTTP_UNAUTHORIZED);
        }

        $project = $projectRepository->findOneByPlainApiToken($token);
        if ($project === null) {
            return $this->json(['error' => 'Invalid bearer token.'], Response::HTTP_UNAUTHORIZED);
        }

        $owner = $project->getOwner();
        if ($owner === null) {
            return $this->json(['error' => 'Project owner is missing.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$quotaService->canAcceptEvent($owner)) {
            return $this->json([
                'error' => 'Weekly event limit reached.',
                'limit' => $owner->getWeeklyEventLimit(),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Request body must be valid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validatePayload($payload);
        if ($validationError !== null) {
            return $this->json(['error' => $validationError], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event = (new ChangeEvent())
            ->setProject($project)
            ->setTableName((string) $payload['tableName'])
            ->setRecordId((string) $payload['recordId'])
            ->setEventType(ChangeEventType::from($payload['eventType']))
            ->setOldValues($payload['oldValues'])
            ->setNewValues($payload['newValues']);

        if ($project->isAiSummariesEnabled() && $owner->isPro() && $quotaService->canCreateAiSummary($owner)) {
            $event->setHumanSummary($aiSummaryService->summarize($event));
        }

        $entityManager->persist($event);
        $entityManager->flush();

        if ($project->getNotificationFrequency() === NotificationFrequency::Instant) {
            $messageBus->dispatch(new SendInstantChangeEventEmailMessage((int) $event->getId()));
        }

        return $this->json([
            'id' => $event->getId(),
            'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
            'humanSummary' => $event->getHumanSummary(),
        ], Response::HTTP_CREATED);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');
        if ($authorization === null || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatePayload(array $payload): ?string
    {
        foreach (['tableName', 'recordId', 'eventType', 'oldValues', 'newValues'] as $field) {
            if (!array_key_exists($field, $payload)) {
                return sprintf('Missing required field "%s".', $field);
            }
        }

        if (!is_string($payload['tableName']) || trim($payload['tableName']) === '') {
            return 'tableName must be a non-empty string.';
        }

        if (!is_string($payload['recordId']) && !is_int($payload['recordId'])) {
            return 'recordId must be a string or integer.';
        }

        if (!is_string($payload['eventType']) || ChangeEventType::tryFrom($payload['eventType']) === null) {
            return 'eventType must be one of: created, updated, deleted.';
        }

        if (!is_array($payload['oldValues'])) {
            return 'oldValues must be an object.';
        }

        if (!is_array($payload['newValues'])) {
            return 'newValues must be an object.';
        }

        return null;
    }
}

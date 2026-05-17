<?php

namespace App\Service;

use App\Entity\ChangeEvent;
use OpenAI;

final readonly class OpenAiSummaryService implements AiSummaryServiceInterface
{
    public function __construct(
        private string $openAiApiKey,
        private string $openAiModel,
        private FakeAiSummaryService $fallback,
    ) {
    }

    public function summarize(ChangeEvent $event): string
    {
        if ($this->openAiApiKey === '' || str_contains($this->openAiApiKey, 'change-me')) {
            return $this->fallback->summarize($event);
        }

        try {
            $response = OpenAI::client($this->openAiApiKey)->responses()->create([
                'model' => $this->openAiModel,
                'instructions' => 'You summarize database change events for app owners. Write exactly one concise, human-readable sentence. Do not mention JSON or SQL.',
                'input' => json_encode([
                    'table' => $event->getTableName(),
                    'recordId' => $event->getRecordId(),
                    'eventType' => $event->getEventType()->value,
                    'oldValues' => $event->getOldValues(),
                    'newValues' => $event->getNewValues(),
                ], JSON_THROW_ON_ERROR),
                'max_output_tokens' => 80,
            ]);

            $summary = trim((string) $response->outputText);

            return $summary !== '' ? $summary : $this->fallback->summarize($event);
        } catch (\Throwable) {
            return $this->fallback->summarize($event);
        }
    }
}

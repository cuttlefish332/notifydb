<?php

namespace App\Service;

use App\Entity\ChangeEvent;

final class FakeAiSummaryService implements AiSummaryServiceInterface
{
    public function summarize(ChangeEvent $event): string
    {
        $fields = array_unique(array_merge(
            array_keys($event->getOldValues()),
            array_keys($event->getNewValues()),
        ));

        $fieldText = $fields === [] ? 'no captured fields' : implode(', ', array_slice($fields, 0, 5));

        return sprintf(
            '%s record %s in %s. Fields affected: %s.',
            ucfirst($event->getEventType()->value),
            $event->getRecordId(),
            $event->getTableName(),
            $fieldText,
        );
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517164500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether Stripe subscriptions are scheduled to cancel at period end.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user ADD COLUMN stripe_cancel_at_period_end BOOLEAN DEFAULT 0 NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE app_user ADD stripe_cancel_at_period_end BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user DROP COLUMN stripe_cancel_at_period_end');

            return;
        }

        $this->addSql('ALTER TABLE app_user DROP stripe_cancel_at_period_end');
    }
}

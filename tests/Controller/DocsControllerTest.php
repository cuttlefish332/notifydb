<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DocsControllerTest extends WebTestCase
{
    public function testDocsArePublicAndDescribeTheIntegration(): void
    {
        $client = static::createClient();

        $client->request('GET', '/docs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Send readable database change alerts from any app.');
        self::assertSelectorTextContains('body', 'POST /api/events');
        self::assertSelectorTextContains('body', 'Authorization');
        self::assertSelectorTextContains('body', 'JavaScript');
        self::assertSelectorTextContains('body', 'Production notes');
    }
}

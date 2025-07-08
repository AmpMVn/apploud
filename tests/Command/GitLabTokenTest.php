<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

final class GitLabTokenTest extends TestCase
{

    public function testGitLabTokenIsValid(): void
    {
        if (!isset($_ENV['GITLAB_BASE_URL'])) {
            self::markTestSkipped('GITLAB_BASE_URL není nastavena');
        }

        if (!\is_string($_ENV['GITLAB_BASE_URL'])) {
            self::fail('GITLAB_BASE_URL musí být string');
        }

        if (!isset($_ENV['GITLAB_TOKEN'])) {
            self::markTestSkipped('GITLAB_TOKEN není nastaven');
        }

        if (!\is_string($_ENV['GITLAB_TOKEN'])) {
            self::fail('GITLAB_TOKEN musí být string');
        }

        $client = HttpClient::create();
        $response = $client->request('GET', $_ENV['GITLAB_BASE_URL']."/user", [
            'headers' => [
                'PRIVATE-TOKEN' => $_ENV['GITLAB_TOKEN'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), 'GitLab token není platný nebo nemá správná oprávnění');
    }
}
<?php

declare(strict_types=1);

namespace App\Command;

use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AccessReportCommand extends Command
{
    private readonly HttpClientInterface $http;
    private readonly string $baseUrl;
    private readonly string $token;

    /** @var array<string, array{name: string, username: string, groups: array<string>, projects: array<string>}> */
    private array $users = [];

    public function __construct()
    {
        if (!isset($_ENV['GITLAB_TOKEN'])) {
            throw new RuntimeException('GITLAB_TOKEN neni nastaven v prostredi.');
        }

        if (!\is_string($_ENV['GITLAB_TOKEN'])) {
            throw new RuntimeException('GITLAB_TOKEN musi byt string.');
        }

        $this->http = HttpClient::create();
        $this->token = $_ENV['GITLAB_TOKEN'];

        if (!isset($_ENV['GITLAB_BASE_URL'])) {
            throw new RuntimeException('GITLAB_BASE_URL neni nastavena v prostredi.');
        }

        if (!\is_string($_ENV['GITLAB_BASE_URL'])) {
            throw new RuntimeException('GITLAB_BASE_URL musi byt string.');
        }

        $this->baseUrl = $_ENV['GITLAB_BASE_URL'];

        echo 'Pouzita Gitlab url: '.$this->baseUrl."\n";
        echo 'Pouzity token: '.$this->token."\n";

        parent::__construct('gitlab:access-report');
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Vygeneruje prehled pristupu uzivatelu v GitLabu.')
            ->addArgument('group_id', InputArgument::REQUIRED, 'ID top-level skupiny');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawGroupId = $input->getArgument('group_id');
        if (!is_numeric($rawGroupId)) {
            throw new RuntimeException('group_id musi byt cislo.');
        }
        $groupId = (int) $rawGroupId;

        $output->writeln(\sprintf('<fg=white;options=bold>Nacitam data z GitLabu pro ID: %d</>', $groupId));

        $allGroups = $this->fetchAllSubgroupsRecursive($groupId);
        $topGroup = $this->fetchGroupInfo($groupId);
        array_unshift($allGroups, $topGroup);

        $allProjects = array_merge(
            ...array_map(
                fn (array $group): array => $this->fetchProjects((int) $group['id']),
                $allGroups,
            ),
        );

        foreach ($allGroups as $group) {
            if (!isset($group['full_path'])) {
                continue;
            }
            $members = $this->fetchMembers('group', $group['id']);
            foreach ($members as $member) {
                $this->addUserAccess($member, 'group', $group['full_path']);
            }
        }

        foreach ($allProjects as $project) {
            $members = $this->fetchMembers('project', $project['id']);
            foreach ($members as $member) {
                $this->addUserAccess($member, 'project', $project['path_with_namespace']);
            }
        }

        $this->renderResults($output);

        return Command::SUCCESS;
    }

    /**
     * @return array{id: int, full_path?: string}
     */
    private function fetchGroupInfo(int $groupId): array
    {
        $response = $this->http->request('GET', $this->baseUrl."/groups/{$groupId}", [
            'headers' => ['PRIVATE-TOKEN' => $this->token],
        ]);

        /** @var array{id: int, full_path?: string} */
        return $response->toArray();
    }

    /**
     * @return array<array{id: int, full_path?: string}>
     */
    private function fetchAllSubgroupsRecursive(int $groupId): array
    {
        $response = $this->http->request('GET', $this->baseUrl."/groups/{$groupId}/subgroups", [
            'headers' => ['PRIVATE-TOKEN' => $this->token],
            'query' => ['all_available' => 'true'],
        ]);

        /** @var array<array{id: int, full_path?: string}> */
        $subgroups = $response->toArray();
        $allGroups = $subgroups;

        foreach ($subgroups as $subgroup) {
            $childGroups = $this->fetchAllSubgroupsRecursive((int) $subgroup['id']);
            $allGroups = array_merge($allGroups, $childGroups);
        }

        return $allGroups;
    }

    /**
     * @return array<array{id: int, path_with_namespace: string}>
     */
    private function fetchProjects(int $groupId): array
    {
        $response = $this->http->request('GET', $this->baseUrl."/groups/{$groupId}/projects", [
            'headers' => ['PRIVATE-TOKEN' => $this->token],
            'query' => ['include_subgroups' => 'true'],
        ]);

        /** @var array<array{id: int, path_with_namespace: string}> */
        return $response->toArray();
    }

    /**
     * @param 'group'|'project' $type
     *
     * @return array<array{username: string, name: string, access_level: int}>
     */
    private function fetchMembers(string $type, int $id): array
    {
        $endpoint = match ($type) {
            'group' => "groups/{$id}/members/all",
            'project' => "projects/{$id}/members/all"
        };

        $response = $this->http->request('GET', $this->baseUrl."/{$endpoint}", [
            'headers' => ['PRIVATE-TOKEN' => $this->token],
        ]);

        /** @var array<array{username: string, name: string, access_level: int}> */
        return $response->toArray();
    }

    /**
     * @param array{username: string, name: string, access_level: int} $member
     * @param 'group'|'project'                                        $type
     */
    private function addUserAccess(array $member, string $type, string $path): void
    {
        $username = $member['username'];
        $this->users[$username] ??= [
            'name' => $member['name'],
            'username' => $member['username'],
            'groups' => [],
            'projects' => [],
        ];

        $access = \sprintf('%s (%s)', $path, $this->formatAccessLevel($member['access_level']));

        if ('group' === $type) {
            $this->users[$username]['groups'][] = $access;
        } else {
            $this->users[$username]['projects'][] = $access;
        }
    }

    private function renderResults(OutputInterface $output): void
    {
        ksort($this->users);
        foreach ($this->users as $user) {
            $output->writeln([
                '',
                \sprintf(
                    '<fg=white;options=bold>%s</> (<fg=cyan>@%s</>)',
                    $user['name'],
                    $user['username'],
                ),
            ]);

            $output->writeln('<fg=magenta;options=bold>Skupiny:</>');
            if (!empty($user['groups'])) {
                foreach (array_unique($user['groups']) as $group) {
                    $this->printAccessLine($output, $group);
                }
            } else {
                $output->writeln('  []');
            }

            $output->writeln('<fg=magenta;options=bold>Projekty:</>');
            if (!empty($user['projects'])) {
                foreach (array_unique($user['projects']) as $project) {
                    $this->printAccessLine($output, $project);
                }
            } else {
                $output->writeln('  []');
            }
        }

        $output->writeln(['', \sprintf(
            '<fg=white;options=bold>Celkem uzivatelu:</> %d',
            \count($this->users),
        )]);
    }

    private function printAccessLine(OutputInterface $output, string $line): void
    {
        if (!preg_match('/^(.*) \((.*)\)$/', $line, $matches)) {
            return;
        }

        [$_, $path, $level] = $matches;
        $style = $this->accessLevelToStyle($level);
        $output->writeln(\sprintf('  ├─ <fg=white>%s</> <%s>(%s)</>', $path, $style, $level));
    }

    private function formatAccessLevel(int $level): string
    {
        return match ($level) {
            10 => 'Host',
            20 => 'Reporter',
            30 => 'Vyvojar',
            40 => 'Spravce',
            50 => 'Vlastnik',
            default => 'Neznamy'
        };
    }

    private function accessLevelToStyle(string $level): string
    {
        return match ($level) {
            'Host' => 'fg=gray',
            'Reporter' => 'fg=blue',
            'Vyvojar' => 'fg=green',
            'Spravce' => 'fg=yellow',
            'Vlastnik' => 'fg=red',
            default => 'fg=default'
        };
    }
}

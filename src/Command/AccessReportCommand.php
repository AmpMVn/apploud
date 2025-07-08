<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\GroupDto;
use App\Dto\MemberDto;
use App\Dto\ProjectDto;
use App\Enum\AccessLevel;
use Exception;
use InvalidArgumentException;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class AccessReportCommand extends Command
{
    private const CACHE_NAMESPACE = 'gitlab';
    private const CACHE_TTL = 3600;
    private const API_RATE_LIMIT = 10;
    private const API_RATE_INTERVAL = '1 second';

    private readonly HttpClientInterface $http;
    private readonly string $baseUrl;
    private readonly string $token;
    private FilesystemAdapter $cache;
    private RateLimiterFactory $limiter;
    private int $retryCount = 0;
    private ?ProgressBar $progressBar = null;
    private LoggerInterface $logger;

    /** @var array<string, array{name: string, username: string, groups: array<string>, projects: array<string>}> */
    private array $users = [];

    public function __construct()
    {
        parent::__construct('gitlab:access-report');

        $this->validateEnvironment();
        $token = $_ENV['GITLAB_TOKEN'];
        $baseUrl = $_ENV['GITLAB_BASE_URL'];

        if (!\is_string($token) || !\is_string($baseUrl)) {
            throw new RuntimeException('Invalid environment variables');
        }

        $this->token = $token;
        $this->baseUrl = $baseUrl;
        $this->http = HttpClient::create();

        $this->initializeCache();
        $this->initializeLogger();
        $this->initializeRateLimiter();
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
        try {
            $groupId = $this->validateGroupId($input->getArgument('group_id'));

            return $this->processGroup($groupId, $output);
        } catch (Throwable $e) {
            $this->handleError($e, $output);

            return Command::FAILURE;
        }
    }

    private function validateEnvironment(): void
    {
        if (!isset($_ENV['GITLAB_TOKEN']) || !isset($_ENV['GITLAB_BASE_URL'])) {
            throw new RuntimeException('Missing required environment variables');
        }
    }

    private function initializeCache(): void
    {
        $cacheDir = $this->ensureDirectory(__DIR__.'/../../var/cache');
        $this->cache = new FilesystemAdapter(self::CACHE_NAMESPACE, self::CACHE_TTL, $cacheDir);
    }

    private function initializeLogger(): void
    {
        $logDir = $this->ensureDirectory(__DIR__.'/../../var/log');
        $logHandler = new RotatingFileHandler(
            $logDir.'/gitlab-access.log',
            10,
            Logger::DEBUG,
        );
        $this->logger = new Logger('gitlab_access', [$logHandler]);
    }

    private function initializeRateLimiter(): void
    {
        $this->limiter = new RateLimiterFactory([
            'id' => 'gitlab_api',
            'policy' => 'token_bucket',
            'limit' => self::API_RATE_LIMIT,
            'rate' => ['interval' => self::API_RATE_INTERVAL],
        ], new InMemoryStorage());
    }

    private function ensureDirectory(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        return $path;
    }

    private function validateGroupId(mixed $rawGroupId): int
    {
        if (!is_numeric($rawGroupId)) {
            throw new RuntimeException('group_id musi byt cislo.');
        }

        return (int) $rawGroupId;
    }

    private function processGroup(int $groupId, OutputInterface $output): int
    {
        $output->writeln(\sprintf('<fg=white;options=bold>Nacitam data z GitLabu pro ID: %d</>', $groupId));

        $groups = $this->collectGroups($groupId);
        $projects = $this->collectProjects($groups);

        $this->processMembers($output, $groups, $projects);
        $this->renderResults($output);

        return Command::SUCCESS;
    }

    /**
     * @return array<GroupDto>
     */
    private function collectGroups(int $groupId): array
    {
        $allGroups = $this->fetchAllGroupsWithCache($groupId);
        $topGroup = $this->fetchGroupInfo($groupId);
        array_unshift($allGroups, $topGroup);

        return $allGroups;
    }

    /**
     * @param array<GroupDto> $groups
     *
     * @return array<ProjectDto>
     */
    private function collectProjects(array $groups): array
    {
        $allProjects = [];
        foreach ($groups as $group) {
            /** @var array<array{id: mixed, path_with_namespace: mixed}> */
            $rawData = $this->makeApiRequest(
                "groups/{$group->id}/projects",
                ['include_subgroups' => 'true'],
            );

            $projects = array_map(
                static fn (array $data): ProjectDto => ProjectDto::fromArray($data),
                $rawData,
            );

            $allProjects = array_merge($allProjects, $projects);
        }

        return $allProjects;
    }

    /**
     * @param array<GroupDto>   $groups
     * @param array<ProjectDto> $projects
     */
    private function processMembers(OutputInterface $output, array $groups, array $projects): void
    {
        $this->progressBar = $this->createProgressBar($output, \count($groups) + \count($projects));
        $output->writeln('');

        foreach ($groups as $group) {
            $this->processGroupMembers($group);
            $this->progressBar?->advance();
        }

        foreach ($projects as $project) {
            $this->processProjectMembers($project);
            $this->progressBar?->advance();
        }

        $this->progressBar?->finish();
        $output->writeln('');
    }

    private function createProgressBar(OutputInterface $output, int $max): ProgressBar
    {
        $progressBar = new ProgressBar($output, $max);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        return $progressBar;
    }

    /**
     * @return array<GroupDto>
     */
    private function fetchAllGroupsWithCache(int $groupId): array
    {
        /** @var array<array{id: mixed, full_path: mixed}> */
        $rawData = $this->withCache(
            "groups.{$groupId}",
            fn () => $this->fetchAllSubgroupsRecursive($groupId),
        );

        return array_map(
            static fn (array $data): GroupDto => GroupDto::fromArray($data),
            $rawData,
        );
    }

    private function fetchGroupInfo(int $groupId): GroupDto
    {
        /** @var array{id: mixed, full_path: mixed} */
        $response = $this->makeApiRequest("groups/{$groupId}");

        return GroupDto::fromArray($response);
    }

    /**
     * @return array<array{id: mixed, full_path: mixed}>
     */
    private function fetchAllSubgroupsRecursive(int $groupId): array
    {
        $subgroups = $this->fetchDirectSubgroups($groupId);

        return $this->fetchNestedSubgroups($subgroups);
    }

    /**
     * @return array<array{id: mixed, full_path: mixed}>
     */
    private function fetchDirectSubgroups(int $groupId): array
    {
        /** @var array<array{id: mixed, full_path: mixed}> */
        return $this->makeApiRequest(
            "groups/{$groupId}/subgroups",
            ['all_available' => 'true'],
        );
    }

    /**
     * @param array<array{id: mixed, full_path: mixed}> $groups
     *
     * @return array<array{id: mixed, full_path: mixed}>
     */
    private function fetchNestedSubgroups(array $groups): array
    {
        $allGroups = $groups;
        foreach ($groups as $group) {
            if (!isset($group['id']) || !is_numeric($group['id'])) {
                throw new RuntimeException('Invalid group structure');
            }
            $childGroups = $this->fetchAllSubgroupsRecursive((int) $group['id']);
            $allGroups = array_merge($allGroups, $childGroups);
        }

        return $allGroups;
    }

    private function processGroupMembers(GroupDto $group): void
    {
        $members = $this->fetchGroupMembers($group->id);
        foreach ($members as $member) {
            $this->addUserAccess($member, 'group', $group->fullPath);
        }
    }

    private function processProjectMembers(ProjectDto $project): void
    {
        $members = $this->fetchProjectMembers($project->id);
        foreach ($members as $member) {
            $this->addUserAccess($member, 'project', $project->pathWithNamespace);
        }
    }

    /**
     * @return array<MemberDto>
     */
    private function fetchGroupMembers(int $groupId): array
    {
        return $this->fetchMembers('group', $groupId);
    }

    /**
     * @return array<MemberDto>
     */
    private function fetchProjectMembers(int $projectId): array
    {
        return $this->fetchMembers('project', $projectId);
    }

    /**
     * @return array<MemberDto>
     */
    private function fetchMembers(string $type, int $id): array
    {
        if (!\in_array($type, ['group', 'project'], true)) {
            throw new InvalidArgumentException('Invalid member type');
        }

        $endpoint = match ($type) {
            'group' => "groups/{$id}/members/all",
            'project' => "projects/{$id}/members/all",
        };

        /** @var array<array{username: mixed, name: mixed, access_level: mixed}> */
        $response = $this->makeApiRequest($endpoint);

        return array_map(
            fn (array $data): MemberDto => MemberDto::fromArray($data),
            $response,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function makeApiRequest(string $endpoint, array $query = []): array
    {
        $this->waitForRateLimit();

        $response = $this->http->request('GET', "{$this->baseUrl}/{$endpoint}", [
            'headers' => ['PRIVATE-TOKEN' => $this->token],
            'query' => $query,
        ]);

        /** @var array<string, mixed> */
        return $response->toArray();
    }

    private function waitForRateLimit(): void
    {
        $limiter = $this->limiter->create();

        try {
            $limiter->consume(1)->wait();
        } catch (Exception $e) {
            $this->logger->warning('Rate limit prekrocen: '.$e->getMessage());
            $waitTime = pow(2, $this->retryCount);
            sleep($waitTime);
            ++$this->retryCount;
            $this->waitForRateLimit();
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withCache(string $key, callable $callback): mixed
    {
        $cacheKey = self::CACHE_NAMESPACE.'.'.$key;
        $isCached = $this->cache->hasItem($cacheKey);

        /** @var T */
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($callback) {
            $this->logger->info('Cache MISS');
            $item->expiresAfter(self::CACHE_TTL);

            return $callback();
        });

        if ($isCached) {
            $this->logger->info('Cache HIT');
        }

        return $result;
    }

    private function addUserAccess(MemberDto $member, string $type, string $path): void
    {
        $username = $member->username;
        $this->users[$username] ??= [
            'name' => $member->name,
            'username' => $username,
            'groups' => [],
            'projects' => [],
        ];

        $access = \sprintf(
            '%s (%s)',
            $path,
            AccessLevel::fromInt($member->accessLevel)->getLabel(),
        );

        if ('group' === $type) {
            $this->users[$username]['groups'][] = $access;
        } else {
            $this->users[$username]['projects'][] = $access;
        }
    }

    private function handleError(Throwable $e, OutputInterface $output): void
    {
        $this->logger->error('Chyba pri zpracovani: '.$e->getMessage());
        $output->writeln('<error>'.$e->getMessage().'</error>');
    }

    private function renderResults(OutputInterface $output): void
    {
        ksort($this->users);
        foreach ($this->users as $user) {
            $this->renderUser($output, $user);
        }

        $output->writeln(['', \sprintf(
            '<fg=white;options=bold>Celkem uzivatelu:</> %d',
            \count($this->users),
        )]);
    }

    /**
     * @param array{name: string, username: string, groups: array<string>, projects: array<string>} $user
     */
    private function renderUser(OutputInterface $output, array $user): void
    {
        $output->writeln([
            '',
            \sprintf(
                '<fg=white;options=bold>%s</> (<fg=cyan>@%s</>)',
                $user['name'],
                $user['username'],
            ),
        ]);

        $this->renderUserSection($output, 'Skupiny', $user['groups']);
        $this->renderUserSection($output, 'Projekty', $user['projects']);
    }

    /**
     * @param array<string> $items
     */
    private function renderUserSection(OutputInterface $output, string $title, array $items): void
    {
        $output->writeln(\sprintf('<fg=magenta;options=bold>%s:</>', $title));
        if (empty($items)) {
            $output->writeln('  []');

            return;
        }

        foreach (array_unique($items) as $item) {
            $this->printAccessLine($output, $item);
        }
    }

    private function printAccessLine(OutputInterface $output, string $line): void
    {
        if (!preg_match('/^(.*) \((.*)\)$/', $line, $matches)) {
            return;
        }

        [, $path, $level] = $matches;
        foreach (AccessLevel::cases() as $accessLevel) {
            if ($accessLevel->getLabel() === $level) {
                $output->writeln(\sprintf(
                    '  ├─ <fg=white>%s</> <%s>(%s)</>',
                    $path,
                    $accessLevel->getStyle(),
                    $level,
                ));

                return;
            }
        }
    }
}

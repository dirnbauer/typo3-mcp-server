<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * OAuth token management for MCP server
 */
final class OAuthManageCommand extends Command
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteBaseUrlResolver $baseUrlResolver,
    ) {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->setDescription('Manage OAuth tokens for MCP server')
            ->setHelp('This command helps manage OAuth tokens and provides authorization URLs for MCP clients.')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform: url, create, list, revoke, cleanup')
            ->addArgument('username', InputArgument::OPTIONAL, 'Backend username (required for url, create, list, revoke actions)')
            ->addOption('client-name', 'c', InputOption::VALUE_OPTIONAL, 'Client name for authorization URL', 'MCP Client')
            ->addOption('token-id', 't', InputOption::VALUE_OPTIONAL, 'Token ID to revoke (for revoke action)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Revoke all tokens for user (for revoke action)')
            ->addOption('ttl-days', null, InputOption::VALUE_OPTIONAL, 'Token lifetime in days (for create action; default 30)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actionArgument = $input->getArgument('action');
        $usernameArgument = $input->getArgument('username');
        $action = is_string($actionArgument) ? $actionArgument : '';
        $username = is_string($usernameArgument) ? $usernameArgument : null;

        try {
            switch ($action) {
                case 'url':
                    return $this->generateAuthUrl($input, $output, $username);
                case 'create':
                    return $this->createToken($input, $output, $username);
                case 'list':
                    return $this->listTokens($input, $output, $username);
                case 'revoke':
                    return $this->revokeTokens($input, $output, $username);
                case 'cleanup':
                    return $this->cleanupTokens($input, $output);
                default:
                    $output->writeln('<error>Invalid action. Use: url, create, list, revoke, or cleanup</error>');
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function generateAuthUrl(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln('<error>Username is required for URL generation</error>');
            return Command::FAILURE;
        }

        // Verify user exists
        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $clientNameOption = $input->getOption('client-name');
        $clientName = is_string($clientNameOption) ? $clientNameOption : 'MCP Client';
        $baseUrl = $this->baseUrlResolver->resolveConfiguredOrPlaceholder();

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $authUrl = $this->oauthService->generateAuthorizationUrl(
            $baseUrl,
            $clientName,
            codeChallenge: $challenge,
        );

        $output->writeln("<info>OAuth Authorization URL for user '$username':</info>");
        $output->writeln("<info>$authUrl</info>");
        $output->writeln('');
        $output->writeln('<comment>PKCE verifier (keep secret until the code exchange):</comment>');
        $output->writeln($verifier);
        $output->writeln('');
        $output->writeln('Instructions:');
        $output->writeln('1. Open this URL in your browser');
        $output->writeln('2. Log in to TYPO3 backend if not already logged in');
        $output->writeln('3. Authorize the MCP client access');
        $output->writeln('4. Exchange the displayed code with this verifier at /mcp_oauth/token');
        $output->writeln('5. Use the returned access token in your MCP client configuration');

        return Command::SUCCESS;
    }

    /**
     * Mint a static bearer token for clients without OAuth discovery
     * (CI jobs, scripted MCP clients, `claude mcp add --header`).
     */
    private function createToken(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if ($username === null || $username === '') {
            $output->writeln('<error>Username is required for token creation</error>');
            return Command::FAILURE;
        }

        $user = $this->findUser($username);
        if ($user === null) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $clientNameOption = $input->getOption('client-name');
        $clientName = is_string($clientNameOption) && trim($clientNameOption) !== '' ? trim($clientNameOption) : 'MCP Client';

        $ttlDaysOption = $input->getOption('ttl-days');
        $ttlSeconds = null;
        if ($ttlDaysOption !== null) {
            $ttlDays = is_int($ttlDaysOption) ? (string)$ttlDaysOption : (is_string($ttlDaysOption) ? trim($ttlDaysOption) : '');
            if ($ttlDays === '' || !ctype_digit($ttlDays) || (int)$ttlDays < 1) {
                $output->writeln('<error>--ttl-days must be a positive integer</error>');
                return Command::FAILURE;
            }
            $ttlSeconds = (int)$ttlDays * 86400;
        }

        $resource = $this->baseUrlResolver->getConfiguredBaseUrl();
        $accessToken = $this->oauthService->createDirectAccessToken(
            $user['uid'],
            $clientName,
            null,
            $resource !== null ? rtrim($resource, '/') . '/mcp' : '',
            $ttlSeconds,
        );

        $output->writeln("<info>Access token created for user '$username':</info>");
        $output->writeln('');
        $output->writeln($accessToken);
        $output->writeln('');
        $output->writeln("Client: <info>$clientName</info>");
        $output->writeln('Expires: <info>' . date('Y-m-d H:i:s', time() + ($ttlSeconds ?? 30 * 86400)) . '</info>');
        if ($resource === null) {
            $output->writeln('<comment>No SYS.reverseProxyBaseUrl configured: the token is not bound to a resource URL.</comment>');
        }
        $output->writeln('');
        $output->writeln('<comment>Store it now - it cannot be retrieved later (only a hash is kept).</comment>');
        $output->writeln('Use it as a static Bearer header in your MCP client, e.g.:');
        $output->writeln('  claude mcp add --transport http typo3 <server-url>/mcp --header "Authorization: Bearer <token>"');

        return Command::SUCCESS;
    }

    private function listTokens(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln('<error>Username is required for token listing</error>');
            return Command::FAILURE;
        }

        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        /** @var list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens */
        $tokens = $this->oauthService->getUserTokens($user['uid']);

        if (empty($tokens)) {
            $output->writeln("<info>No active tokens found for user '$username'</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<info>Active tokens for user '$username':</info>");
        $output->writeln('');

        foreach ($tokens as $token) {
            $created = date('Y-m-d H:i:s', $token['crdate']);
            $expires = date('Y-m-d H:i:s', $token['expires']);
            $lastUsed = $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : 'Never';

            $output->writeln("Token ID: <info>{$token['uid']}</info>");
            $output->writeln("Client: <info>{$token['client_name']}</info>");
            $output->writeln("Created: <info>$created</info>");
            $output->writeln("Expires: <info>$expires</info>");
            $output->writeln("Last Used: <info>$lastUsed</info>");
            $output->writeln('Token: <comment>' . substr($token['token'], 0, 20) . '...</comment>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function revokeTokens(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln('<error>Username is required for token revocation</error>');
            return Command::FAILURE;
        }

        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $revokeAll = $input->getOption('all');
        $tokenIdOption = $input->getOption('token-id');
        $tokenId = is_string($tokenIdOption) || is_int($tokenIdOption) ? (string)$tokenIdOption : null;

        if ($revokeAll) {
            $count = $this->oauthService->revokeAllUserTokens($user['uid']);
            $output->writeln("<info>Revoked $count tokens for user '$username'</info>");
        } elseif ($tokenId) {
            $success = $this->oauthService->revokeToken((int)$tokenId, $user['uid']);
            if ($success) {
                $output->writeln("<info>Token $tokenId revoked successfully</info>");
            } else {
                $output->writeln("<error>Token $tokenId not found or not owned by user</error>");
                return Command::FAILURE;
            }
        } else {
            $output->writeln('<error>Either --token-id or --all option is required for revocation</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function cleanupTokens(InputInterface $input, OutputInterface $output): int
    {
        $this->oauthService->cleanupExpired();

        $output->writeln('<info>Cleanup completed - expired tokens and authorization codes removed</info>');
        return Command::SUCCESS;
    }

    /**
     * @return array{uid: int, username: string}|null
     */
    private function findUser(string $username): ?array
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('be_users');

        $queryBuilder = $connection->createQueryBuilder();
        $user = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($user)) {
            return null;
        }

        $uid = $user['uid'] ?? 0;
        $resolvedUsername = $user['username'] ?? '';

        return [
            'uid' => is_int($uid) ? $uid : (is_numeric($uid) ? (int)$uid : 0),
            'username' => is_string($resolvedUsername) ? $resolvedUsername : '',
        ];
    }
}

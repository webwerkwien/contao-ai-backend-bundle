<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

class SystemPromptProvider
{
    private ?string $cachedTemplate = null;

    public function __construct(
        private readonly string $promptFile,
    ) {
    }

    /**
     * Names of every tool the bundle ships, regardless of who can use it. Used
     * to render the "actions explicitly NOT available to you" section so the
     * model can't extrapolate generic CRUD coverage from training data.
     *
     * @var list<string>
     */
    private const ALL_KNOWN_TOOL_NAMES = [
        'news_create', 'news_update', 'news_delete', 'news_read',
        'page_create', 'page_update', 'page_delete', 'page_publish', 'page_read',
        'article_create', 'article_update', 'article_delete', 'article_read',
        'content_create', 'content_update', 'content_delete', 'content_read',
        'record_list',
        'dca_schema', 'listing_config', 'search_query',
    ];

    /**
     * @param list<string> $allowedToolNames Already-filtered names from
     *   ToolAccessChecker::listAllowedTools — NOT raw class-level AsTool names.
     *   The class-level set leaks admin-only sub-tools (news_delete) into the
     *   prompt for non-admin users, which made the model embellish its
     *   capability description and start unsolvable confirmation loops.
     */
    public function forUser(BackendUser $user, array $allowedToolNames): string
    {
        // H-5: sanitize all template substitutions — values flow into the system prompt
        // unmodified, so a hostile username/language could inject Markdown sections or
        // override prior instructions. Identifier-shaped fields are regex-validated;
        // the tool list is filtered to a strict identifier alphabet.
        $username = $this->sanitizeIdentifier((string) ($user->username ?? ''), 64) ?: '(invalid)';
        $locale   = $this->sanitizeIdentifier((string) ($user->language ?? ''), 16) ?: 'de';
        $isAdmin  = $user->isAdmin ? 'yes' : 'no';

        $allowed = [];
        foreach ($allowedToolNames as $name) {
            if (\is_string($name) && preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
                $allowed[$name] = true;
            }
        }
        $allowedNames = array_keys($allowed);
        $deniedNames  = array_values(array_diff(self::ALL_KNOWN_TOOL_NAMES, $allowedNames));

        $tools = $allowedNames ? '- ' . implode("\n- ", $allowedNames) : '(none)';
        $denied = $deniedNames ? '- ' . implode("\n- ", $deniedNames) : '(none)';

        return strtr($this->loadTemplate(), [
            '{{username}}'   => $username,
            '{{locale}}'     => $locale,
            '{{admin}}'      => $isAdmin,
            '{{tools}}'      => $tools,
            '{{tools_denied}}' => $denied,
        ]);
    }

    private function sanitizeIdentifier(string $value, int $maxLen): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,' . $maxLen . '}$/', $value)) {
            return '';
        }
        return $value;
    }

    private function loadTemplate(): string
    {
        if (null !== $this->cachedTemplate) {
            return $this->cachedTemplate;
        }

        $path = $this->resolvePath();
        if (null === $path) {
            throw new \RuntimeException(\sprintf('System prompt file not found at "%s" nor in the local fallback path.', $this->promptFile));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Failed to read system prompt file at "%s".', $path));
        }

        return $this->cachedTemplate = $contents;
    }

    private function resolvePath(): ?string
    {
        if (is_readable($this->promptFile)) {
            return $this->promptFile;
        }
        $local = \dirname(__DIR__) . '/Resources/prompts/system.md';
        if (is_readable($local)) {
            return $local;
        }
        return null;
    }
}

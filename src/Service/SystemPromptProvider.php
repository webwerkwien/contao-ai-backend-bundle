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
     * @param list<AbstractCoreCommandTool> $allowedTools
     */
    public function forUser(BackendUser $user, array $allowedTools): string
    {
        // H-5: sanitize all template substitutions — values flow into the system prompt
        // unmodified, so a hostile username/language could inject Markdown sections or
        // override prior instructions. Identifier-shaped fields are regex-validated;
        // the tool list is filtered to a strict identifier alphabet.
        $username = $this->sanitizeIdentifier((string) ($user->username ?? ''), 64) ?: '(invalid)';
        $locale   = $this->sanitizeIdentifier((string) ($user->language ?? ''), 16) ?: 'de';
        $isAdmin  = $user->isAdmin ? 'yes' : 'no';

        $names = [];
        foreach ($allowedTools as $tool) {
            foreach ($tool->getToolNames() as $name) {
                if (\is_string($name) && preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
                    $names[$name] = true;
                }
            }
        }
        $tools = implode(', ', array_keys($names)) ?: '(none)';

        return strtr($this->loadTemplate(), [
            '{{username}}' => $username,
            '{{locale}}'   => $locale,
            '{{admin}}'    => $isAdmin,
            '{{tools}}'    => $tools,
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

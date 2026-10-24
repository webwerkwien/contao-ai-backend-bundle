<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Which files the chat takes, from Contao's own settings (v1.0.0).
 *
 * No limits of its own: the upload types and the file size are the ones an
 * editor already knows from the file manager. What a converter cannot read is
 * left out even where Contao allows it.
 */
final class AttachmentPolicy
{
    public const MAX_ATTACHMENTS = 5;

    /** Contao's own default for maxFileSize (contao/config/default.php). */
    private const DEFAULT_MAX_FILE_SIZE = 2048000;

    /**
     * @param iterable<AttachmentConverterInterface> $converters in tag priority order, highest first
     */
    public function __construct(
        private readonly ContaoFramework $framework,
        #[AutowireIterator(AttachmentConverterInterface::TAG)]
        private readonly iterable $converters,
    ) {
    }

    public function converterFor(string $extension): ?AttachmentConverterInterface
    {
        foreach ($this->converters as $converter) {
            if (\in_array($extension, $converter->extensions(), true)) {
                return $converter;
            }
        }

        return null;
    }

    /**
     * @return list<string> Contao's upload types, lower case
     */
    public function uploadTypes(): array
    {
        $types = array_map(static fn (string $type): string => strtolower(trim($type)), explode(',', (string) $this->config('uploadTypes')));

        return array_values(array_unique(array_filter($types, static fn (string $type): bool => '' !== $type)));
    }

    /**
     * @return list<string> what Contao allows and a converter reads: the file picker's list
     */
    public function readableTypes(): array
    {
        return array_values(array_filter($this->uploadTypes(), fn (string $type): bool => null !== $this->converterFor($type)));
    }

    public function maxFileSize(): int
    {
        $size = (int) $this->config('maxFileSize');

        return $size > 0 ? $size : self::DEFAULT_MAX_FILE_SIZE;
    }

    /**
     * The largest request body PHP reads (post_max_size; 0 there means no limit).
     * A larger one arrives empty, which would read as a missing CSRF token.
     */
    public function maxRequestBytes(): int
    {
        $bytes = self::iniBytes((string) \ini_get('post_max_size'));

        return $bytes > 0 ? $bytes : \PHP_INT_MAX;
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    private function config(string $key): mixed
    {
        $this->framework->initialize();

        return $this->framework->getAdapter(Config::class)->get($key);
    }
}

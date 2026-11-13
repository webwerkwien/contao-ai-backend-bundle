<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Attachment;

use Webwerkwien\ContaoAiBackendBundle\Exception\AttachmentRefusedException;

/**
 * Checks the files of one chat message and hands them to their converters (v1.0.0).
 *
 * The browser checks count, type and size too, for a quick answer; this is the
 * check that counts. Order: count, name, type, size, content, model. Cheap before
 * expensive, and a file is decoded only once its type is known to be readable.
 */
final class AttachmentReader
{
    public function __construct(private readonly AttachmentPolicy $policy)
    {
    }

    /**
     * @param mixed        $raw      the request's `attachments` value
     * @param list<string> $readable AttachmentContent kinds the model reads besides text
     *
     * @throws AttachmentRefusedException
     */
    public function read(mixed $raw, array $readable, string $model): AcceptedAttachments
    {
        if (null === $raw || [] === $raw) {
            return new AcceptedAttachments([], []);
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new AttachmentRefusedException('attachment_invalid', ['?']);
        }
        if (\count($raw) > AttachmentPolicy::MAX_ATTACHMENTS) {
            throw new AttachmentRefusedException('attachments_too_many', [AttachmentPolicy::MAX_ATTACHMENTS]);
        }

        $files = [];
        $contents = [];
        $seen = [];
        foreach ($raw as $entry) {
            $name = self::cleanName(\is_array($entry) ? ($entry['name'] ?? null) : null);
            $data = \is_array($entry) ? ($entry['data'] ?? null) : null;
            if (null === $name || !\is_string($data)) {
                throw new AttachmentRefusedException('attachment_invalid', [$name ?? '?']);
            }

            // Tools of an add-on find a file by its name.
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw new AttachmentRefusedException('attachment_duplicate', [$name]);
            }
            $seen[$key] = true;

            $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
            if (!\in_array($extension, $this->policy->uploadTypes(), true)) {
                throw new AttachmentRefusedException('attachment_type_not_allowed', [$name]);
            }
            $converter = $this->policy->converterFor($extension);

            // base64 is 4/3 of the file: too large is known before decoding.
            $maxSize = $this->policy->maxFileSize();
            if (\strlen($data) > (int) ceil($maxSize / 3) * 4 + 4) {
                throw new AttachmentRefusedException('attachment_too_large', [$name, self::megabytes($maxSize)]);
            }
            $bytes = base64_decode($data, true);
            if (false === $bytes || '' === $bytes) {
                throw new AttachmentRefusedException('attachment_invalid', [$name]);
            }
            if (\strlen($bytes) > $maxSize) {
                throw new AttachmentRefusedException('attachment_too_large', [$name, self::megabytes($maxSize)]);
            }

            $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);

            // Not readable, but allowed for uploads: only its name reaches the model,
            // and file_store may put it into the file manager. Where the type is known,
            // the content must fit it, as for the readable ones.
            if (null === $converter) {
                if (!self::fitsKnownType($extension, $mime)) {
                    throw new AttachmentRefusedException('attachment_mismatch', [$name]);
                }
                $files[] = new ChatAttachment($name, $extension, $mime, $bytes, false);
                continue;
            }

            if (!$converter->acceptsMime($extension, $mime)) {
                throw new AttachmentRefusedException('attachment_mismatch', [$name]);
            }

            $file = new ChatAttachment($name, $extension, $mime, $bytes);
            foreach ($converter->convert($file) as $content) {
                if (AttachmentContent::TEXT !== $content->kind && !\in_array($content->kind, $readable, true)) {
                    throw new AttachmentRefusedException('attachment_model_cannot_read', [$name, $model]);
                }
                $contents[] = $content;
            }
            $files[] = $file;
        }

        return new AcceptedAttachments($files, $contents);
    }

    /**
     * What finfo reports for the types without a converter that it recognises. A type
     * missing here is accepted as it comes, except a script under a harmless name.
     */
    private const KNOWN_TYPES = [
        'zip'  => ['application/zip'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'odt'  => ['application/zip', 'application/vnd.oasis.opendocument.text'],
        'ods'  => ['application/zip', 'application/vnd.oasis.opendocument.spreadsheet'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        'svg'  => ['image/svg+xml'],
        'svgz' => ['application/gzip', 'application/x-gzip'],
        'avif' => ['image/avif'],
        'heic' => ['image/heic'],
        'ico'  => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'mp3'  => ['audio/mpeg'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
        'ogg'  => ['audio/ogg', 'video/ogg', 'application/ogg'],
    ];

    private const SCRIPT_TYPES = ['text/x-php', 'application/x-httpd-php', 'application/x-executable', 'application/x-dosexec', 'application/x-sharedlib', 'text/x-shellscript'];

    public static function fitsKnownType(string $extension, string $mime): bool
    {
        if (\in_array($mime, self::SCRIPT_TYPES, true)) {
            return false;
        }

        return !isset(self::KNOWN_TYPES[$extension]) || \in_array($mime, self::KNOWN_TYPES[$extension], true);
    }

    /**
     * The file name without path parts and control characters; null when nothing is left.
     */
    public static function cleanName(mixed $name): ?string
    {
        if (!\is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        $name = str_replace('\\', '/', $name);
        $name = substr((string) strrchr('/' . $name, '/'), 1);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name), ' .');
        $name = self::composed($name);

        return '' === $name || mb_strlen($name) > 255 ? null : $name;
    }

    /**
     * One spelling per name: macOS hands over "ö" as o + combining diaeresis, the
     * model types the composed letter. Without this the duplicate check saw two
     * names, and a tool asking ChatAttachments for the file found none.
     */
    public static function composed(string $name): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name) : $name;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_000_000, 1, '.', ''), '0'), '.') . ' MB';
    }
}

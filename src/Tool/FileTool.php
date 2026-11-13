<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachments;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiCoreBundle\Command\FileDeleteCommand;

/**
 * The file manager in the chat (v1.3.0), through core-bundle v1.18.0's file commands.
 *
 * Each operation asks what the back end asks (tl_files.php and DC_Folder, 5.7 and 6.0):
 * the file operation right (f1 upload, f2 rename and move, f3 delete, f4 delete a folder
 * with content), the file mount for every path involved, and the data container action
 * on tl_files. The right is also part of ToolAccessChecker, so a tool the user may not
 * use is not offered at all.
 *
 * Beyond the back end: a rename or a move asks first when a text field names the path
 * (Contao's UUID references survive it, a written path does not), and a delete is
 * refused while the file is used and always asks first. Storing never overwrites
 * silently: an existing name gets one question, replace or a free name.
 */
#[AsTool('file_usages', 'Where a file or folder below files/ is used: file fields (by UUID) and text fields (by path). pathsOnly: only the paths in text fields, which a rename or a move breaks.', method: 'usages')]
#[AsTool('file_store', 'Store a file attached to the current message in the file manager. attachment is its name as shown ("Anhang: …"); folder a folder below files/ the user may use — ask the user if not given. name optionally renames it (same extension). If the name exists, the tool asks the user once: onConflict "replace" overwrites (the file keeps its UUID), "rename" stores it under a free name. Pass onConflict only after the user decided. Answers path and uuid; set an image with content_update (singleSRC = uuid).', method: 'store')]
#[AsTool('folder_create', 'Create a folder below files/, e.g. files/team/2026. Parent folders are created as needed.', method: 'createFolder')]
#[AsTool('file_rename', 'Rename a file or folder below files/; name is the new name, not a path, and a file keeps its extension. Asks the user first when text fields name the path.', method: 'rename')]
#[AsTool('file_move', 'Move a file or folder into another folder below files/ (to; "files" for the top level). The name stays. Asks the user first when text fields name the path.', method: 'move')]
#[AsTool('file_delete', 'Delete a file or folder below files/. Refused while it is used anywhere (the answer lists where); otherwise always asks the user first. There is no undo.', method: 'delete')]
#[AsTool('file_meta', 'Set metadata of a file below files/ in one language: title, alt, link, caption (only these keys). lang is the language of the root page, e.g. de.', method: 'meta')]
class FileTool extends AbstractCoreCommandTool
{
    /** Temp files of a staged replace live this long; older ones are removed on every store. */
    private const TEMP_MAX_AGE = 600;

    private const TEMP_PREFIX = 'chat-';

    private const META_KEYS = ['title', 'alt', 'link', 'caption'];

    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ConsoleRunner $console,
        private readonly ChatAttachments $attachments,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'file_usages';
    }

    /**
     * Only where the core has the usage search (v1.18.0), which the others rely on.
     */
    public function isAccessibleBy(BackendUser $user): bool
    {
        return $this->console->has('contao:file:usages') && parent::isAccessibleBy($user);
    }

    /**
     * @param string $path      File or folder below files/
     * @param bool   $pathsOnly Only paths in text fields (what a rename or a move breaks)
     */
    public function usages(string $path, bool $pathsOnly = false): string
    {
        $this->assertAccess('file_usages');
        $path = $this->mountedPath($path);

        [$code, $output] = $this->console->run(self::given([
            'command'      => 'contao:file:usages',
            '--path'       => $path,
            '--paths-only' => $pathsOnly ?: null,
        ]));

        return $this->answer($code, $output, 'file_usages');
    }

    /**
     * @param string $path Folder to create below files/, e.g. files/team/2026
     */
    public function createFolder(string $path): string
    {
        $this->assertAccess('folder_create');
        $user = $this->requireBackendUser();
        $this->assertGranted(ContaoCorePermissions::USER_CAN_UPLOAD_FILES, null, 'Keine Berechtigung, Ordner anzulegen.');
        $path = $this->mountedPath($path);
        $parent = $this->mountedPath(\dirname($path), true);
        $this->assertAction(new CreateAction('tl_files', ['id' => $parent . '/__new__', 'pid' => $parent]), $path);

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:folder:create',
            '--path'     => $path,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'folder_create');
    }

    /**
     * @param string $path File or folder below files/
     * @param string $name The new name, not a path; a file keeps its extension
     */
    public function rename(string $path, string $name): string
    {
        $this->assertAccess('file_rename');
        $user = $this->requireBackendUser();
        $this->assertGranted(ContaoCorePermissions::USER_CAN_RENAME_FILE, null, 'Keine Berechtigung, Dateien umzubenennen.');
        // tl_files.name is an excluded field: the back end's form needs it too.
        $this->assertGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_files::name', 'Keine Berechtigung für das Feld „Dateiname“.');
        $path = $this->mountedPath($path);
        $this->assertAction(new UpdateAction('tl_files', ['id' => $path]), $path);

        $asked = $this->askWhenTextNamesPath('file_rename', "rename:$path:$name", $path, \sprintf('„%s“ in „%s“ umbenennen?', $path, $name));
        if (null !== $asked) {
            return $asked;
        }

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:file:rename',
            '--path'     => $path,
            '--name'     => $name,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'file_rename');
    }

    /**
     * @param string $path File or folder below files/
     * @param string $to   Folder to move it into; "files" for the top level
     */
    public function move(string $path, string $to): string
    {
        $this->assertAccess('file_move');
        $user = $this->requireBackendUser();
        $this->assertGranted(ContaoCorePermissions::USER_CAN_RENAME_FILE, null, 'Keine Berechtigung, Dateien zu verschieben.');
        $path = $this->mountedPath($path);
        $to = $this->mountedPath($to, true);
        $this->assertAction(new UpdateAction('tl_files', ['id' => $path, 'pid' => \dirname($path)], ['pid' => $to]), $path);

        $asked = $this->askWhenTextNamesPath('file_move', "move:$path:$to", $path, \sprintf('„%s“ nach „%s“ verschieben?', $path, $to));
        if (null !== $asked) {
            return $asked;
        }

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:file:move',
            '--path'     => $path,
            '--to'       => $to,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'file_move');
    }

    /**
     * @param string $path File or folder below files/
     */
    public function delete(string $path): string
    {
        $this->assertAccess('file_delete');
        $user = $this->requireBackendUser();
        $path = $this->mountedPath($path);
        $absolute = $this->projectDir . '/' . $path;

        // As tl_files.php: a file needs f3, a folder with content f4. Like Contao's
        // Finder, dot files (.public, .nosync) are not content.
        $content = is_dir($absolute) ? array_filter((array) scandir($absolute), static fn ($e): bool => !str_starts_with((string) $e, '.')) : [];
        if ([] !== $content) {
            $this->assertGranted(ContaoCorePermissions::USER_CAN_DELETE_RECURSIVELY, null, 'Keine Berechtigung, Ordner samt Inhalt zu löschen.');
        } else {
            $this->assertGranted(ContaoCorePermissions::USER_CAN_DELETE_FILE, null, 'Keine Berechtigung, Dateien zu löschen.');
        }
        $this->assertAction(new DeleteAction('tl_files', ['id' => $path]), $path);

        $usages = $this->findUsages($path, false);
        if ([] !== $usages) {
            throw new ToolRefusedException(\sprintf(
                '„%s“ wird noch verwendet und wurde nicht gelöscht: %s. Erst die Verwendungen entfernen.',
                $path,
                self::describe($usages),
            ));
        }

        $asked = $this->requireConfirmation('file_delete', "delete:$path", \sprintf('„%s“ endgültig löschen? Dateien lassen sich nicht wiederherstellen.', $path), ['path' => $path]);
        if (null !== $asked) {
            return $asked;
        }

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:file:delete',
            '--path'     => $path,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'file_delete');
    }

    /**
     * @param string                $path   File below files/
     * @param array<string,scalar|null>|string $fields Metadata fields and their values, as a list of name and value (["alt", "Das Team", "title", "Team"]) or as an object ({"alt": "Das Team"}). Field names: title, alt, link, caption.
     * @param string                $lang   Language of the root page, e.g. de
     */
    public function meta(string $path, array|string $fields, string $lang): string
    {
        $this->assertAccess('file_meta');
        $user = $this->requireBackendUser();
        $path = $this->mountedPath($path);
        $this->assertAction(new UpdateAction('tl_files', ['id' => $path]), $path);

        // symfony/ai describes the map as a list: the model sends name and value in turn.
        $fields = self::normalizeFieldsPayload(self::payload($fields, 'fields'));
        $unknown = array_diff(array_keys($fields), self::META_KEYS);
        $notText = array_filter($fields, static fn (mixed $v): bool => !\is_scalar($v) && null !== $v);
        if ([] === $fields || [] !== $unknown || [] !== $notText) {
            throw new ToolRefusedException('Metadaten: nur title, alt, link und caption, mindestens eines davon.');
        }
        if (!preg_match('/^[a-z]{2}(?:[_-][A-Za-z]{2})?$/', $lang)) {
            throw new ToolRefusedException('lang ist die Sprache des Startpunkts, z. B. de.');
        }

        $set = [];
        foreach ($fields as $key => $value) {
            $set[] = $key . '=' . (string) $value;
        }

        [$code, $output] = $this->console->run(self::given([
            'command'    => 'contao:file:meta',
            '--path'     => $path,
            '--lang'     => $lang,
            '--set'      => $set,
            '--operator' => (string) ($user->username ?? ''),
        ]));

        return $this->answer($code, $output, 'file_meta');
    }

    /**
     * @param string $attachment Name of the file attached to the current message
     * @param string $folder     Folder below files/
     * @param string $name       Optional new name with the same extension
     * @param string $onConflict Only after the user decided: "replace" or "rename"
     */
    public function store(string $attachment, string $folder, string $name = '', string $onConflict = ''): string
    {
        $this->assertAccess('file_store');
        $user = $this->requireBackendUser();
        $this->assertGranted(ContaoCorePermissions::USER_CAN_UPLOAD_FILES, null, 'Keine Berechtigung, Dateien hochzuladen.');
        $folder = $this->mountedPath($folder, true);
        if (!is_dir($this->projectDir . '/' . $folder)) {
            throw new ToolRefusedException(\sprintf('Den Ordner „%s“ gibt es nicht. Mit folder_create anlegen oder einen anderen nennen.', $folder));
        }
        if (!\in_array($onConflict, ['', 'replace', 'rename'], true)) {
            throw new ToolRefusedException('onConflict ist "replace" oder "rename".');
        }
        $this->removeStaleTempFiles();

        $file = $this->attachments->get($attachment);
        $fileName = '' !== $name ? $name : ($file->name ?? $attachment);
        $target = $this->mountedPath($folder . '/' . self::cleanName($fileName, $file?->extension));
        $this->assertAction(new CreateAction('tl_files', ['pid' => $folder, 'type' => 'file']), $target);

        // The model may spell the name differently when it answers.
        $key = 'store:' . mb_strtolower($target);
        $userId = (int) ($user->id ?? 0);

        if (null === $file) {
            // The attachment exists only in its own message. A later message can
            // only finish a store that was staged for this target.
            if ('' === $onConflict) {
                throw new ToolRefusedException(\sprintf('Der Anhang „%s“ ist nicht in dieser Nachricht. Bitte erneut anhängen.', $attachment));
            }
            $staged = $this->pendingActionStore->peek($userId, 'file_store', $key);
            // The question was about one attachment; another name does not answer it.
            if (\is_array($staged) && mb_strtolower((string) ($staged['source'] ?? '')) !== mb_strtolower($attachment)) {
                throw new ToolRefusedException(\sprintf('Die Rückfrage zu „%s“ galt dem Anhang „%s“. Bitte „%s“ erneut anhängen.', $staged['target'] ?? $target, $staged['source'] ?? '?', $attachment));
            }
            $staged = $this->pendingActionStore->consume($userId, 'file_store', $key);
            $temp = \is_array($staged) ? $this->stagedTemp($staged) : null;
            if (null === $temp) {
                throw new ToolRefusedException('Die Rückfrage zum Ablegen ist abgelaufen. Bitte die Datei erneut anhängen.');
            }
            $stagedTarget = $this->mountedPath((string) ($staged['target'] ?? $target));

            return $this->write('rename' === $onConflict ? $this->freeName($stagedTarget) : $stagedTarget, $temp, $user);
        }

        if ($this->pendingActionStore->stagedInCurrentTurn($userId, 'file_store', $key)) {
            $pending = $this->pendingActionStore->peek($userId, 'file_store', $key);
            if (mb_strtolower((string) ($pending['source'] ?? '')) !== mb_strtolower($file->name)) {
                throw new ToolRefusedException(\sprintf('Für „%s“ wartet schon eine Frage zum Anhang „%s“. Erst deren Antwort abwarten.', $target, $pending['source'] ?? '?'));
            }

            return self::storeQuestion('awaiting_user', $target, 'Die Frage ist schon gestellt. Rufe das Werkzeug JETZT NICHT ERNEUT auf, sondern warte auf die Antwort des Users.');
        }

        // Attached again with the answer: the question of an earlier message is answered
        // by this call, and the file attached now is the one to store. It answers only a
        // question about this very file name: on a case-sensitive file system foto.jpg
        // and Foto.jpg share the key but are two files.
        $staged = $this->discardStaged($userId, $key);
        $answered = null !== $staged && ($staged['target'] ?? null) === $target;

        $temp = $this->writeTemp($file->bytes, $file->extension);
        if (!$this->exists($target)) {
            return $this->write($target, $temp, $user);
        }
        if ('rename' === $onConflict) {
            return $this->write($this->freeName($target), $temp, $user);
        }
        if ('replace' === $onConflict && $answered) {
            return $this->write($target, $temp, $user);
        }

        // One question for the user: replace, or a free name. The temp file waits for
        // the answer; its name stays on the server.
        $this->pendingActionStore->stage($userId, 'file_store', $key, ['temp' => basename($temp), 'sha1' => sha1_file($temp), 'target' => $target, 'source' => $file->name]);

        return self::storeQuestion('pending_confirmation', $target, 'Frage den User wörtlich und warte auf seine Antwort. In einer SPÄTEREN Nachricht file_store mit denselben Angaben und onConflict "replace" oder "rename" aufrufen; der Anhang muss dafür nicht erneut angehängt werden.', $this->freeName($target));
    }

    private static function storeQuestion(string $status, string $target, string $note, ?string $free = null): string
    {
        return '<tool_output_data tool="file_store">' . "\n" . json_encode([
            'status'   => $status,
            'tool'     => 'file_store',
            'question' => null === $free
                ? \sprintf('„%s“ gibt es schon. Ersetzen oder unter einem freien Namen ablegen?', $target)
                : \sprintf('„%s“ gibt es schon. Ersetzen (die Datei behält ihre Verwendungen) oder als „%s“ ablegen?', $target, basename($free)),
            'note'     => $note,
        ], self::JSON_FLAGS) . "\n</tool_output_data>";
    }

    /**
     * Ends a question staged in an earlier message for this target, and removes its file.
     *
     * @return array<string, mixed>|null the staged payload, if there was one
     */
    private function discardStaged(int $userId, string $key): ?array
    {
        $staged = $this->pendingActionStore->consume($userId, 'file_store', $key);
        if (!\is_array($staged)) {
            return null;
        }
        $name = (string) ($staged['temp'] ?? '');
        if (preg_match('/^' . self::TEMP_PREFIX . '[0-9a-f]{24}\.[a-z0-9]+$/', $name)) {
            @unlink($this->uploadDir() . '/' . $name);
        }

        return $staged;
    }

    private function write(string $target, string $temp, BackendUser $user): string
    {
        try {
            [$code, $output] = $this->console->run(self::given([
                'command'    => 'contao:file:write',
                '--path'     => $target,
                '--source'   => $temp,
                '--operator' => (string) ($user->username ?? ''),
            ]));
        } finally {
            @unlink($temp);
        }

        return $this->answer($code, $output, 'file_store');
    }

    private function uploadDir(): string
    {
        return $this->projectDir . '/var/bridge-uploads';
    }

    private function writeTemp(string $bytes, string $extension): string
    {
        $dir = $this->uploadDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ToolRefusedException('Die Datei konnte nicht zwischengespeichert werden.');
        }
        $temp = $dir . '/' . self::TEMP_PREFIX . bin2hex(random_bytes(12)) . '.' . $extension;
        if (false === file_put_contents($temp, $bytes)) {
            throw new ToolRefusedException('Die Datei konnte nicht zwischengespeichert werden.');
        }

        return $temp;
    }

    /**
     * @param array<string, mixed> $staged
     */
    private function stagedTemp(array $staged): ?string
    {
        $name = (string) ($staged['temp'] ?? '');
        if (!preg_match('/^' . self::TEMP_PREFIX . '[0-9a-f]{24}\.[a-z0-9]+$/', $name)) {
            return null;
        }
        $temp = $this->uploadDir() . '/' . $name;

        return is_file($temp) && sha1_file($temp) === ($staged['sha1'] ?? null) ? $temp : null;
    }

    private function removeStaleTempFiles(): void
    {
        foreach (glob($this->uploadDir() . '/' . self::TEMP_PREFIX . '*') ?: [] as $temp) {
            if (is_file($temp) && filemtime($temp) < time() - self::TEMP_MAX_AGE) {
                @unlink($temp);
            }
        }
    }

    private function exists(string $path): bool
    {
        return file_exists($this->projectDir . '/' . $path) || is_link($this->projectDir . '/' . $path);
    }

    /** foto.jpg → foto-1.jpg, foto-2.jpg, … the first that does not exist. */
    private function freeName(string $path): string
    {
        $info = pathinfo($path);
        $base = $info['dirname'] . '/' . $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        for ($i = 1; $i < 1000; ++$i) {
            if (!$this->exists("$base-$i$ext")) {
                return "$base-$i$ext";
            }
        }

        throw new ToolRefusedException('Kein freier Name gefunden.');
    }

    /**
     * A file name without path parts; with a given extension, it must keep it.
     */
    public static function cleanName(string $name, ?string $extension): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '', $name));
        if ('' === $name || '.' === $name[0]) {
            throw new ToolRefusedException('Ungültiger Dateiname.');
        }
        if (null !== $extension && strtolower(pathinfo($name, \PATHINFO_EXTENSION)) !== strtolower($extension)) {
            throw new ToolRefusedException(\sprintf('Der Name muss die Endung .%s behalten.', $extension));
        }

        return $name;
    }

    /**
     * The canonical path below files/, inside the user's file mounts.
     */
    private function mountedPath(string $path, bool $rootAllowed = false): string
    {
        $raw = trim(str_replace('\\', '/', $path), '/');
        if ($rootAllowed && 'files' === $raw) {
            $canonical = 'files';
        } else {
            $canonical = FileDeleteCommand::canonicalPath($raw);
            if (null === $canonical) {
                throw new ToolRefusedException(\sprintf('„%s“ liegt nicht unter files/.', $path));
            }
        }
        $outside = \sprintf('„%s“ liegt außerhalb deiner freigegebenen Ordner.', $canonical);
        $this->assertGranted(ContaoCorePermissions::USER_CAN_ACCESS_PATH, $canonical, $outside);

        // The voter compares regardless of case; DC_Folder::isMounted() does not, and on a
        // case-sensitive file system files/TEAM is not files/team.
        $user = $this->requireBackendUser();
        if (!$user->isAdmin) {
            $mounted = false;
            foreach ((array) $user->filemounts as $mount) {
                if (\is_string($mount) && '' !== $mount && Path::isBasePath($mount, $canonical)) {
                    $mounted = true;
                    break;
                }
            }
            if (!$mounted) {
                throw new ToolAccessDeniedException($outside);
            }
        }

        return $canonical;
    }

    private function assertGranted(string $attribute, ?string $subject, string $message): void
    {
        if (!$this->authorizationChecker->isGranted($attribute, $subject)) {
            throw new ToolAccessDeniedException($message);
        }
    }

    private function assertAction(CreateAction|UpdateAction|DeleteAction $action, string $path): void
    {
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::DC_PREFIX . 'tl_files', $action)) {
            throw new ToolAccessDeniedException(\sprintf('Keine Berechtigung für „%s“.', $path));
        }
    }

    /**
     * @return list<array<mixed>> each {table, id, field, path} as the core answers
     */
    private function findUsages(string $path, bool $pathsOnly): array
    {
        [$code, $output] = $this->console->run(self::given([
            'command'      => 'contao:file:usages',
            '--path'       => $path,
            '--paths-only' => $pathsOnly ?: null,
        ]));
        $decoded = json_decode($output, true);
        if (0 !== $code || !\is_array($decoded) || 'ok' !== ($decoded['status'] ?? null)) {
            throw new ToolRefusedException(\sprintf('Die Verwendungen von „%s“ ließen sich nicht prüfen: %s', $path, \is_array($decoded) ? (string) ($decoded['message'] ?? '') : 'keine Antwort'));
        }

        return array_values(array_filter((array) ($decoded['usages'] ?? []), 'is_array'));
    }

    private function askWhenTextNamesPath(string $tool, string $key, string $path, string $question): ?string
    {
        $usages = $this->findUsages($path, true);
        if ([] === $usages) {
            return null;
        }

        return $this->requireConfirmation(
            $tool,
            $key,
            $question . ' Der Pfad steht in Textfeldern und funktioniert dort danach nicht mehr: ' . self::describe($usages) . '.',
            ['path' => $path, 'usages' => \count($usages)],
        );
    }

    /**
     * @param list<array<mixed>> $usages
     */
    private static function describe(array $usages): string
    {
        $parts = array_map(
            static fn (array $u): string => \sprintf('%s %s (%s)', self::text($u['table'] ?? '?'), self::text($u['id'] ?? '?'), self::text($u['field'] ?? '?')),
            \array_slice($usages, 0, 10),
        );
        $more = \count($usages) - \count($parts);

        return implode(', ', $parts) . ($more > 0 ? " und $more weitere" : '');
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '?';
    }

    /**
     * The console input without the options that are not given.
     *
     * @param array<string, string|list<string>|true|null> $input
     *
     * @return array<string, string|list<string>|true>
     */
    private static function given(array $input): array
    {
        return array_filter($input, static fn ($value): bool => null !== $value && [] !== $value);
    }
}

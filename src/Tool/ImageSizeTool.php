<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\AllowListGrant;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * Image sizes in the chat (v0.19.0) — the theme manager's sizes, which core-bundle and
 * the CLI could write since core v0.2.18 while the chat had no tool for them.
 *
 * Rights: Contao's voter (theme manager module and the image size right) plus the
 * allow-list `imageSizes`. The back end applies that list to what it shows, not to a
 * direct URL (DC_Table::edit asks the voter only); the chat applies it to every call —
 * what an editor sees in the back end is what they reach here (DataContainerAccess). A new size joins the editor's allow-lists
 * as Contao's UserRootListener would add it (AllowListGrant). Variants: ImageSizeItemTool.
 * Listing goes through record_list (`tl_image_size`).
 */
#[AsTool('image_size_create', 'Create an image size in a theme (tl_image_size)', method: 'create')]
#[AsTool('image_size_update', 'Update fields of an image size by ID', method: 'update')]
#[AsTool('image_size_delete', 'Delete an image size with its variants by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('image_size_read', 'Read an image size by ID with all fields', method: 'read')]
class ImageSizeTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly Connection $connection,
        private readonly ImageSizeCreateCommand $createCommand,
        private readonly ImageSizeUpdateCommand $updateCommand,
        private readonly ImageSizeDeleteCommand $deleteCommand,
        private readonly ImageSizeReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'image_size_create';
    }

    /** The fields of the size's palette in the back end, `name` included. */
    protected function allowedFields(): array
    {
        return [
            'name', 'width', 'height', 'resizeMode', 'zoom', 'densities', 'sizes', 'lazyLoading',
            'preserveMetadata', 'formats', 'skipIfDimensionsMatch', 'imageQuality', 'cssClass',
        ];
    }

    /**
     * @param int                        $themeId Theme ID (tl_theme) the size belongs to
     * @param string                     $name    Name shown in the back end, e.g. "Teaser"
     * @param array<string,scalar|null>|string $fields  Further fields, e.g. {"width": "800", "height": "450", "resizeMode": "crop"}. Allowed: width, height, resizeMode (crop, box, proportional), zoom, densities (e.g. "1x, 2x"), sizes, lazyLoading, preserveMetadata, formats, skipIfDimensionsMatch, imageQuality, cssClass.
     */
    #[AiContract(
        writes: true, tables: ['tl_image_size'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(int $themeId, string $name, array|string $fields = []): string
    {
        $fields = self::payload($fields, 'fields');
        if (null === $this->dcAccess->fetchRecord('tl_theme', $themeId)) {
            throw new ToolRefusedException(\sprintf('Theme %d nicht gefunden.', $themeId));
        }
        $this->dcAccess->assertCreate('tl_image_size', ['pid' => $themeId, 'name' => $name]);
        $this->assertFieldsCreatable('tl_image_size', ['name' => $name] + (self::writtenValues($fields) ?? []));

        return $this->runCommand($this->createCommand, [
            '--pid'  => (string) $themeId,
            '--name' => $name,
            '--set'  => [] === $fields ? null : $this->buildSetOptions($fields, 'tl_image_size'),
        ], 'image_size_create');
    }

    /**
     * @param int                        $id     Image size ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"width": "1200"}. Allowed: name, width, height, resizeMode, zoom, densities, sizes, lazyLoading, preserveMetadata, formats, skipIfDimensionsMatch, imageQuality, cssClass.
     */
    #[AiContract(
        writes: true, tables: ['tl_image_size'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $this->dcAccess->assertUpdate('tl_image_size', $id, self::writtenValues($fields));

        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields, 'tl_image_size'),
        ], 'image_size_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_image_size', 'tl_image_size_item'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $size = $this->dcAccess->assertDelete('tl_image_size', $id);
        $name = (string) ($size['name'] ?? 'unbekannt');
        $variants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_image_size_item WHERE pid = ?', [$id]);

        $staged = $this->requireConfirmation(
            'image_size_delete',
            (string) $id,
            \sprintf('Die Bildgröße "%s" (ID %d) soll samt %d Variante(n) gelöscht werden. Bilder, die sie verwenden, fallen auf die Originalgröße zurück. Wirklich fortfahren?', $name, $id, $variants),
            ['id' => $id, 'name' => $name, 'variants' => $variants],
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'image_size_delete');
    }

    #[AiContract(writes: false, tables: ['tl_image_size'], trace: [])]
    public function read(int $id): string
    {
        $this->dcAccess->assertRead('tl_image_size', $id);

        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'image_size_read');
    }

    /** A new size joins the editor's allow-lists, as after a create in the back end. */
    protected function postProcessDecoded(array &$decoded, string $toolName): void
    {
        if ('image_size_create' !== $toolName || 'ok' !== ($decoded['status'] ?? null)) {
            return;
        }
        $user = $this->getCurrentBackendUser();
        if (null === $user) {
            return;
        }
        $granted = AllowListGrant::grant($this->connection, $user, 'imageSizes', (int) ($decoded['id'] ?? 0));
        if (null !== $granted) {
            $decoded['auto_granted'] = $granted;
        }
    }
}

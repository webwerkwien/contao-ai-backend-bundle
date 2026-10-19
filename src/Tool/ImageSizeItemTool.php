<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tool;

use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemUpdateCommand;

/**
 * The media-query variants of an image size (tl_image_size_item), v0.19.0.
 *
 * Contao's voter for variants checks module and image size right only, not the size they
 * belong to. A variant is part of its size, so every call here also asks for the right to
 * edit that size — which includes the allow-list `imageSizes` (DataContainerAccess).
 */
#[AsTool('image_size_item_create', 'Create a media-query variant of an image size', method: 'create')]
#[AsTool('image_size_item_update', 'Update fields of an image size variant by ID', method: 'update')]
#[AsTool('image_size_item_delete', 'Delete an image size variant by ID. Requires explicit confirmation.', method: 'delete')]
#[AsTool('image_size_item_read', 'Read an image size variant by ID with all fields', method: 'read')]
class ImageSizeItemTool extends AbstractCoreCommandTool
{
    public function __construct(
        ToolAccessChecker $accessChecker,
        TokenChecker $tokenChecker,
        private readonly ImageSizeItemCreateCommand $createCommand,
        private readonly ImageSizeItemUpdateCommand $updateCommand,
        private readonly ImageSizeItemDeleteCommand $deleteCommand,
        private readonly ImageSizeItemReadCommand $readCommand,
    ) {
        parent::__construct($accessChecker, $tokenChecker);
    }

    public function getToolName(): string
    {
        return 'image_size_item_create';
    }

    /** The fields of the variant's palette in the back end. */
    protected function allowedFields(): array
    {
        return ['media', 'width', 'height', 'resizeMode', 'zoom', 'densities', 'sizes', 'invisible'];
    }

    /**
     * @param int                        $imageSizeId Image size ID (tl_image_size) the variant belongs to
     * @param string                     $media       CSS media condition, e.g. "(max-width: 767px)"
     * @param array<string,scalar|null>|string $fields      Further fields, e.g. {"width": "400", "height": "300"}. Allowed: width, height, resizeMode, zoom, densities, sizes, invisible.
     */
    #[AiContract(
        writes: true, tables: ['tl_image_size_item'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id'],
    )]
    public function create(int $imageSizeId, string $media, array|string $fields = []): string
    {
        $fields = self::payload($fields, 'fields');
        $this->assertSizeEditable($imageSizeId);
        $this->dcAccess->assertCreate('tl_image_size_item', ['pid' => $imageSizeId, 'media' => $media]);
        $this->assertFieldsCreatable('tl_image_size_item', ['media' => $media] + (self::writtenValues($fields) ?? []));

        return $this->runCommand($this->createCommand, [
            '--pid'   => (string) $imageSizeId,
            '--media' => $media,
            '--set'   => [] === $fields ? null : $this->buildSetOptions($fields, 'tl_image_size_item'),
        ], 'image_size_item_create');
    }

    /**
     * @param int                        $id     Variant ID
     * @param array<string,scalar|null>|string $fields Field names and their new values, as a list of name and value (["published", "1"]) or as an object, e.g. {"media": "(max-width: 599px)"}. Allowed: media, width, height, resizeMode, zoom, densities, sizes, invisible.
     */
    #[AiContract(
        writes: true, tables: ['tl_image_size_item'], trace: ['tl_version', 'tl_log'], traceWhen: 'on-success',
        repeatable: true, answerShape: ['status', 'id'],
    )]
    public function update(int $id, array|string $fields): string
    {
        $fields = self::payload($fields, 'fields');
        $item = $this->dcAccess->assertUpdate('tl_image_size_item', $id, self::writtenValues($fields));
        $this->assertSizeEditable((int) $item['pid']);

        return $this->runCommand($this->updateCommand, [
            'id'    => (string) $id,
            '--set' => $this->buildSetOptions($fields, 'tl_image_size_item'),
        ], 'image_size_item_update');
    }

    #[AiContract(
        writes: true, tables: ['tl_image_size_item'], trace: ['tl_undo', 'tl_log'], traceWhen: 'on-success',
        repeatable: false, answerShape: ['status', 'id', 'deleted'],
    )]
    public function delete(int $id): string
    {
        $item = $this->dcAccess->assertDelete('tl_image_size_item', $id);
        $this->assertSizeEditable((int) $item['pid']);
        $media = (string) ($item['media'] ?? '');

        $staged = $this->requireConfirmation(
            'image_size_item_delete',
            (string) $id,
            \sprintf('Die Variante "%s" (ID %d) der Bildgröße %d soll gelöscht werden. Wirklich fortfahren?', $media, $id, (int) $item['pid']),
            ['id' => $id, 'media' => $media, 'image_size' => (int) $item['pid']],
        );
        if (null !== $staged) {
            return $staged;
        }

        return $this->runCommand($this->deleteCommand, ['id' => (string) $id], 'image_size_item_delete');
    }

    #[AiContract(writes: false, tables: ['tl_image_size_item'], trace: [])]
    public function read(int $id): string
    {
        $item = $this->dcAccess->assertRead('tl_image_size_item', $id);
        $this->dcAccess->assertRead('tl_image_size', (int) $item['pid']);

        return $this->runCommand($this->readCommand, ['id' => (string) $id], 'image_size_item_read');
    }

    /** A variant is part of its size: changing one is editing the size. */
    private function assertSizeEditable(int $imageSizeId): void
    {
        if (null === $this->dcAccess->fetchRecord('tl_image_size', $imageSizeId)) {
            throw new ToolRefusedException(\sprintf('Bildgröße %d nicht gefunden.', $imageSizeId));
        }
        $this->dcAccess->assertUpdate('tl_image_size', $imageSizeId);
    }
}

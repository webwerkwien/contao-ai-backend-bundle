<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentTool;

/**
 * An image from the file manager can be put into an element (v1.0.0): addImage
 * was writable before, the image itself was not.
 */
class ContentImageFieldsTest extends TestCase
{
    public function testTheImageFieldsAreWritable(): void
    {
        $method = new \ReflectionMethod(ContentTool::class, 'allowedFields');
        $tool = (new \ReflectionClass(ContentTool::class))->newInstanceWithoutConstructor();

        foreach (['addImage', 'singleSRC', 'size'] as $field) {
            self::assertContains($field, $method->invoke($tool));
        }
    }

    /**
     * The core turns a UUID into the binary column and a JSON list into the
     * serialized size; a bare size id it refuses. The model has to know both.
     */
    public function testTheModelIsToldHow(): void
    {
        $doc = (string) (new \ReflectionMethod(ContentTool::class, 'update'))->getDocComment();

        self::assertStringContainsString('singleSRC', $doc);
        self::assertStringContainsString('UUID', $doc);
        self::assertStringContainsString('["","","5"]', $doc);
    }
}

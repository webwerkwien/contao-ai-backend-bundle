<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;

/**
 * A parameter's description in the docblock is what the model reads about it — but only
 * if symfony/ai finds it. Its MethodDescriber matches `@param \S+ $name`, so a type with a
 * space (`array<string, scalar|null>`) hides the whole description. Thirteen parameters
 * had one that never reached the model, among them `fields` of every *_update tool with its
 * list of allowed field names (v0.19.2): the chat guessed
 * a headline's level as `unit`, then as `hl`.
 *
 * Every tool method, every documented parameter: the schema must carry its description.
 */
class ParameterDescriptionsReachTheModelTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function toolMethods(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Tool/*.php') ?: [] as $file) {
            $class = 'Webwerkwien\\ContaoAiBackendBundle\\Tool\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getAttributes(AsTool::class) as $attribute) {
                $tool = $attribute->newInstance();
                yield $tool->name => [$class, $tool->method];
            }
        }
    }

    /**
     * @dataProvider toolMethods
     *
     * @param class-string $class
     */
    public function testEveryDocumentedParameterKeepsItsDescription(string $class, string $method): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $comment = (string) $reflection->getDocComment();
        $schema = (new Factory())->buildParameters($class, $method);

        // A tool without documented parameters has nothing to lose; it counts as checked.
        $this->addToAssertionCount(1);

        foreach ($reflection->getParameters() as $parameter) {
            // Whatever follows the variable name on its @param line, type spaces or not.
            if (!preg_match('/@param\s.*?\$' . preg_quote($parameter->getName(), '/') . '[ \t]+([^\s*][^\n]*)/', $comment, $match)) {
                continue;
            }

            $this->assertNotEmpty(
                $schema['properties'][$parameter->getName()]['description'] ?? null,
                \sprintf('%s::%s(): the description of $%s does not reach the model. A space in its @param type hides it from symfony/ai.', $class, $method, $parameter->getName()),
            );
        }
    }

    public function testTheToolsAreFound(): void
    {
        $this->assertGreaterThan(30, iterator_count(self::toolMethods()));
    }
}

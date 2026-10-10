<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs a console command by name inside the request, through the kernel's console
 * application (v0.13.0).
 *
 * The tools usually hold the core command they wrap and run it directly. That is not
 * possible for commands only known at run time — an extension's `#[AiContract]`
 * command, a command that exists only where a package is installed — and the core's
 * `contao:ai:run` and `contao:ai:commands` ask their application for other commands,
 * which a command run outside an application does not have. The application is built
 * on first use and kept for the request.
 */
class ConsoleRunner
{
    private ?Application $application = null;

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function has(string $name): bool
    {
        return $this->application()->has($name);
    }

    /**
     * @param array<string, mixed> $input the command name under `command`, then its arguments and options
     *
     * @return array{0: int, 1: string} exit code and output
     */
    public function run(array $input): array
    {
        $in = new ArrayInput($input);
        $in->setInteractive(false);
        $out = new BufferedOutput();

        $code = $this->application()->run($in, $out);

        return [$code, $out->fetch()];
    }

    private function application(): Application
    {
        if (null === $this->application) {
            $this->application = new Application($this->kernel);
            $this->application->setAutoExit(false);
            // Exceptions reach the tool, which tells a refusal from a defect.
            $this->application->setCatchExceptions(false);
        }

        return $this->application;
    }
}

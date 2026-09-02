<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;

/**
 * The confirmation gate, and the attack it did not stop until 2026-09-02.
 *
 * 🔴 C-1. The gate's whole enforcement used to be a sentence addressed to the
 * model: *"Frage den User wörtlich. Bei klarem Ja denselben Tool-Aufruf erneut
 * absetzen — dann führt der Server aus."* Since symfony/ai 0.13 the agent drives
 * the tool loop itself with `maxToolCalls: 50`, so the model could call
 * `page_delete`, read *pending_confirmation*, and call again in the same
 * request. The deletion ran; the user never saw the question.
 *
 * 🎯 A guarantee whose enforcement rests with the party it guards against is not
 * a guarantee — and the model is exactly the party that untrusted record content
 * can steer.
 *
 * One HTTP request is one chat turn. Binding the staged entry to the request id
 * enforces what the sentence only requested: **a human must have sent something
 * between the question and the execution.**
 */
class PendingActionStoreTest extends TestCase
{
    private const USER = 42;
    private const TOOL = 'page_delete';
    private const KEY  = '7';

    private function stackWithFreshRequest(?RequestStack $stack = null): RequestStack
    {
        $stack ??= new RequestStack();

        $request = new Request();
        $request->setSession($this->session ??= new Session(new MockArraySessionStorage()));
        $stack->push($request);

        return $stack;
    }

    private ?Session $session = null;

    public function testAStagedActionCannotBeConsumedInTheSameRequest(): void
    {
        // The attack, verbatim: stage and consume without anything in between.
        $stack = $this->stackWithFreshRequest();
        $store = new PendingActionStore($stack);

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7]);

        self::assertNull(
            $store->consume(self::USER, self::TOOL, self::KEY),
            'the destructive action executed without a user turn in between',
        );
    }

    public function testTheEntrySurvivesTheRefusedAttempt(): void
    {
        // Refusing must not throw the confirmation away — otherwise a model that
        // calls twice would force the user to start over, and the pressure to
        // "just execute" would come back through the front door.
        $stack = $this->stackWithFreshRequest();
        $store = new PendingActionStore($stack);

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7]);
        $store->consume(self::USER, self::TOOL, self::KEY);

        self::assertTrue($store->stagedInCurrentTurn(self::USER, self::TOOL, self::KEY));
        self::assertNotNull($store->peek(self::USER, self::TOOL, self::KEY));
    }

    public function testTheNextTurnMayConsumeIt(): void
    {
        // The legitimate flow must still work: ask in turn 1, confirm in turn 2.
        $stack = $this->stackWithFreshRequest();
        $store = new PendingActionStore($stack);

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7, 'title' => 'Kontakt']);

        // A new HTTP request is a new chat turn.
        $this->stackWithFreshRequest($stack);

        $payload = $store->consume(self::USER, self::TOOL, self::KEY);

        self::assertNotNull($payload, 'the confirmed action no longer executes');
        self::assertSame('Kontakt', $payload['title']);
    }

    public function testConsumingIsStillSingleUse(): void
    {
        $stack = $this->stackWithFreshRequest();
        $store = new PendingActionStore($stack);

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7]);
        $this->stackWithFreshRequest($stack);

        self::assertNotNull($store->consume(self::USER, self::TOOL, self::KEY));
        self::assertNull($store->consume(self::USER, self::TOOL, self::KEY), 'a replay executed a second time');
    }

    public function testAnotherUsersConfirmationIsNotVisible(): void
    {
        $stack = $this->stackWithFreshRequest();
        $store = new PendingActionStore($stack);

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7]);
        $this->stackWithFreshRequest($stack);

        self::assertNull($store->consume(99, self::TOOL, self::KEY));
        self::assertNull($store->consume(self::USER, 'news_delete', self::KEY));
        self::assertNull($store->consume(self::USER, self::TOOL, '8'));
    }

    public function testWithoutARequestNothingIsStagedAndTheCallerFallsThrough(): void
    {
        // CLI and tests: no session, so peek() returns null and the tool executes
        // immediately. That is deliberate — the CLI operator has shell access
        // anyway and is not the party this gate guards against.
        $store = new PendingActionStore(new RequestStack());

        $store->stage(self::USER, self::TOOL, self::KEY, ['id' => 7]);

        self::assertNull($store->peek(self::USER, self::TOOL, self::KEY));
        self::assertFalse($store->stagedInCurrentTurn(self::USER, self::TOOL, self::KEY));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\DefaultDataContainerVoter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;

/**
 * Rule 25: the voter list is what the container hands over, not what a test writes down.
 * DataContainerAccess gets the voters through `#[AutowireIterator('security.voter')]`; the
 * design rests on Contao's DefaultDataContainerVoter (priority -1024) coming last. Built
 * here by Symfony's own container from the attribute, with the default voter registered
 * first and a denying voter after it.
 */
class DataContainerAccessWiringTest extends TestCase
{
    public function testTheContainerHandsOverTheVotersByPriority(): void
    {
        $container = new ContainerBuilder();
        $container->register(DefaultDataContainerVoter::class)->addTag('security.voter', ['priority' => -1024]);
        $container->register(DenyingTestVoter::class)->addTag('security.voter', ['priority' => 100]);
        $container->register(ContaoFramework::class)->setSynthetic(true);
        $container->register(TokenStorageInterface::class)->setSynthetic(true);
        $container->register(Connection::class)->setSynthetic(true);
        $container->register(DataContainerAccess::class)->setAutowired(true)->setPublic(true);
        $container->compile();

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(['loadDataContainer'])->getMock());
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new NullToken());
        $container->set(ContaoFramework::class, $framework);
        $container->set(TokenStorageInterface::class, $tokenStorage);
        $container->set(Connection::class, $this->createMock(Connection::class));

        $access = $container->get(DataContainerAccess::class);
        self::assertInstanceOf(DataContainerAccess::class, $access);

        // Registered first, the default voter would grant under any order but priority.
        self::assertFalse($access->isGranted(new CreateAction('tl_page', ['pid' => 0, 'sorting' => 0, 'type' => 'regular'])));
        // And it is there at all: where the denying voter abstains, it grants. An empty
        // list (a wrong tag) would refuse everything.
        self::assertTrue($access->isGranted(new CreateAction('tl_page', ['pid' => 2, 'sorting' => 0, 'type' => 'regular'])));
    }
}

/**
 * A data container voter as Contao writes them: denies or abstains, never grants. This
 * one denies a page on top level.
 */
final class DenyingTestVoter implements VoterInterface
{
    /**
     * The fourth parameter is part of the interface since Symfony 8 (commented out
     * in 7.x, where an extra optional parameter is allowed).
     *
     * @param array<mixed> $attributes
     */
    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        return $subject instanceof CreateAction && '0' === $subject->getNewPid() ? VoterInterface::ACCESS_DENIED : VoterInterface::ACCESS_ABSTAIN;
    }
}

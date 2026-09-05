<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
class AiAccessVoter extends Voter
{
    public const ATTR_USE_CHAT = 'AI_CHAT_USE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTR_USE_CHAT === $attribute;
    }

    /**
     * Symfony 8 added a fourth parameter to the parent, `?Vote $vote = null`.
     * A three-parameter override is a fatal error there.
     *
     * 🎯 Typed `?object` rather than `?Vote` on purpose: the `Vote` class does
     * not exist before Symfony 8, so naming it would make this file unloadable
     * on 6.4/7.x. PHP allows a wider type on a parameter, and `object` is wider
     * than `Vote` — one signature that holds on both.
     *
     * The parameter is never read; it exists to satisfy the contract.
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?object $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof BackendUser) {
            return false;
        }

        if ($user->isAdmin) {
            return true;
        }

        $modules = (array) ($user->modules ?? []);

        return \in_array('ai_chat', $modules, true);
    }
}

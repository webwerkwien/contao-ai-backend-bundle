<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AiAccessVoter extends Voter
{
    public const ATTR_USE_CHAT = 'AI_CHAT_USE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTR_USE_CHAT === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
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

<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\Voter\SwitchUserVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Regra da impersonação: alvo ativo; administrador comum só entra como usuário comum;
 * super administrador entra como qualquer outro.
 */
class SwitchUserVoterTest extends TestCase
{
    #[DataProvider('cases')]
    public function testVote(array $actorRoles, array $targetRoles, string $targetStatus, int $expected): void
    {
        $voter = new SwitchUserVoter(new RoleHierarchy([
            'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
            'ROLE_ADMIN'       => ['ROLE_USER'],
        ]));

        $actor  = self::user('ator@exemplo.org', $actorRoles, User::STATUS_ACTIVE);
        $target = self::user('alvo@exemplo.org', $targetRoles, $targetStatus);
        $token  = new UsernamePasswordToken($actor, 'main', $actor->getRoles());

        $this->assertSame($expected, $voter->vote($token, $target, [SwitchUserVoter::ATTRIBUTE]));
    }

    public static function cases(): array
    {
        $ok = VoterInterface::ACCESS_GRANTED;
        $no = VoterInterface::ACCESS_DENIED;

        return [
            'admin → usuário comum ativo'        => [['ROLE_ADMIN'], [], User::STATUS_ACTIVE, $ok],
            'admin → outro admin'                => [['ROLE_ADMIN'], ['ROLE_ADMIN'], User::STATUS_ACTIVE, $no],
            'admin → super admin'                => [['ROLE_ADMIN'], ['ROLE_SUPER_ADMIN'], User::STATUS_ACTIVE, $no],
            'admin → usuário pendente'           => [['ROLE_ADMIN'], [], User::STATUS_PENDING, $no],
            'admin → usuário inativo'            => [['ROLE_ADMIN'], [], User::STATUS_INACTIVE, $no],
            'super admin → admin'                => [['ROLE_SUPER_ADMIN'], ['ROLE_ADMIN'], User::STATUS_ACTIVE, $ok],
            'super admin → outro super admin'    => [['ROLE_SUPER_ADMIN'], ['ROLE_SUPER_ADMIN'], User::STATUS_ACTIVE, $ok],
            'super admin → usuário inativo'      => [['ROLE_SUPER_ADMIN'], [], User::STATUS_INACTIVE, $no],
            'usuário comum → usuário comum'      => [[], [], User::STATUS_ACTIVE, $no],
            'usuário comum → admin'              => [[], ['ROLE_ADMIN'], User::STATUS_ACTIVE, $no],
        ];
    }

    public function testNobodySwitchesToThemselves(): void
    {
        $voter = new SwitchUserVoter(new RoleHierarchy(['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN']]));
        $actor = self::user('mesmo@exemplo.org', ['ROLE_SUPER_ADMIN'], User::STATUS_ACTIVE);
        $token = new UsernamePasswordToken($actor, 'main', $actor->getRoles());

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $actor, [SwitchUserVoter::ATTRIBUTE]));
    }

    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = new SwitchUserVoter(new RoleHierarchy([]));
        $actor = self::user('ator@exemplo.org', ['ROLE_ADMIN'], User::STATUS_ACTIVE);
        $token = new UsernamePasswordToken($actor, 'main', $actor->getRoles());

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $actor, ['ROLE_ADMIN']));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [SwitchUserVoter::ATTRIBUTE]));
    }

    private static function user(string $email, array $roles, string $status): User
    {
        return (new User())->setEmail($email)->setName('Teste')->setRoles($roles)->setStatus($status)->setPassword('hash');
    }
}

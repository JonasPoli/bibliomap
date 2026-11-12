<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\EquatableInterface;

/**
 * UserChecker (status só depois da senha) e User::isEqualTo (sessão cai quando a conta deixa de estar ativa).
 */
class UserSessionTest extends TestCase
{
    public function testPreAuthNeverRevealsAccountStatus(): void
    {
        $checker = new UserChecker();
        foreach ([User::STATUS_PENDING, User::STATUS_INACTIVE, User::STATUS_ACTIVE] as $status) {
            $checker->checkPreAuth(self::user($status));
        }
        // Chegou aqui sem exceção: quem erra a senha vê só "credenciais inválidas"
        $this->addToAssertionCount(1);
    }

    public function testPostAuthBlocksPendingAndInactive(): void
    {
        $checker = new UserChecker();
        $checker->checkPostAuth(self::user(User::STATUS_ACTIVE));

        foreach ([User::STATUS_PENDING => 'aguardando aprovação', User::STATUS_INACTIVE => 'desativada'] as $status => $text) {
            try {
                $checker->checkPostAuth(self::user($status));
                $this->fail("Login de usuário {$status} deveria ser recusado.");
            } catch (CustomUserMessageAccountStatusException $e) {
                $this->assertStringContainsString($text, $e->getMessageKey());
            }
        }
    }

    public function testSessionSurvivesWhileNothingRelevantChanges(): void
    {
        $fresh = self::user(User::STATUS_ACTIVE);
        $this->assertInstanceOf(EquatableInterface::class, $fresh);

        // Cópia que volta da sessão: a senha vem como crc32c do hash (User::__serialize)
        $fromSession = unserialize(serialize($fresh));
        $this->assertSame(8, strlen((string) $fromSession->getPassword()));
        $this->assertTrue($fromSession->isEqualTo($fresh));

        // Primeiro acesso depois do login: o objeto ainda tem o hash inteiro
        $this->assertTrue($fresh->isEqualTo(self::user(User::STATUS_ACTIVE)));

        // Mudar nome ou instituição não derruba a sessão
        $renamed = self::user(User::STATUS_ACTIVE)->setName('Outro Nome')->setInstitution('UFSCar');
        $this->assertTrue($fromSession->isEqualTo($renamed));
    }

    public function testSessionEndsWhenAccountIsDeactivatedOrCredentialsChange(): void
    {
        $fromSession = unserialize(serialize(self::user(User::STATUS_ACTIVE)));

        $this->assertFalse($fromSession->isEqualTo(self::user(User::STATUS_INACTIVE)), 'conta desativada');
        $this->assertFalse($fromSession->isEqualTo(self::user(User::STATUS_PENDING)), 'conta voltou a pendente');
        $this->assertFalse($fromSession->isEqualTo(self::user(User::STATUS_ACTIVE)->setPassword('outro-hash')), 'senha trocada');
        $this->assertFalse($fromSession->isEqualTo(self::user(User::STATUS_ACTIVE)->setEmail('novo@exemplo.org')), 'e-mail trocado');
        $this->assertFalse($fromSession->isEqualTo(self::user(User::STATUS_ACTIVE)->setRoles(['ROLE_ADMIN'])), 'papel mudou');
    }

    private static function user(string $status): User
    {
        return (new User())
            ->setEmail('pessoa@exemplo.org')
            ->setName('Pessoa')
            ->setRoles([])
            ->setStatus($status)
            ->setPassword('$2y$13$hashdeexemploqualquerparaoteste');
    }
}

<?php

namespace App\Tests\Security;

use App\Command\CreateAdminCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * app:admin:create sem credenciais padrão: e-mail obrigatório, senha informada ou perguntada,
 * e usuário existente não tem a senha trocada sem --reset-password. Sem banco (repositório falso).
 */
class CreateAdminCommandTest extends TestCase
{
    private const STRONG = 'frase-longa-de-teste';

    public function testEmailIsRequiredAndHasNoDefault(): void
    {
        [$tester] = $this->tester(null);

        $this->expectException(RuntimeException::class);
        $tester->execute([]);
    }

    public function testDefinitionCarriesNoDefaultCredentials(): void
    {
        [$tester, , $command] = $this->tester(null);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->getArgument('email')->isRequired());
        $this->assertNull($definition->getArgument('password')->getDefault());
        // O texto de ajuda não pode trazer senha de exemplo pronta para uso
        $this->assertDoesNotMatchRegularExpression('/senha\s+\S*\d{4,}/iu', $command->getHelp());
    }

    public function testCreatesAdminWithGivenPasswordAndNeverPrintsIt(): void
    {
        [$tester, $persisted] = $this->tester(null);

        $status = $tester->execute(['email' => 'novo@exemplo.org', 'password' => self::STRONG]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertCount(1, $persisted);
        $user = $persisted[0];
        $this->assertSame('novo@exemplo.org', $user->getEmail());
        $this->assertSame('hash:' . self::STRONG, $user->getPassword());
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], array_values($user->getRoles()));
        $this->assertTrue($user->isActive());
        $this->assertStringNotContainsString(self::STRONG, $tester->getDisplay());
    }

    public function testSuperOptionGivesSuperAdminRole(): void
    {
        [$tester, $persisted] = $this->tester(null);
        $tester->execute(['email' => 'novo@exemplo.org', 'password' => self::STRONG, '--super' => true]);

        $this->assertContains('ROLE_SUPER_ADMIN', $persisted[0]->getRoles());
    }

    public function testShortPasswordIsRefused(): void
    {
        [$tester, $persisted] = $this->tester(null);

        $this->assertSame(Command::INVALID, $tester->execute(['email' => 'novo@exemplo.org', 'password' => 'curta123']));
        $this->assertCount(0, $persisted);
    }

    public function testNonInteractiveWithoutPasswordFailsInsteadOfUsingADefault(): void
    {
        [$tester, $persisted] = $this->tester(null);

        $status = $tester->execute(['email' => 'novo@exemplo.org'], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertCount(0, $persisted);
    }

    public function testExistingUserIsPromotedButKeepsPassword(): void
    {
        $existing = (new User())->setEmail('ja@exemplo.org')->setName('Já Existe')
            ->setPassword('hash-antigo')->setStatus(User::STATUS_PENDING);
        [$tester] = $this->tester($existing);

        $status = $tester->execute(['email' => 'ja@exemplo.org'], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame('hash-antigo', $existing->getPassword());
        $this->assertContains('ROLE_ADMIN', $existing->getRoles());
        $this->assertTrue($existing->isActive());
    }

    public function testExistingUserWithPasswordArgumentNeedsResetOption(): void
    {
        $existing = (new User())->setEmail('ja@exemplo.org')->setName('Já Existe')->setPassword('hash-antigo');
        [$tester] = $this->tester($existing);

        $this->assertSame(Command::INVALID, $tester->execute(['email' => 'ja@exemplo.org', 'password' => self::STRONG]));
        $this->assertSame('hash-antigo', $existing->getPassword());
        $this->assertNotContains('ROLE_ADMIN', $existing->getRoles());

        $this->assertSame(Command::SUCCESS, $tester->execute([
            'email' => 'ja@exemplo.org', 'password' => self::STRONG, '--reset-password' => true,
        ]));
        $this->assertSame('hash:' . self::STRONG, $existing->getPassword());
    }

    /** @return array{0: CommandTester, 1: \ArrayObject<int, User>, 2: CreateAdminCommand} */
    private function tester(?User $existing): array
    {
        $persisted = new \ArrayObject();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use ($persisted): void {
            $persisted->append($o);
        });

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn ($user, string $plain): string => 'hash:' . $plain);

        $command = new CreateAdminCommand($em, $repo, $hasher);

        return [new CommandTester($command), $persisted, $command];
    }
}

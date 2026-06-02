<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:create',
    description: 'Cria ou promove um usuário a administrador',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly UserRepository            $userRepo,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email',    InputArgument::OPTIONAL, 'E-mail do administrador', 'admin@wab.com.br')
            ->addArgument('password', InputArgument::OPTIONAL, 'Senha do administrador',  'wab12345678')
            ->setHelp(<<<'EOT'
Cria um usuário administrador ou promove um usuário existente.

Uso:
  <info>php bin/console app:admin:create</info>
      → Cria admin@wab.com.br com a senha wab12345678

  <info>php bin/console app:admin:create seuemail@example.com MinhaS3nh@</info>
      → Cria/promove o usuário especificado
EOT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $email    = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        $io->title('BiblioMap — Criar Administrador');

        /** @var User|null $user */
        $user = $this->userRepo->findOneBy(['email' => $email]);

        if ($user !== null) {
            $io->note("Usuário '{$email}' já existe. Promovendo a administrador...");
        } else {
            $user = new User();
            $user->setEmail($email);
            $user->setName('Administrador');
            $this->em->persist($user);
            $io->note("Criando novo usuário '{$email}'...");
        }

        // Hash password
        $hashed = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashed);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setStatus(User::STATUS_ACTIVE);

        $this->em->flush();

        $io->success([
            'Administrador criado/atualizado com sucesso!',
            "E-mail : {$email}",
            "Senha  : {$password}",
            'Acesso : /login',
        ]);

        return Command::SUCCESS;
    }
}

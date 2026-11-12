<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
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
        // Sem valores padrão: o repositório é público e um e-mail/senha fixos no código seriam credenciais conhecidas
        $this
            ->addArgument('email',    InputArgument::REQUIRED, 'E-mail do administrador')
            ->addArgument('password', InputArgument::OPTIONAL, 'Senha (se omitida, é pedida no terminal sem aparecer na tela)')
            ->addOption('super', null, InputOption::VALUE_NONE, 'Promover a Super Administrador')
            ->addOption('reset-password', null, InputOption::VALUE_NONE, 'Trocar a senha de um usuário que já existe')
            ->setHelp(<<<'EOT'
Cria um usuário administrador ou promove um usuário existente.

Uso:
  <info>php bin/console app:admin:create voce@exemplo.org</info>
      → Cria o administrador e pede a senha no terminal (não fica no histórico do shell)

  <info>php bin/console app:admin:create voce@exemplo.org --super</info>
      → Cria/promove para Super Administrador

  <info>php bin/console app:admin:create voce@exemplo.org --reset-password</info>
      → Usuário que já existe: promove e troca a senha

Usuário que já existe é só promovido e ativado; a senha dele não muda sem <info>--reset-password</info>.
A senha nova precisa ter ao menos 10 caracteres e nunca é exibida.
EOT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $email   = trim((string) $input->getArgument('email'));
        $isSuper = (bool) $input->getOption('super');
        $reset   = (bool) $input->getOption('reset-password');

        $io->title('BiblioMap — Criar Administrador');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('Informe um e-mail válido.');
            return Command::INVALID;
        }

        /** @var User|null $user */
        $user = $this->userRepo->findOneBy(['email' => $email]);
        $isNew = $user === null;

        $password = $input->getArgument('password');
        if (!$isNew && !$reset && $password !== null) {
            $io->error("O usuário '{$email}' já existe. Para trocar a senha dele, repita o comando com --reset-password.");
            return Command::INVALID;
        }

        $setPassword = $isNew || $reset;
        if ($setPassword) {
            if ($password === null) {
                if (!$input->isInteractive()) {
                    $io->error('Informe a senha como segundo argumento ou rode o comando num terminal interativo.');
                    return Command::INVALID;
                }
                $password = $io->askHidden('Senha do administrador (mínimo de ' . User::PASSWORD_MIN_LENGTH . ' caracteres)');
            }
            $password = (string) $password;
            if (mb_strlen($password) < User::PASSWORD_MIN_LENGTH) {
                $io->error('A senha deve ter ao menos ' . User::PASSWORD_MIN_LENGTH . ' caracteres.');
                return Command::INVALID;
            }
        }

        if ($isNew) {
            $user = new User();
            $user->setEmail($email);
            $user->setName($isSuper ? 'Super Administrador' : 'Administrador');
            $this->em->persist($user);
            $io->note("Criando novo usuário '{$email}'...");
        } else {
            $io->note("Usuário '{$email}' já existe. Promovendo" . ($reset ? ' e trocando a senha' : ' (a senha não muda)') . '...');
        }

        if ($setPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }
        $user->setRoles($isSuper ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']);
        $user->setStatus(User::STATUS_ACTIVE);

        $this->em->flush();

        $io->success([
            ($isSuper ? 'Super Administrador' : 'Administrador') . ($isNew ? ' criado' : ' atualizado') . ' com sucesso!',
            "E-mail : {$email}",
            'Senha  : ' . ($setPassword ? 'definida agora (não é exibida)' : 'mantida'),
            'Acesso : /login',
        ]);

        return Command::SUCCESS;
    }
}

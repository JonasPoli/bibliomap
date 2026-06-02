# 📚 BiblioMap — Plataforma de Análise Bibliométrica

Plataforma web para análise bibliométrica de produção científica. Importa bases do Web of Science e Scopus, gera redes de coautoria, palavras-chave e países, mapas temáticos e relatórios exportáveis.

---

## Índice

1. [Requisitos](#requisitos)
2. [Instalação do Ambiente](#instalação-do-ambiente)
   - [Windows — XAMPP](#windows--xampp)
   - [Linux — LAMP](#linux--lamp)
   - [macOS — Homebrew](#macos--homebrew)
3. [Instalação do Symfony CLI](#instalação-do-symfony-cli)
4. [Clonando e configurando o projeto](#clonando-e-configurando-o-projeto)
5. [Banco de dados](#banco-de-dados)
6. [Rodando o servidor de desenvolvimento](#rodando-o-servidor-de-desenvolvimento)
7. [Comandos úteis](#comandos-úteis)

---

## Requisitos

| Componente | Versão mínima | Versão usada neste projeto |
|---|---|---|
| PHP | **8.2** | **8.4** (definida em `.php-version`) |
| Composer | 2.x | — |
| MySQL | 8.0+ (ou SQLite para dev) | — |
| Git | qualquer versão recente | — |
| Symfony CLI | recomendado | — |

> **Nota:** O arquivo `.php-version` na raiz do projeto instrui o Symfony CLI e ferramentas como `phpenv` / `asdf` a usar exatamente **PHP 8.4**. O `composer.json` aceita `>=8.2`, mas recomenda-se usar 8.4 para garantir compatibilidade total.

Extensões PHP necessárias: `pdo`, `pdo_mysql` (ou `pdo_sqlite`), `ctype`, `iconv`, `mbstring`, `xml`, `zip`, `intl`.

---

## Instalação do Ambiente

### Windows — XAMPP

O XAMPP é o jeito mais rápido de ter PHP + MySQL + Apache no Windows.

#### 1. Baixar e instalar o XAMPP

Acesse a página oficial e baixe a versão com **PHP 8.4** (ou 8.2+ como mínimo):
👉 **https://www.apachefriends.org/download.html**

Execute o instalador e marque no mínimo:
- ✅ Apache
- ✅ MySQL
- ✅ PHP

Caminho padrão de instalação: `C:\xampp`

#### 2. Iniciar os serviços

Abra o **XAMPP Control Panel** e clique em **Start** para:
- Apache
- MySQL

#### 3. Verificar o PHP no terminal

Abra o **Prompt de Comando** (ou PowerShell) e adicione o PHP ao PATH:

```powershell
# Adicionar temporariamente (por sessão)
$env:PATH += ";C:\xampp\php"

# Para tornar permanente, adicione C:\xampp\php nas Variáveis de Ambiente do Sistema
```

Verifique:
```powershell
php -v
# Deve exibir PHP 8.4.x (ou 8.2.x como mínimo)
```

#### 4. Instalar o Composer

Baixe e execute o instalador oficial:
👉 **https://getcomposer.org/Composer-Setup.exe**

O instalador detecta o PHP automaticamente. Após instalar:

```powershell
composer --version
# Composer version 2.x.x
```

#### 5. Instalar o Git

👉 **https://git-scm.com/download/win**

Instale com as opções padrão. Após instalar, abra o **Git Bash** ou o PowerShell:

```powershell
git --version
```

---

### Linux — LAMP

Instruções para **Ubuntu/Debian**. Para outras distribuições, adapte o gerenciador de pacotes.

#### 1. Atualizar o sistema

```bash
sudo apt update && sudo apt upgrade -y
```

#### 2. Instalar Apache e MySQL

```bash
sudo apt install apache2 mysql-server -y
sudo systemctl start apache2
sudo systemctl start mysql
sudo systemctl enable apache2
sudo systemctl enable mysql
```

#### 3. Instalar PHP 8.2 e extensões

```bash
# Adicionar repositório Ondřej Surý (mantido oficialmente)
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Instalar PHP 8.4 com extensões necessárias (mínimo: 8.2)
sudo apt install php8.4 php8.4-cli php8.4-fpm php8.4-mysql php8.4-sqlite3 \
  php8.4-xml php8.4-mbstring php8.4-intl php8.4-zip php8.4-curl \
  php8.4-ctype php8.4-iconv -y
```

Verificar:
```bash
php -v
# PHP 8.4.x
```

#### 4. Instalar o Composer

Siga a documentação oficial:
👉 **https://getcomposer.org/download/**

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

#### 5. Instalar o Git

```bash
sudo apt install git -y
git --version
```

---

### macOS — Homebrew

A documentação oficial do Symfony recomenda o Homebrew para macOS.
👉 **https://symfony.com/doc/current/setup.html**

#### 1. Instalar o Homebrew

Se ainda não tiver o Homebrew:
👉 **https://brew.sh**

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

#### 2. Instalar PHP 8.4

```bash
brew install php@8.4

# Adicionar ao PATH (zsh)
echo 'export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"' >> ~/.zshrc
echo 'export PATH="/opt/homebrew/opt/php@8.4/sbin:$PATH"' >> ~/.zshrc
source ~/.zshrc

# Verificar
php -v
# PHP 8.4.x
```

> 💡 O Symfony CLI detecta automaticamente o arquivo `.php-version` na raiz do projeto
> e usa o PHP 8.4 para `symfony serve`, `symfony console`, etc.
> Não é necessário configuração adicional.

#### 3. Instalar o MySQL

```bash
brew install mysql@8.0

# Iniciar o serviço
brew services start mysql@8.0

# Adicionar ao PATH
echo 'export PATH="/opt/homebrew/opt/mysql@8.0/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc

# Verificar
mysql --version

# Configurar senha root (opcional para dev)
mysql_secure_installation
```

#### 4. Instalar o Composer

```bash
brew install composer
composer --version
```

> O Composer lê o `.php-version` do projeto (via Symfony CLI) e usa o PHP 8.4
> automaticamente ao instalar dependências.

#### 5. Instalar o Git

O Git já vem no macOS via Xcode Command Line Tools:
```bash
git --version
# Se não estiver instalado, o macOS vai solicitar instalação automaticamente
```

Ou instale via Homebrew:
```bash
brew install git
```

---

## Instalação do Symfony CLI

O Symfony CLI facilita rodar o servidor local com HTTPS e verificar os requisitos do projeto.

Documentação oficial: 👉 **https://symfony.com/download**

### Windows
```powershell
# Via Scoop
scoop install symfony-cli

# Ou baixe o instalador em:
# https://github.com/symfony-cli/symfony-cli/releases
```

### Linux
```bash
curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | sudo -E bash
sudo apt install symfony-cli -y
```

### macOS
```bash
brew install symfony-cli/tap/symfony-cli
```

Verificar:
```bash
symfony version
```

---

## Clonando e configurando o projeto

### 1. Clonar o repositório

```bash
git clone https://github.com/SEU_USUARIO/bibliometric.git
cd bibliometric
```

### 2. Instalar as dependências PHP

```bash
composer install
```

> Este comando instala todos os pacotes listados em `composer.json` na pasta `vendor/`. Pode demorar alguns minutos na primeira vez.

### 3. Configurar as variáveis de ambiente

Copie o arquivo de exemplo e edite com suas configurações:

```bash
cp .env .env.local
```

Abra `.env.local` e configure o banco de dados:

#### Usando SQLite (mais simples para desenvolvimento local)
```env
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

#### Usando MySQL
```env
DATABASE_URL="mysql://root:SUA_SENHA@127.0.0.1:3306/bibliometric?serverVersion=8.0.32&charset=utf8mb4"
```

> **Atenção:** Nunca commite o arquivo `.env.local`. Ele já está no `.gitignore`.

### 4. Criar o banco de dados

```bash
# Criar o banco
php bin/console doctrine:database:create

# Executar as migrations (criar as tabelas)
php bin/console doctrine:migrations:migrate
```

Confirme com `yes` quando solicitado.

### 5. Instalar os assets JavaScript

```bash
php bin/console importmap:install
```

---

## Banco de dados

O projeto suporta **SQLite** (sem configuração extra, ideal para desenvolvimento) e **MySQL 8+** (recomendado para produção).

### Criar o banco MySQL manualmente (opcional)

Se preferir criar o banco antes de rodar o Doctrine:

```sql
-- Conecte-se ao MySQL
mysql -u root -p

-- Crie o banco
CREATE DATABASE bibliometric CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Verifique
SHOW DATABASES;
EXIT;
```

### Recriar o banco do zero

```bash
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

---

## Rodando o servidor de desenvolvimento

### Com Symfony CLI (recomendado — suporta HTTPS)

```bash
symfony serve
# Acesse: https://127.0.0.1:8000
```

Para rodar em porta específica:
```bash
symfony serve --port=8001
```

Para rodar em background:
```bash
symfony serve -d
symfony server:stop  # para parar
```

### Com PHP built-in server

```bash
php -S 127.0.0.1:8000 -t public/
# Acesse: http://127.0.0.1:8000
```

> ⚠️ O servidor embutido do PHP não suporta HTTPS. Use o Symfony CLI para certificado local automático.

---

## Comandos úteis

```bash
# Limpar o cache
php bin/console cache:clear

# Ver todas as rotas registradas
php bin/console debug:router

# Verificar requisitos do Symfony
symfony check:requirements

# Criar um usuário admin (se houver comando disponível)
php bin/console app:create-user

# Ver status das migrations
php bin/console doctrine:migrations:status

# Gerar uma nova migration após alterar entidades
php bin/console doctrine:migrations:diff

# Executar migrations pendentes
php bin/console doctrine:migrations:migrate

# Verificar a versão do PHP e extensões
php -m | grep -E "pdo|mbstring|intl|xml|zip"
```

---

## Estrutura do projeto

```
bibliometric/
├── assets/          # JavaScript e CSS (Asset Mapper)
├── config/          # Configurações do Symfony
├── migrations/      # Migrations do banco de dados
├── public/          # Ponto de entrada web (index.php)
├── src/
│   ├── Controller/  # Controllers HTTP
│   ├── Entity/      # Entidades Doctrine (modelos)
│   ├── Service/     # Lógica de negócio
│   └── Repository/  # Repositórios Doctrine
├── templates/       # Templates Twig (HTML)
├── var/             # Cache, logs, SQLite (gitignored)
├── vendor/          # Dependências Composer (gitignored)
├── .env             # Variáveis de ambiente padrão
└── composer.json    # Dependências PHP
```

---

## Solução de problemas comuns

### Erro: `PHP extension required`
```bash
# Verifique quais extensões estão ativas
php -m

# Instale a extensão faltante (exemplo: intl no Ubuntu)
sudo apt install php8.2-intl -y
```

### Erro: `Access denied for user 'root'@'localhost'`
Verifique se o MySQL está rodando e se a senha no `.env.local` está correta:
```bash
mysql -u root -p
```

### Erro: `Unable to guess the Mailer DSN`
Certifique-se que o `.env.local` tem:
```env
MAILER_DSN=null://null
```

### Cache corrompido
```bash
rm -rf var/cache/*
php bin/console cache:clear
```

### Permissões no Linux/macOS
```bash
chmod -R 775 var/ public/
```

---

## Links úteis

| Recurso | URL |
|---|---|
| Documentação oficial do Symfony | https://symfony.com/doc/current/index.html |
| Instalação do Symfony | https://symfony.com/doc/current/setup.html |
| Doctrine ORM | https://www.doctrine-project.org/projects/orm.html |
| Composer | https://getcomposer.org/doc/ |
| XAMPP | https://www.apachefriends.org |
| Homebrew | https://brew.sh |

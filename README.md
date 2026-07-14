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
8. [Comandos Customizados do Sistema (CLI)](#comandos-customizados-do-sistema-cli)

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
# Limpar o cache do Symfony
php bin/console cache:clear

# Ver todas as rotas registradas
php bin/console debug:router

# Verificar requisitos do Symfony
symfony check:requirements

# Ver status das migrations do banco de dados
php bin/console doctrine:migrations:status

# Gerar uma nova migration após alterar entidades
php bin/console doctrine:migrations:diff

# Executar migrations pendentes
php bin/console doctrine:migrations:migrate

# Verificar a versão do PHP e extensões instaladas
php -m | grep -E "pdo|mbstring|intl|xml|zip"
```

---

## Comandos Customizados do Sistema (CLI)

O BiblioMap inclui uma série de comandos customizados no namespace `app` para gerenciar importações, tratamento de dados, administração e testes automáticos. Todos os comandos devem ser executados na raiz do projeto usando o comando `php bin/console <nome-do-comando>`.

### 💻 Administração e Acesso

#### `app:admin:create`
* **Descrição:** Cria um novo usuário administrador no sistema ou promove um usuário existente.
* **Opções:**
  * `--super`: Atribui permissão de Super Administrador (`ROLE_SUPER_ADMIN`) ao usuário criado/promovido.
* **Exemplo:** `php bin/console app:admin:create`

---

### 📥 Importação e Sincronização de Dados

#### `app:import:dataset`
* **Descrição:** Processa um dataset de importação pendente. Este comando é chamado de forma assíncrona pelo sistema em segundo plano quando um novo arquivo é enviado, mas pode ser executado manualmente para re-processar ou depurar erros.
* **Argumentos:**
  * `datasetId` (Obrigatório): ID numérico do dataset a ser importado.
* **Exemplo:** `php bin/console app:import:dataset 12`

#### `app:project:sync-cli`
* **Descrição:** Executa todo o pipeline de sincronização e enriquecimento de dados (geográfico, institucional, autores e palavras-chave) para um determinado projeto.
* **Argumentos:**
  * `projectId` (Obrigatório): ID do projeto.
* **Exemplo:** `php bin/console app:project:sync-cli 9`

---

### 📝 Tratamento e Normalização de Palavras-chave

#### `app:keywords:clear`
* **Descrição:** Limpa por completo todas as palavras-chave (tabelas `keyword` e `document_keyword`) do banco de dados. Útil para reiniciar o dicionário do sistema antes de novos testes ou importações limpas.
* **Exemplo:** `php bin/console app:keywords:clear`

#### `app:keywords:diagnose`
* **Descrição:** Exibe um relatório diagnóstico completo sobre o estado da base de palavras-chave no banco de dados.
* **Exemplo:** `php bin/console app:keywords:diagnose`

#### `app:keywords:treat`
* **Descrição:** Executa o pipeline automatizado de tratamento e consolidação de termos de palavras-chave.
* **Exemplo:** `php bin/console app:keywords:treat`

#### `app:keywords:normalize-casing`
* **Descrição:** Normaliza a capitalização (casing) das palavras-chave para evitar duplicados por diferenças de caixa alta/baixa, consolidando as referências e limpando dados órfãos.
* **Exemplo:** `php bin/console app:keywords:normalize-casing`

#### `app:keywords:migrate-keyword-concepts-to-thesaurus`
* **Descrição:** Migra associações de conceitos legados do sistema antigo para a estrutura nova baseada em `ThesaurusConcept`.
* **Exemplo:** `php bin/console app:keywords:migrate-keyword-concepts-to-thesaurus`

---

### 🗺️ Dados Geográficos e Institucionais

#### `app:geography:seed`
* **Descrição:** Alimenta o banco de dados com a estrutura geográfica padrão (países do mundo, estados e cidades brasileiras) e suas variações linguísticas e siglas de uso comum.
* **Exemplo:** `php bin/console app:geography:seed`

#### `app:geography:apply-corrections-v6`
* **Descrição:** Executa as correções de auditoria avançadas para instituições e países (Fase 3 / V6) com base nos CSVs da pasta `docs/ajustes/`.
* **Exemplo:** `php bin/console app:geography:apply-corrections-v6`

> [!NOTE]
> Existem variações deste comando para projetos específicos e fases anteriores, como `app:apply-corrections`, `app:apply-corrections-v4`, `app:geography:apply-corrections` e `app:geography:apply-corrections-p5`.

---

### 🎓 Banco Qualis CAPES e Periódicos

#### `app:qualis:import`
* **Descrição:** Importa a base de classificação de periódicos Qualis CAPES a partir de arquivos PDF.
* **Exemplo:** `php bin/console app:qualis:import`

#### `app:qualis:resolve-missing`
* **Descrição:** Consulta a API externa Crossref para buscar dados de periódicos (e seus respectivos ISSNs) que estejam ausentes e os insere na tabela `qualis_journal` para validação futura.
* **Exemplo:** `php bin/console app:qualis:resolve-missing`

---

### 🧪 Testes e Backfill

#### `app:run-heavy-test-routine`
* **Descrição:** Roda a rotina pesada de testes automáticos. Limpa projetos antigos de teste, cria o projeto de teste atual, importa os arquivos de dados fornecidos de forma concorrente e realiza a auditoria e validação comparativa detalhada de métricas e arquivos de exportação CSV.
* **Argumentos:**
  * `files` (Obrigatório, múltiplos): Caminho dos arquivos TXT/CSV a serem importados e testados.
* **Exemplo:** `php bin/console app:run-heavy-test-routine savedrecs.txt savedrecs02.txt savedrecs03.txt`

#### `app:backfill:countries` / `app:backfill:institutions`
* **Descrição:** Re-processa e atualiza colunas JSON de países/instituições em documentos existentes no banco de dados a partir dos arquivos Scopus CSV originais.
* **Exemplo:** `php bin/console app:backfill:countries`

#### `app:backfill:theoretical-lenses-citations`
* **Descrição:** Atualiza formatos de citação de lentes teóricas registradas no sistema, garantindo pelo menos 10 formatos por lente.
* **Exemplo:** `php bin/console app:backfill:theoretical-lenses-citations`

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

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
* **Argumentos:** o e-mail é obrigatório. A senha (mínimo de 10 caracteres) pode vir como segundo argumento; sem ela, o comando pergunta no terminal, sem exibir.
* **Opções:**
  * `--super`: Atribui permissão de Super Administrador (`ROLE_SUPER_ADMIN`) ao usuário criado/promovido.
  * `--reset-password`: Troca a senha de um usuário que já existe (sem a opção, a senha existente é mantida).
* **Exemplo:** `php bin/console app:admin:create voce@exemplo.org --super`

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

#### `app:keywords:diagnose`
* **Descrição:** Exibe um relatório diagnóstico completo sobre o estado da base de palavras-chave no banco de dados.
* **Exemplo:** `php bin/console app:keywords:diagnose`

#### `app:keywords:treat`
* **Descrição:** Executa o tratamento de **todas** as palavras-chave da plataforma (tabela global: afeta todos os projetos), em lotes, em quatro etapas: limpeza e invalidação, agrupamento exato, ligação exata ao tesauro `keyword` e ligação por similaridade. Cada execução aparece no histórico de `/admin/super/keywords/treatment` (origem `cli`).
* **Opções principais:**
  * `--dry-run` (padrão quando `--execute` não é passado): simula e só grava o histórico e o relatório.
  * `--execute`: grava as mudanças (modo Real, sem desfazer: a volta é pelo backup do banco).
  * `--require-backup`: só roda se a pasta `DATABASE_BACKUP_DIR` tiver um backup completo com a data de hoje; sem ele, escreve "Backup do banco de hoje não encontrado: tratamento adiado.", não grava nada e termina com código 1.
  * `--limit=N` (0 = todas), `--batch-size=N`, `--min-auto-score`, `--min-review-score`, `--no-invalids`, `--no-exact`, `--no-thesaurus`, `--no-fuzzy`.
  * `--auto-create-concepts`: **não implementada**; aceita por compatibilidade, sem efeito (não cria conceitos).
* **Repetível:** rodar de novo não repete sugestões pendentes ou recusadas, não invalida de novo um termo com o mesmo motivo já gravado (nem o que um administrador aceitou) e registra cada alerta de suspeito uma vez só.
* **Um por vez:** se outro tratamento estiver rodando, escreve "Outro tratamento de palavras-chave está em andamento." e termina sem gravar (código 0). No modo Real, também não começa com um dataset em importação ("Importação em andamento: tratamento adiado.").
* **Rotina noturna (produção):** às 07:30 UTC (04:30 em Brasília), `--execute --require-backup --env=prod`, depois do backup das 07:15 UTC. O "dia" do backup exigido é o dia em UTC, o fuso do servidor. Saída da última noite em `var/log/cron_keywords_treat.log`.
* **Exemplos:**
  * `php bin/console app:keywords:treat --dry-run --limit=1000`
  * `php bin/console app:keywords:treat --execute --require-backup`

---

### 🗺️ Dados Geográficos e Institucionais

#### `app:geography:seed`
* **Descrição:** Alimenta o banco de dados com a estrutura geográfica padrão (países do mundo, estados e cidades brasileiras) e suas variações linguísticas e siglas de uso comum.
* **Exemplo:** `php bin/console app:geography:seed`

---

### 🧭 Classificação Temática

#### `app:classification:compare-expansion`
* **Descrição:** Calcula a classificação de um projeto duas vezes, sem gravar nada no banco: com a expansão anterior pelo tesauro (só os rótulos dos conceitos) e com a atual (rótulos mais as variações ativas da palavra-chave de cada termo, em `palavra_chave_variacoes_nome`). Mostra, por grupo, os documentos antes, depois, que entram e que saem; o resumo de cada modo (ruído, sem grupo, multiclassificados); e, por grupo, as variações que entraram na expansão com quantos documentos cada uma trouxe (as de até 3 caracteres vêm destacadas). Serve para medir o efeito da correção num projeto real antes de reexecutar a classificação, que apaga as movimentações manuais.
* **Argumentos:**
  * `projectId` (Obrigatório): ID do projeto. Um ID que não existe termina com "Projeto #ID não encontrado." e código 1.
* **Opções:**
  * `--csv=<arquivo>`: grava os documentos que mudaram (ID, ano, título, grupo, entrou/saiu, termo) para sortear uma amostra de precisão. O arquivo tem dados do corpus: o comando recusa um caminho dentro do projeto; apague-o depois da análise.
* **Exemplo:** `php bin/console app:classification:compare-expansion 12 --csv=/tmp/comparacao-expansao-12.csv`

---

### 🎓 Banco Qualis CAPES e Periódicos

#### `app:qualis:import`
* **Descrição:** Importa a base de classificação de periódicos Qualis CAPES a partir de arquivos PDF.
* **Exemplo:** `php bin/console app:qualis:import`

#### `app:qualis:resolve-missing`
* **Descrição:** Liga os documentos de **todos os projetos** às revistas já cadastradas em `qualis_journal` pelo ISSN e, para os ISSN que ainda não têm revista, consulta o título no Crossref e o estrato na Sucupira e cadastra a revista (mesmo sem estrato). Muda o relatório por estrato e a lista "Revistas sem Qualis" de todos os projetos. Um ISSN que o Crossref não conhece é consultado de novo na execução seguinte. As chamadas à Sucupira verificam o certificado HTTPS do servidor.
* **Revistas sem estrato:** a cada execução, até `--retry-qualis-limit` revistas (padrão 100; 0 desliga) que estão sem estrato e têm documentos são consultadas de novo na Sucupira: primeiro as que ela nunca respondeu, depois as conferidas há mais de 30 dias (coluna `qualis_checked_at`). O estrato encontrado vai para a revista e para `document.qualis` dos documentos dela, em todos os projetos.
* **Rotina noturna (produção):** às 06:00 UTC (03:00 em Brasília), com `--env=prod`, antes do backup e do tratamento de palavras-chave. Saída da última noite em `var/log/cron_qualis_resolve_missing.log`.
* **Exemplo:** `php bin/console app:qualis:resolve-missing`

---

### 💾 Backup do banco

#### `app:database:backup`
* **Descrição:** Grava um backup completo do banco da `DATABASE_URL` (`mysqldump --single-transaction --no-tablespaces`, comprimido com `gzip`) em `bibliomap-AAAAMMDD-HHMM.sql.gz`. O arquivo nasce como `.part` e só recebe o nome final depois de o dump terminar sem erro e de passar no `gzip -t`. Só lê o banco: não muda nenhum número.
* **Pasta:** variável `DATABASE_BACKUP_DIR`, declarada vazia no `.env` e definida **só no `.env.local`** do servidor: caminho absoluto fora do projeto e da pasta pública, com permissão `700`. O comando recusa a variável vazia, uma pasta dentro do projeto, uma pasta com acesso de grupo ou de outros e menos de 2 GB livres na partição.
* **Arquivos:** permissão `600` (o backup tem dados pessoais dos usuários; nunca vai para o git). Usuário e senha do banco vão num arquivo de opções temporário (`600`) na própria pasta, apagado no fim, e nunca aparecem na linha do cron, na saída nem na lista de processos.
* **Retenção:** depois de um backup bem-sucedido, apaga os backups com mais de 7 dias, exceto os feitos num domingo, que ficam até 28 dias (a idade vem da data no nome). Um backup que falha não apaga nada. Dumps com outros nomes (feitos à mão antes de um deploy) não são tocados.
* **Rotina noturna (produção):** às 07:15 UTC (04:15 em Brasília), com `--env=prod`, antes do tratamento de palavras-chave, que exige o backup do dia (`--require-backup`). Saída da última noite em `var/log/cron_db_backup.log`. A página `/admin/super/keywords/treatment` mostra o último backup e avisa quando falta o de hoje.
* **Restauração** (num banco vazio, de preferência temporário, para conferir antes de substituir o original):
  ```bash
  mysql -e "CREATE DATABASE bibliomap_restaurado"
  gunzip -c bibliomap-AAAAMMDD-HHMM.sql.gz | mysql bibliomap_restaurado
  ```
* **Exemplo:** `php bin/console app:database:backup`

---

### 🧪 Backfill

#### `app:backfill:countries` / `app:backfill:institutions`
* **Descrição:** Re-processa e atualiza colunas JSON de países/instituições em documentos existentes no banco de dados a partir dos arquivos Scopus CSV originais.
* **Exemplo:** `php bin/console app:backfill:countries`

#### `app:backfill:theoretical-lenses-citations`
* **Descrição:** Gera os formatos de citação padrão (pelo menos 10) das lentes teóricas que ainda não têm nenhum. Lentes com formatos já cadastrados ou editados pelo administrador não são tocadas.
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

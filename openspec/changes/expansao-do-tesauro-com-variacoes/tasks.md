# Tasks

## 1. Expansão corrigida

- [ ] 1.1 Extrair a expansão de `ClassificationEngine::expandTermsWithThesaurus()` para um serviço testável (ex.: `src/Service/Classification/ClassificationTermExpander.php`) com os dois modos, "só tesauro" e "tesauro com variações", e sem o `try/catch` silencioso. Verificar com teste unitário (Connection simulada, como em `tests/Service/KeywordTreatmentFixesTest.php`): no modo novo saem os rótulos do tesauro mais os `variation_name` em minúsculas e sem duplicatas, no modo antigo só os rótulos.
- [ ] 1.2 Trocar a consulta das variações pela do design (decisão 1): `TextNormalizer::normalizeForComparison(termo)` igual a `keyword.keyword_normalized`, com `k.status = 1`, JOIN em `palavra_chave_variacoes_nome` com `v.status = 1`, devolvendo `variation_name`. Verificar com teste unitário que "e-learning" é procurado como "e learning" e que a SQL filtra os dois status (cenários "Termo digitado com outra pontuação" e "Variação inativa").
- [ ] 1.3 Verificar com teste unitário que uma exceção da consulta das variações sobe do serviço, em vez de ser engolida.

## 2. Cálculo separado da gravação e comparação

- [ ] 2.1 Separar em `ClassificationEngine` um cálculo puro (sem INSERT, sem DELETE e sem criar o grupo "Sem Classificação") que recebe o modo da expansão, e fazer `run()` montar os termos de todos os grupos antes de `deleteByProject`. Verificar com teste unitário (repositório simulado) que, se o expansor lança exceção, `deleteByProject` não é chamado (cenário "Erro no banco durante a expansão").
- [ ] 2.2 Criar o comando `app:classification:compare-expansion <projectId> [--csv=<arquivo>]` (design, decisão 6). Verificar no banco local, no projeto 12: a saída traz uma linha por grupo; `SELECT COUNT(*), MAX(run_at) FROM document_classification WHERE project_id = 12` dá o mesmo antes e depois; com um ID inexistente sai "Projeto #ID não encontrado." e código diferente de zero.
- [ ] 2.3 Conferir no banco local, que não tem variações, que a classificação do projeto 12 dá as mesmas contagens por grupo antes e depois da mudança (exportar os resultados nas duas versões e comparar).
- [ ] 2.4 Conferir no banco local o cenário "Variação gravada": cadastrar uma variação de teste pela importação de tesauro em `/admin/keywords`, rodar a comparação e a classificação do projeto 12 e ver o documento entrar no grupo pela variação. Apagar a variação de teste e conferir que ela sumiu do cadastro.
- [ ] 2.5 Documentar o comando novo na lista de comandos do `README.md` e conferir que o exemplo documentado roda como está escrito.
- [ ] 2.6 Rodar `vendor/bin/phpunit` (verde) e fazer o commit local do grupo, sem arquivo de corpus nem CSV gerado.

## 3. Aviso na tela e ajuda

- [ ] 3.1 Criar a migration que acrescenta `bibliometric_project.classification_expansion_version` (SMALLINT NULL, com `down`), mais o campo na entidade, e fazer `run()` gravar `ClassificationEngine::EXPANSION_VERSION = 2`. Verificar com `doctrine:migrations:migrate` local e com `doctrine:schema:validate` sem diferença de mapeamento.
- [ ] 3.2 Mostrar o aviso em `templates/classification/index.html.twig` e `results.html.twig` quando o projeto tem resultados, a versão é NULL ou menor que a atual e há grupo com `use_thesaurus = 1`, com o número de documentos com `manual_override = 1`. Verificar localmente os três cenários do requisito "Aviso de resultado calculado com a expansão anterior".
- [ ] 3.3 Na ajuda, criar a chave `classification.index.expansion_notice` (title, what, how, not, example), usada pelo aviso, e atualizar `classification.form.thesaurus` (forma normalizada, só variações ativas, cadastro global que vale para todos os projetos), `classification.results.matched_term` (o termo pode vir de uma variação) e `classification.index.run`/`classification.results.rerun` (falha na expansão não apaga os resultados). Verificar com o `tests/Twig/HelpCatalogTest.php` verde.
- [ ] 3.4 Rodar `vendor/bin/phpunit` (verde) e fazer o commit local do grupo. O push é do Jonas.

## 4. Produção

- [ ] 4.1 Backup antes do deploy (produção, escrita em `~/backups/`): conferir o espaço com `df -h` e rodar `mysqldump` comprimido do banco em `~/backups/bibliomap-AAAAMMDD-expansao.sql.gz`, com as credenciais da `DATABASE_URL` sem imprimi-las. Verificar com `gunzip -t` e com o tamanho maior que zero.
- [ ] 4.2 Deploy (produção, escrita), como runcloud em `~/webapps/app-bibliomap`: `git pull --ff-only origin main`, `./build.sh --prod` e `/RunCloud/Packages/php84rc/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=prod`.
- [ ] 4.3 Conferir depois do deploy (produção, leitura): `doctrine:migrations:status --env=prod` sem pendências, `git log -1` igual ao main, a coluna nova presente, o aviso na classificação de um projeto com resultados e nenhum erro novo em `var/log/prod.log`.
- [ ] 4.4 Listar (produção, leitura, só SELECT) os projetos com resultados de classificação e grupos com o tesauro ligado, só com IDs e contagens, e escolher o projeto da comparação.
- [ ] 4.5 Rodar a comparação (produção, leitura no banco), fora do horário de uso, como runcloud: `/RunCloud/Packages/php84rc/bin/php bin/console app:classification:compare-expansion <id> --env=prod --csv=/home/runcloud/comparacao-expansao-<id>.csv`. Registrar as contagens na tabela "Efeito nos números de um projeto real" do design, sem nomes nem dados pessoais. O Jonas confere uma amostra de até 30 documentos que entraram, por grupo, e a precisão estimada entra no design. Commit só do `openspec/`.
- [ ] 4.6 Conferir que o CSV da comparação ficou fora do webapp e do repositório. Apagá-lo depois da análise e conferir com `ls`.

## Workflow follow-up

- Arquivar a mudança (`openspec archive expansao-do-tesauro-com-variacoes`) depois que o Jonas aprovar os números da tarefa 4.5, e conferir que `openspec/specs/classificacao-tematica/spec.md` ficou com o requisito novo.

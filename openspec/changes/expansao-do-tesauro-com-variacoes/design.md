# Design

## Context

Motivação em proposal.md. Estado atual do código (lido em 09/11/2026):

- `ClassificationEngine::run()` apaga os resultados do projeto (`deleteByProject`) e só depois monta os termos dos grupos (`buildTermMap`). Para grupos com `use_thesaurus = 1`, a montagem chama `expandTermsWithThesaurus()`, que para cada termo: (1) procura conceitos em `thesaurus_label` e, sem achar, em `thesaurus_concept`, por `LOWER(...) = termo`; (2) junta todos os rótulos desses conceitos; (3) procura palavras-chave por `LOWER(keyword_display) = termo OR LOWER(keyword_normalized) = termo` e consulta `SELECT raw_variation FROM keyword_variation`. O passo 3 falha sempre que o termo coincide com uma palavra-chave, porque nem a tabela nem a coluna existem. O `catch (\Throwable) {}` engole o erro. Os rótulos do passo 2 já estavam na lista e por isso continuam valendo.
- `use_thesaurus` tem padrão 1 na coluna, no formulário e na importação de grupos por CSV. Portanto quase todo grupo está sujeito à correção.
- `palavra_chave_variacoes_nome` (`keyword_id`, `variation_name`, `normalized_name`, `variation_type`, `status`) é global. É alimentada pelas mesclagens de **Normalizar e Deduplicar**, pelo tratamento de palavras-chave (a grafia do termo fundido vira variação) e pela importação de tesauro em `/admin/keywords`. O remapeamento do enriquecimento (`DocumentEnrichmentService`) já usa só variações com `v.status = 1` de palavras-chave com `k.status = 1`.
- A ajuda `classification.form.thesaurus` já promete as variações ("e com as variações gravadas para a palavra-chave correspondente"). A correção faz o código cumprir o que a tela diz.
- Banco local (cópia antiga, lida em 09/11/2026): 35.941 palavras-chave, **0** variações e **0** rótulos de tesauro; 4 grupos normais em 2 projetos, todos com o tesauro ligado. Aqui o efeito da correção é nulo, e o efeito real só pode ser medido com os dados de produção (ver "Efeito nos números").

## Goals / Non-Goals

**Goals:**
- Fazer a expansão incluir as variações ativas da palavra-chave do termo, com a mesma regra de status do remapeamento.
- Não perder resultados por falha na expansão, e não esconder a falha.
- Deixar o dono decidir quando reclassificar, informado do que muda e do que perde.
- Medir o efeito num projeto real antes de recomendar a reexecução.

**Non-Goals:**
- Mudar a busca por trecho (sem operadores, com acentos contando) ou a ordem de avaliação.
- Restringir a busca do tesauro ao esquema `keyword`. Hoje ela procura rótulos de todos os esquemas, e isso não muda aqui.
- Incluir na expansão as palavras-chave ligadas ao mesmo conceito do tesauro ou ao mesmo `keyword_concept`. Seria uma ampliação de método e ficaria para uma mudança própria.
- Filtrar variações curtas. Os rótulos do tesauro já entram sem filtro (cenário "Sigla no tesauro"), e a comparação mostra o problema quando ele existe.
- Reclassificar automaticamente qualquer projeto.
- Tornar transacional toda a gravação dos resultados.

## Decisions

### 1. Chave da busca: forma normalizada do termo × `keyword_normalized`
O termo passa por `TextNormalizer::normalizeForComparison()`, a mesma função que gera `keyword_normalized` na importação (minúsculas, sem acentos, pontuação vira espaço). Ele é comparado por igualdade com `keyword_normalized` das palavras-chave com `status = 1`, dos dois tipos (autor e indexada), porque o texto do documento junta os dois campos conforme o grupo. Entram os `variation_name` das variações com `status = 1`, em minúsculas e sem espaços nas pontas. Uma consulta só, com JOIN, por termo.

- Por que não manter `LOWER(keyword_display) = ? OR LOWER(keyword_normalized) = ?`: comparar o termo em minúsculas, com acentos e pontuação, com uma coluna sem acentos só funciona por acaso da collation (`keyword` está em `utf8mb4_0900_ai_ci`, que ignora acentos), e não pega diferença de pontuação ("e-learning" × "e learning"). A regra nova não depende de collation e é a que a ajuda descreve ("idêntico a uma palavra-chave normalizada").
- Por que `variation_name` e não `normalized_name`: o texto do documento não é normalizado (os acentos e a pontuação contam na busca). A forma normalizada da variação não acharia o texto original.

### 2. Montar os termos antes de apagar, e falhar alto
`run()` passa a montar os mapas de termos de todos os grupos antes de `deleteByProject`. O `try/catch` silencioso sai da expansão. Uma exceção sobe até o `ClassificationController`, que já mostra "Erro ao executar classificação: …", e os resultados anteriores ficam intactos.
- Alternativa: envolver a execução inteira numa transação. Isso também protegeria contra falha no meio das inserções, mas abriria uma transação longa (até centenas de milhares de linhas) num MySQL dividido com outros dois apps num servidor de 1 vCPU. A falha da expansão se resolve com a ordem das etapas, e a transação fica fora desta mudança.

### 3. Separar o cálculo da gravação
O motor ganha um método de cálculo puro, que devolve, por documento, os grupos e o termo correspondido e o resumo (total, ruído, sem grupo, multiclassificados). Ele recebe o modo da expansão: só tesauro (o comportamento até hoje) ou tesauro com variações (o novo). `run()` usa o cálculo no modo novo e grava. No cálculo puro, o grupo "Sem Classificação" que ainda não existe é representado sem ser criado, e só `run()` o cria. O modo "só tesauro" fica disponível apenas para a comparação, nunca para a tela.

### 4. Marca da versão da expansão no projeto
A coluna nova `bibliometric_project.classification_expansion_version` (SMALLINT NULL) é gravada por `run()` com a constante `ClassificationEngine::EXPANSION_VERSION = 2`. NULL quer dizer "antes desta mudança". O aviso aparece quando o projeto tem resultados, a versão é menor que a atual (ou NULL) e há ao menos um grupo com `use_thesaurus = 1`. O aviso traz o número de documentos com `manual_override = 1`, que a reexecução apagaria. Se a expansão mudar de novo no futuro, basta subir a constante para o aviso voltar.
- Alternativa: comparar `run_at` com uma data fixa no código. Foi descartada porque a data do deploy não é conhecida no commit, e uma execução entre o commit e o deploy seria contada errado.
- Alternativa: avisar só por e-mail. O código não envia e-mail. Se o Jonas quiser avisar os donos, isso complementa o aviso na tela, mas não o substitui (ver Open Questions).

### 5. Reclassificar só sob demanda
Nada é recalculado sozinho, porque reexecutar apaga as movimentações manuais (decisão registrada na spec e na ajuda). O dono vê o aviso e pode exportar os resultados atuais ("Exportar Resultados", que já existe). Depois decide se executa de novo.

### 6. Comando de comparação
`app:classification:compare-expansion <projectId> [--csv=<arquivo>]` roda o cálculo puro duas vezes (só tesauro × tesauro com variações) e mostra:
- uma tabela por grupo: documentos antes, depois, que entram e que saem;
- o resumo de cada modo (ruído, sem grupo, multiclassificados);
- por grupo, as variações que entraram na expansão e não estavam entre os rótulos do tesauro, com o número de documentos que entraram no grupo por elas (termo correspondido), destacando as de até 3 caracteres.

Com `--csv`, grava um arquivo com os documentos que mudaram (ID, ano, título, grupo, entrou/saiu, termo), para sortear a amostra de precisão. O arquivo tem dados do corpus e é gravado fora do repositório (o repositório é público). Em produção o comando é **leitura** no banco (só SELECT) e usa CPU. O arquivo CSV é a única escrita, na pasta pessoal do runcloud, fora do webapp.

### Referência metodológica e efeito esperado
- Expandir a consulta com um vocabulário controlado aumenta a revocação e pode reduzir a precisão. É o efeito clássico das relações de equivalência (termo preferido × não preferido) descritas em Lancaster (1986, *Vocabulary Control for Information Retrieval*) e na ISO 25964-1:2011. Na bibliometria, unificar variantes e sinônimos antes de contar é prática padrão: o argumento `synonyms` do `thematicMap()`/`termExtraction()` do Bibliometrix (Aria; Cuccurullo, 2017, *Journal of Informetrics* 11(4)) e os tesauros `.the` do VantagePoint (Porter; Cunningham, 2005, *Tech Mining*), formato que o projeto importa. A delimitação lexical de um campo se avalia pela precisão numa amostra dos documentos recuperados (Zitt; Bassecoulard, 2006, *Information Processing & Management* 42(6)). A comparação proposta segue esse roteiro.
- O efeito não é só "mais documentos". Os termos só aumentam, mas grupos de **Ruído** com o tesauro ligado passam a capturar mais documentos, que então saem de todos os grupos normais. **Validadores** com o tesauro passam a aprovar documentos antes reprovados, que podem entrar em grupos normais ou em "Sem Classificação". O termo correspondido pode mudar (vale o primeiro encontrado na lista expandida). Depois da reexecução mudam também o Comparativo (Jaccard), o Relatório de Classificação, as matrizes com a dimensão "grupo temático" e a exportação de categorias para o VOSviewer.
- Efeito em todos os projetos: as tabelas de variações e de palavras-chave são globais, mas aqui só são lidas. Cada projeto só muda quando o dono reexecuta. A partir da correção, cadastrar ou desativar uma variação muda o resultado da próxima execução em qualquer projeto com grupo cujo termo coincida com aquela palavra-chave.

### Efeito nos números de um projeto real
Ainda não medido: o banco local não tem variações e o acesso à produção ficou fora desta proposta. A tarefa 4.5 roda a comparação em produção (leitura) no projeto com mais grupos com o tesauro ligado e registra aqui, sem nomes nem dados pessoais (só o ID do projeto e contagens):

| Grupo (tipo) | Antes | Depois | Entram | Saem | Variações que mais trouxeram |
|---|---|---|---|---|---|
| Projeto 9, grupo #1 (validador, 19 termos) | — | — | 0 | 0 | nenhuma: 19 termos viram 29 com os rótulos do tesauro, e as variações não acrescentam nenhum termo |

Leitura de 10/11/2026 (só SELECT): nenhum projeto de produção tem resultados de classificação gravados; o projeto 9 é o único com termos em grupo com o tesauro ligado (os 2 grupos do projeto 1 não têm termos); a base tem 22 variações, todas ativas e de palavras-chave ativas. Como as listas de termos dos dois modos são iguais, a classificação do projeto 9 dá o mesmo resultado nas duas expansões. As contagens de documentos por grupo ficam pendentes: o comando de comparação estourou o `memory_limit` de 256 MB do PHP de linha de comando ao ler os 49.992 documentos do projeto (nada foi gravado).

Mais a precisão estimada numa amostra de até 30 documentos que entraram, por grupo, conferida pelo Jonas.

## Risks / Trade-offs

- [Variações ruins (curtas, genéricas ou de fusões erradas) passam a classificar, porque a busca é por trecho: uma variação "ia" casaria com "media" e "social"] → a comparação lista as variações e destaca as de até 3 caracteres. O dono pode trocar o termo ou desligar o tesauro no grupo, e o administrador pode corrigir ou desativar a variação no cadastro global.
- [Corrigir uma variação global muda a próxima execução de outros projetos] → é o comportamento esperado de tabela de autoridade. A ajuda da expansão passa a dizer isso.
- [Reexecutar apaga as movimentações manuais] → o aviso mostra quantas são, e a exportação dos resultados já existe.
- [Custo: uma consulta a mais por termo com o tesauro ligado] → 10 grupos com 50 termos dão 500 consultas indexadas (`keyword_normalized` tem índice). É desprezível perto da leitura dos documentos.
- [Comparação pesada num servidor dividido] → rodar fora do horário de uso. É só leitura e pode ser interrompida sem efeito.
- [O modo "só tesauro" fica no código e alguém pode usá-lo na tela] → ele só é aceito pelo cálculo puro e pelo comando de comparação. `run()` usa sempre o modo atual.

## Migration Plan

1. Implementar e testar localmente (tarefas 1 a 3). `vendor/bin/phpunit` antes de cada commit. Push pelo Jonas.
2. Produção, **escrita**: backup (`mysqldump` em `~/backups/`), depois `git pull --ff-only`, `./build.sh --prod` e `doctrine:migrations:migrate` (a migration só acrescenta a coluna anulável). Conferência depois do deploy.
3. Produção, **leitura**: comparação no projeto escolhido e registro dos números neste design.
4. Os donos reexecutam quando quiserem. Até lá, o aviso fica na tela.
- Rollback: reverter o commit e rodar o `down` da migration (remove a coluna). Resultados reexecutados com a versão nova continuam gravados e válidos para aquela versão.

## Open Questions

- O Jonas vai avisar por e-mail os donos dos projetos afetados (a lista sai da tarefa 4.4)? Isso não muda o código nem as specs.
- Se a amostra mostrar que variações curtas derrubam a precisão, vale abrir uma mudança própria para um tamanho mínimo de variação na expansão. Depende do resultado da tarefa 4.5.

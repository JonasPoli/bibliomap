# classificacao-tematica Specification

## Purpose
Classifica os documentos de um projeto em grupos temáticos definidos pelo usuário, com grupos de Ruído (falsos positivos), Validadores e "Sem Classificação", e permite revisar, mover e exportar o resultado. Reproduz o `classifica.php` do classificador do PPGCTS. Implementado em `ClassificationEngine`, `ClassificationController` e na entidade `ClassificationGroup` (tipos `normal`, `validator`, `noise` e `unclassified`). Guias em `docs/Classificacao-Tematica-Guia.md` e `docs/grupo-tematico-plus.md`.

## Requirements
### Requirement: Ordem de avaliação
Para cada documento, o motor SHALL aplicar nesta ordem: ano mínimo, termos de Ruído, Validadores, todos os grupos normais e, por fim, "Sem Classificação". Um documento reprovado no ano mínimo, no Ruído ou nos Validadores MUST NOT ser avaliado nos grupos normais.

#### Scenario: Documento com termo de Ruído e termo temático
- **WHEN** o título contém um termo do grupo de Ruído e também um termo de um grupo normal
- **THEN** o documento fica só no grupo de Ruído

### Requirement: Ano mínimo
Quando o projeto tem ano mínimo de classificação, o documento publicado antes dele SHALL ir para o primeiro grupo de Ruído (pela posição), com o termo `year<ANO`. Documento sem ano SHALL seguir para as etapas seguintes.

#### Scenario: Artigo antigo
- **WHEN** o ano mínimo é 2010 e o documento é de 2008
- **THEN** o documento vai para o primeiro Ruído com o termo `year<2010`

### Requirement: Ruído
O primeiro termo de Ruído encontrado SHALL mandar o documento para o grupo de Ruído dono do termo, e a avaliação do documento SHALL parar ali.

#### Scenario: Falso positivo conhecido
- **WHEN** o resumo contém "radiation therapy", termo do grupo "Ruído / Falso Positivo"
- **THEN** o documento fica nesse grupo com o termo "radiation therapy"

### Requirement: Validadores
Se o projeto tem grupos Validadores, o documento SHALL conter ao menos um termo de algum deles. Sem nenhum, SHALL ir para o primeiro grupo de Ruído ou, se o projeto não tem Ruído, para "Sem Classificação", contado como reprovado sem grupo.

#### Scenario: Projeto sem grupo de Ruído
- **WHEN** um documento não contém termo validador e o projeto não tem grupo de Ruído
- **THEN** o documento vai para "Sem Classificação"
- **AND** o resumo da execução o conta entre os reprovados que ficaram sem grupo

### Requirement: Multiclassificação nos grupos normais
O documento SHALL entrar em todos os grupos normais cujos filtros passam (estratos Qualis, intervalo de anos, continente, países, natureza da instituição e autores) e cujo algum termo aparece no texto, gravando o primeiro termo encontrado. A posição do grupo SHALL definir só a ordem de exibição.

#### Scenario: Documento em dois temas
- **WHEN** o texto contém "drone" (grupo Robótica) e "irrigation" (grupo Recursos Hídricos)
- **THEN** o documento entra nos dois grupos e conta como multiclassificado

### Requirement: Busca por trecho de texto
Os termos e o texto do documento SHALL ser comparados em minúsculas (com `mb_strtolower`) por trecho, sem operadores, aspas ou curingas, e com os acentos contando. O texto SHALL juntar só os campos escolhidos no grupo: título, resumo, palavras-chave do autor (DE) e Keywords Plus (ID), com a forma tratada e a original de cada palavra-chave.

#### Scenario: Termo dentro de outra palavra
- **WHEN** um grupo tem o termo "vigor" e o título diz "vigorous growth"
- **THEN** o documento entra no grupo

### Requirement: Filtro de continente
O filtro de continente SHALL comparar com os continentes dos países das instituições do documento; país transcontinental ("Europa/Ásia") SHALL casar tanto com "Europa" quanto com "Ásia".

#### Scenario: Instituição em país transcontinental
- **WHEN** o grupo filtra "Ásia" e a única instituição do documento é de um país "Europa/Ásia"
- **THEN** o filtro de continente aceita o documento

### Requirement: Expansão pelo tesauro
Com "Utilizar Tesauro Global" ligado, cada termo do grupo SHALL ser ampliado com todos os rótulos dos conceitos do tesauro cujo rótulo ou rótulo preferido é igual ao termo, em minúsculas. As variações das palavras-chave (`palavra_chave_variacoes_nome`) não entram na expansão.

#### Scenario: Sigla no tesauro
- **WHEN** o grupo tem "artificial intelligence" com o tesauro ligado e o conceito tem o rótulo "AI"
- **THEN** documentos que contêm "ai" também entram no grupo

### Requirement: Grupo Sem Classificação
O documento que não entra em nenhum grupo SHALL ir para o grupo `unclassified` do projeto, criado automaticamente na primeira execução se não existir ("Sem Classificação", posição 999).

#### Scenario: Primeira execução
- **WHEN** o dono executa a classificação num projeto sem grupo "Sem Classificação"
- **THEN** o grupo é criado e recebe os documentos sem correspondência

### Requirement: Reexecução apaga os resultados
Executar a classificação, só pelo dono e por POST, SHALL apagar todos os resultados anteriores do projeto, inclusive as movimentações manuais, antes de gravar os novos.

#### Scenario: Documento movido à mão
- **WHEN** o dono moveu um documento para outro grupo e executa de novo
- **THEN** o documento volta para o grupo que o motor calcular

### Requirement: Mover documento entre grupos
O dono SHALL poder mover um documento do grupo aberto na tela para outro grupo do mesmo projeto ou para "Sem Classificação", com token CSRF, marcando a classificação como manual. Se o documento já está no grupo de destino, SHALL só sair do grupo de origem.

#### Scenario: Grupo de outro projeto
- **WHEN** o pedido traz o ID de um grupo de outro projeto
- **THEN** a resposta é 400 "Grupo inválido" e nada muda

### Requirement: Exportação CSV do resultado
A exportação SHALL gerar um CSV com BOM UTF-8 e separador `;`, uma linha por documento classificado, com título, ano, DOI, grupos, tipos, termos correspondidos, se houve movimentação manual e a data. Quem pode ver o projeto SHALL poder exportar.

#### Scenario: Documento em dois grupos
- **WHEN** um documento está em dois grupos normais
- **THEN** ele sai numa linha só, com os dois nomes de grupo separados por "|"

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
Com "Utilizar Tesauro Global" ligado, cada termo do grupo SHALL ser ampliado com todos os rótulos dos conceitos do tesauro cujo rótulo ou rótulo preferido é igual ao termo, em minúsculas, e com as variações ativas (`palavra_chave_variacoes_nome`) das palavras-chave ativas cuja forma normalizada (minúsculas, sem acentos e sem pontuação) é igual à do termo. Com a opção desligada, o termo SHALL ser procurado só como foi digitado.

#### Scenario: Sigla no tesauro
- **WHEN** o grupo tem "artificial intelligence" com o tesauro ligado e o conceito tem o rótulo "AI"
- **THEN** documentos que contêm "ai" também entram no grupo

#### Scenario: Variação gravada da palavra-chave
- **WHEN** o grupo tem "remote sensing" com o tesauro ligado e a palavra-chave "Remote sensing" tem a variação ativa "remote-sensing"
- **THEN** documentos que contêm "remote-sensing" também entram no grupo

#### Scenario: Termo digitado com outra pontuação
- **WHEN** o grupo tem "e-learning" com o tesauro ligado e a palavra-chave de forma normalizada "e learning" tem a variação ativa "elearning"
- **THEN** documentos que contêm "elearning" também entram no grupo

#### Scenario: Variação inativa
- **WHEN** a variação "remote-sensing" está com status inativo
- **THEN** ela não entra na expansão do termo "remote sensing"

#### Scenario: Tesauro desligado
- **WHEN** o grupo tem "remote sensing" com o tesauro desligado
- **THEN** só documentos que contêm "remote sensing" entram no grupo, sem sinônimos nem variações

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

### Requirement: Falha na expansão preserva os resultados
Se a montagem dos termos dos grupos ou a consulta da expansão falhar, a execução SHALL parar antes de apagar qualquer resultado, mostrar a mensagem do erro e manter os resultados e as movimentações manuais anteriores do projeto. A falha MUST NOT ser ignorada em silêncio.

#### Scenario: Erro no banco durante a expansão
- **WHEN** a consulta das variações falha durante a execução da classificação
- **THEN** a tela mostra "Erro ao executar classificação:" seguido da mensagem do erro
- **AND** os resultados e as movimentações manuais anteriores continuam iguais

### Requirement: Aviso de resultado calculado com a expansão anterior
Quando o projeto tem resultados gravados por uma versão da expansão anterior à atual e ao menos um grupo com o tesauro ligado, as telas da classificação e dos resultados SHALL mostrar um aviso de que a próxima execução pode mudar os grupos, com o número de movimentações manuais que ela apagaria. O aviso SHALL sumir depois da próxima execução.

#### Scenario: Projeto classificado antes da correção
- **WHEN** o dono abre a classificação de um projeto executado antes da correção, com um grupo com o tesauro ligado e 3 documentos movidos à mão
- **THEN** a tela avisa que os resultados foram calculados sem as variações das palavras-chave e que executar de novo apaga as 3 movimentações manuais

#### Scenario: Projeto sem grupo com tesauro
- **WHEN** todos os grupos do projeto estão com o tesauro desligado
- **THEN** nenhum aviso aparece

#### Scenario: Depois de executar de novo
- **WHEN** o dono executa a classificação depois da correção
- **THEN** o aviso deixa de aparecer

### Requirement: Comparação da expansão sem gravar
O comando `app:classification:compare-expansion` SHALL calcular a classificação de um projeto com a expansão sem as variações e com as variações, sem gravar nada no banco, e mostrar, por grupo, os documentos antes e depois, os que entram e os que saem, e as variações que entraram na expansão com quantos documentos cada uma trouxe.

#### Scenario: Comparação num projeto
- **WHEN** o administrador roda o comando com o ID de um projeto que tem grupos
- **THEN** a saída traz uma linha por grupo com os documentos antes, depois, que entram e que saem
- **AND** os resultados gravados do projeto não mudam

#### Scenario: Projeto inexistente
- **WHEN** o comando recebe um ID de projeto que não existe
- **THEN** sai com erro "Projeto #ID não encontrado." e código diferente de zero

### Requirement: Gravação atômica dos resultados
A execução da classificação SHALL apagar os resultados anteriores e gravar os novos numa única transação: uma falha em qualquer ponto SHALL deixar os resultados e as movimentações manuais anteriores intactos.

#### Scenario: Falha no meio da gravação
- **WHEN** a gravação dos resultados novos falha depois de parte das linhas
- **THEN** o projeto continua com todos os resultados da execução anterior

### Requirement: Leitura por partes
A classificação SHALL ler os documentos do projeto em blocos, sem manter título, resumo e palavras-chave de todos os documentos na memória, e o resultado MUST ser o mesmo da leitura de uma vez.

#### Scenario: Projeto de 50 mil documentos
- **WHEN** a classificação do projeto 9 (49.992 documentos) é calculada pela linha de comando
- **THEN** termina dentro do limite de 256 MB de memória

### Requirement: Cor e ícone do grupo
A cor do grupo SHALL ser um hexadecimal de seis dígitos e o ícone um nome `bi-…`; no formulário e na importação de grupos por CSV, valor fora do padrão SHALL ser trocado pelo padrão.

#### Scenario: CSV com cor inválida
- **WHEN** o CSV de grupos traz `red; background:url(x)` na cor
- **THEN** o grupo é criado com a cor padrão

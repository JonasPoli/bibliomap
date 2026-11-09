## MODIFIED Requirements

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

## ADDED Requirements

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

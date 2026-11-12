## ADDED Requirements

### Requirement: Corte determinístico
Quando vários itens empatam na frequência do último lugar de um corte (nós da rede, itens do gráfico de três campos, linhas e colunas da matriz, listas "top N" dos relatórios), o sistema SHALL desempatar por um critério fixo, de modo que os mesmos dados deem sempre o mesmo resultado.

#### Scenario: Empate no último nó
- **WHEN** a rede de coautoria é pedida duas vezes com `limit=100` e há 5 autores empatados na 100ª posição
- **THEN** as duas respostas trazem o mesmo autor na 100ª posição

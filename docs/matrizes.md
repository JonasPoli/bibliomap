# Documentação Técnica: Painel de Gerenciamento e Criação de Matrizes Bibliométricas

O **BiblioMap** oferece um módulo moderno e extensível de **Matrizes Bibliométricas**, capaz de cruzar e analisar a co-ocorrência entre quaisquer duas dimensões do corpus (Linhas vs. Colunas).

---

## 1. Como Funciona a Geração de Matrizes

A matriz de contingência $M_{(N \times M)}$ contabiliza, para cada documento do projeto, a presença conjunta de valores entre duas dimensões $X$ (Linha) e $Y$ (Coluna):

1. **Varredura por Documento:** O motor (`MatrixEngineService`) percorre artigo por artigo do projeto selecionado.
2. **Extração e Normalização com Tesauro Global:** Para cada documento, o motor extrai os valores de $X$ e $Y$. Se o botão **"Tesauro Global"** estiver ativo (opção padrão), os valores brutos são substituídos pelos conceitos normalizados do Tesauro (`thesaurus_concept`, `author_identity`, `instituicoes_ensino`, `paises`, `JournalVariation`).
3. **Contabilidade de Co-ocorrência:**
   - **Matriz Cruzada ($X \neq Y$):** Para cada combinação de $(x \in X, y \in Y)$ presente no artigo, incrementa-se $M[x][y] \leftarrow M[x][y] + 1$.
   - **Matriz de Co-ocorrência / Simétrica ($X = Y$):** Para cada par $(x_i, x_j)$ com $i \neq j$ no mesmo artigo, incrementa-se $M[x_i][x_j] \leftarrow M[x_i][x_j] + 1$.
   - **Regra da Diagonal Zero:** Sempre que o mesmo campo for selecionado simultaneamente para Linha e Coluna ($X = Y$), ou quando o item da linha for idêntico ao item da coluna ($x = y$), o valor da célula assume rigorosamente **0 (zero)** ($M[x][x] = 0$).
4. **Agregação e Estatísticas:** São calculados os totais de linhas, colunas, total geral de conexões, células ativas, taxa de densidade e esparsidade da matriz.

---

## 2. Dimensões Suportadas no Sistema

O painel suporta atualmente 12 dimensões organizadas por categorias:

| Chave (`key`) | Rótulo (`label`) | Categoria | Uso do Tesauro |
|---|---|---|---|
| `author` | Autores | Pessoas & Autoria | `author_identity` / `author_name_variant` |
| `keyword_author` | Palavras-chave do Autor | Conceitos & Palavras-Chave | `thesaurus_concept` / `thesaurus_label` |
| `keyword_indexed` | Keywords Plus (Indexadas) | Conceitos & Palavras-Chave | `thesaurus_concept` / `thesaurus_label` |
| `year` | Ano de Publicação | Temporal | N/A (Inteiros de ano) |
| `institution` | Instituição de Ensino / Pesquisa | Institucional | `instituicoes_ensino` / `institution_variation` |
| `institution_nature` | Natureza Jurídica | Institucional | Agrupamento por tipo (`Pública`, `Empresa`, etc.) |
| `country` | País de Origem | Geografia | `paises` / `country_variation` |
| `continent` | Continente / Região | Geografia | Tabela de regiões geográficas |
| `state` | Estado / UF | Geografia | `estados` / `state_variation` |
| `city` | Cidade | Geografia | `cidades` / `city_variation` |
| `qualis` | Estrato Qualis (CAPES) | Publicação & Impacto | Mapeamento `qualis_journal` |
| `thematic_group` | Grupo Temático (Classificação) | Classificação & Temas | Motor de grupos temáticos do BiblioMap |

---

## 3. Roteiro de Auditoria das Matrizes

Para auditar e conferir a integridade dos dados de uma matriz gerada:

1. **Acessar a tela de Matrizes:** Navegue para `/projects/{id}/matrices`.
2. **Selecionar o Par de Dimensões:** Escolha o Eixo Linhas e o Eixo Colunas (ex: *Palavras-chave do Autor* x *País de Origem*).
3. **Verificar os Totais da Tabela Dinâmica:**
   - A soma do total de cada linha reflete o número de pares co-ocorrentes daquele item nas colunas exibidas.
   - O total no rodapé (*Total Geral*) contabiliza a soma acumulada da matriz.
4. **Validar o Tesauro Global:** Alterne o botão "Tesauro Global" entre ativo/inativo para comparar os termos brutos com os conceitos unificados pelo Tesauro.
5. **Exportação CSV para Auditoria Externa:** Clique em **"Exportar CSV"**. Abra o arquivo em um software de planilha (Excel / Google Sheets) ou script R/Python para validar os cálculos matriciais.

---

## 4. Guia para Desenvolvedores: Como Adicionar Novas Dimensões

A arquitetura do motor de matrizes foi projetada para ser flexível e permitir a inclusão de novas dimensões sem refatorar o código existente.

### Passo a passo para adicionar uma nova dimensão (Exemplo: *Área do Conhecimento*):

1. **Registrar a nova dimensão no `MatrixEngineService.php`:**
   Adicione a chave e rótulos no método `registerDefaultDimensions()`:
   ```php
   'knowledge_area' => [
       'label' => 'Área do Conhecimento',
       'category' => 'Classificação & Temas'
   ],
   ```

2. **Incluir a extração dos valores no método `extractValuesForKey()`:**
   ```php
   'knowledge_area' => $docData['knowledge_areas'] ?? [],
   ```

3. **Carregar os dados da relação (se for uma nova entidade):**
   Adicione a consulta no `generateMatrix()` para popular o campo no `$docData`.

Pronto! A nova dimensão aparecerá automaticamente no formulário da interface, no endpoint da API e nos relatórios de exportação em CSV.
O sistema precisa ter um painel para gerenciamento e criação das matrizes.
O usuário vai poder escolher 2 campos para serem usados nas matrizes. Por exemplo: Autores e palavras-chave, ano, instituição, paíse, continente, estado, cidade, região do país, área do conhecimento, etc (adicione outros campos que você achar que devem estar, não se limiete aos exemplo)
Deixe tudo isso muito bem documentado pois precisará, primeiro, ser auditado e depois, novas formas de se criar matrizes devem ser adicionadas. O painel deve ser flexível o suficiente para permitir essas adiçoes sem grandes alterações futuras.
(sempre usando tesauros)

As matrizes funcionam da seguinte maneira:
O sistema deve contar, um a um a cada trabalho, quantos trabalhos são simultanemante de cada um do dois eixos da matriz. Por exemplo se um trabalho estiver na primeira linha, na primeira coluna, ele será contado como um trabalho de cada um do dois eixos. Se ele estiver na segunda linha e primeira coluna, ele será contado como um trabalho de cada um do dois eixos. Se ele estiver na primeira linha e segunda coluna, ele será contado como um trabalho de cada um do dois eixos. 
Outro exemplo: Quais instituições estão relacionadas com palavras chaves. (o eixo vertical são as instituições e o horizontal são as palavras chaves).
As linhas podem ser as instituições e as colunas as palavras chaves.
Primeiro o sistema deve localizar todas as palavras chaves (no caso de palavras chaves pelo tesauro) que estão contidas nos trabalhos analisados.
Depois o sistema deve localizar todas as instituições que estão contidas nos trabalhos analisados (no caso de instituições pelo tesauro)
Depois deve pegar uma por uma de todas as ocorrências de palavras chaves e instituições nos trabalhos. Por exemplo se a instituição "Universidade X" aparece em 32 trabalhos e a palavra chave "Python" aparece em 15 trabalhos. Se a "Universidade X" aparece em 15 trabalhos que também tem a palavra chave "Python", então na matriz teremos o valor 15 na linha "Universidade X" e coluna "Python".
Depois de gerado, deve ser possível exportar para CSV.

O sistema deve, ainda, permitir que o mesmo campo seja simultaneamente coluna e linha. Neste caso, sempre que o ceuzamento for o mesmo item, deve assumir zero.

# Documentação Técnica: Painel de Gerenciamento e Criação de Matrizes Bibliométricas



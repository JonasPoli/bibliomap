# Documentação Técnica: Evolução de Grupos Temáticos & Relatórios Comparativos

Esta documentação descreve a implementação completa das novas formas de criação de grupos temáticos e do módulo de **Relatórios Comparativos entre Grupos** no **BiblioMap**.

---

## 1. Novas Formas de Criar Grupos Temáticos

No formulário de criação/edição de grupos (`/projects/{id}/classification/groups/new`), o usuário possui um painel avançado para configurar múltiplos critérios de enquadramento:

### 1.1 Seleção de Campos Bibliográficos
- O usuário pode escolher em quais campos os termos de busca serão pesquisados:
  - **Título (`TI`):** `title`
  - **Abstract / Resumo (`AB`):** `abstract`
  - **Palavras-chave do Autor (`DE`):** `author_keywords`
  - **Keywords Plus (`ID`):** `indexed_keywords`

### 1.2 Filtro Temporal (Intervalo de Anos)
- Define ano inicial (`startYear`) e ano final (`endYear`). Documentos fora desse intervalo são desconsiderados para o grupo.

### 1.3 Filtro Geográfico e Institucional
- **Natureza Jurídica / Tipo:** Permite selecionar o perfil das instituições (`Pública`, `Privada`, `Empresa`, `Governo`, `Ensino/Pesquisa`, `Internacional`).
- **Continente / Região:** Permite restringir o grupo a continentes específicos (`América do Sul`, `Europa`, `Ásia`, etc.).
- **Filtro de Países de Afiliação:** Seletor interativo com busca em tempo real (*Live Search*), badges removíveis e seleção em lote.

### 1.4 Filtro de Autoria
- Permite especificar uma lista de autores (`authorsFilter`) para restringir a inclusão no grupo aos trabalhos publicados por esses pesquisadores.

### 1.5 Integração com o Tesauro Global
- Quando o toggle **"Utilizar Tesauro Global"** está ativo, o motor (`ClassificationEngine`) expande automaticamente os termos pesquisados buscando todos os seus sinônimos e variações gramaticais cadastradas no Tesauro Global (`ThesaurusConcept`, `ThesaurusLabel`).

---

## 2. Módulo de Relatórios Comparativos entre Grupos

Localizado no menu principal em **Classificação Temática > Comparar Grupos** (`/projects/{id}/classification/compare`), este módulo permite selecionar 2 ou mais grupos do projeto e gerar análises comparativas aprofundadas.

### 2.1 Visões Analíticas Disponíveis

1. **Resumo de Volume e Citações:**
   - Total de documentos por grupo, percentual em relação ao corpus, total acumulado de citações e média de citações por artigo.
2. **Evolução Temporal Comparada:**
   - Gráfico de linhas interativo (Chart.js) e tabela cruzada mostrando a produção anual de cada grupo ao longo do tempo.
3. **Sobreposição de Palavras-Chave & Índice de Jaccard (com Tesauro):**
   - Utiliza os conceitos normalizados do Tesauro para calcular a interseção de temas entre grupos e o **Índice de Similaridade de Jaccard**:
     $$J(A, B) = \frac{|A \cap B|}{|A \cup B|}$$
4. **Perfil Geográfico Comparado:**
   - Tabela e distribuição de países de origem da produção científica dos grupos comparados.
5. **Perfil Institucional Comparado:**
   - Comparativo do tipo de instituições de pesquisa envolvidas em cada grupo.
6. **Distribuição de Impacto Qualis (CAPES):**
   - Mapeamento dos artigos dos grupos nos estratos Qualis (A1, A2, B1, B2, B3, B4, C).

---

## 3. Exportação de Relatórios em CSV

No painel de comparação (`/projects/{id}/classification/compare`), o botão **"Exportar CSV Comparativo"** faz o download imediato de um arquivo `.csv` contendo todas as 6 visões comparativas compiladas em UTF-8 com BOM (pronto para abertura direta no Microsoft Excel).

## 3. Exportação e Importação de Grupos em CSV

Na página de gerenciamento de grupos (`/projects/{id}/classification`), o usuário conta com recursos para backup, transferência e importação em lote de cadastros de grupos:

- **Exportar Grupos (CSV):** Faz o download de um arquivo `.csv` codificado em UTF-8 com BOM contendo todos os grupos e suas regras configuradas (nome, tipo, cor, ícone, posição, termos, campos bibliográficos, datas, instituição, países, autores e toggle do Tesauro).
- **Importar Grupos (CSV):** Permite enviar uma planilha `.csv` para cadastrar automaticamente múltiplos grupos no projeto com todos os seus termos e filtros avançados.

---

## 4. Estrutura de Código e Arquivos

- **Controller de Classificação:** [`ClassificationController.php`](file:///Volumes/Dados/work/bibliomap/src/Controller/ClassificationController.php) (`exportGroupsCsv`, `importGroupsCsv`)
- **Controller de Comparação:** [`GroupComparisonController.php`](file:///Volumes/Dados/work/bibliomap/src/Controller/GroupComparisonController.php)
- **Serviço de Comparação:** [`GroupComparisonService.php`](file:///Volumes/Dados/work/bibliomap/src/Service/Classification/GroupComparisonService.php)
- **Interface Gráfica de Grupos:** [`templates/classification/index.html.twig`](file:///Volumes/Dados/work/bibliomap/templates/classification/index.html.twig)
- **Interface Gráfica de Comparação:** [`templates/classification/compare.html.twig`](file:///Volumes/Dados/work/bibliomap/templates/classification/compare.html.twig)
- **Entidade do Grupo:** [`ClassificationGroup.php`](file:///Volumes/Dados/work/bibliomap/src/Entity/ClassificationGroup.php)
- **Motor de Classificação:** [`ClassificationEngine.php`](file:///Volumes/Dados/work/bibliomap/src/Service/Classification/ClassificationEngine.php)

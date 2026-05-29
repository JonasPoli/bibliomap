Abaixo está um planejamento **bem completo** para desenvolver um “Bibliometrix em Symfony”, com foco em importar bases bibliográficas, processar indicadores, gerar gráficos, redes, relatórios e oferecer uma experiência visual melhor do que o Biblioshiny.

Como referência: o Bibliometrix/Biblioshiny trabalha com importação/conversão de bases bibliográficas e suporta fontes como **Web of Science, Scopus, Dimensions, Lens, PubMed e Cochrane Library**; o Biblioshiny organiza análises em níveis como **Sources, Authors, Documents**, além de estruturas de conhecimento, redes, acoplamento, cocitação, colaboração e coocorrência. ([Bibliometrix][1])

# Planejamento completo para desenvolvimento de uma plataforma bibliométrica em Symfony

## 1. Visão geral do projeto

O objetivo será desenvolver uma plataforma web em Symfony para análise bibliométrica e cientométrica, inspirada nas funcionalidades do Bibliometrix/Biblioshiny, mas com interface mais moderna, fluxo mais intuitivo, relatórios mais visuais e possibilidade de uso online em servidor próprio.

O sistema permitirá que o usuário importe bases bibliográficas de diferentes fontes, normalize os dados, realize análises quantitativas, gere gráficos, mapas de rede, tabelas, relatórios e exportações em formatos úteis para pesquisas acadêmicas, dissertações, teses, artigos científicos e relatórios institucionais.

A plataforma será desenvolvida em Symfony, com banco de dados relacional, processamento assíncrono para tarefas pesadas, visualizações em JavaScript e possibilidade de integração com scripts externos para cálculos avançados.

---

# 2. Nome provisório do sistema

Algumas possibilidades:

* BiblioMap
* BiblioDash
* ScientiaMap
* BiblioLab
* BiblioScope
* Cienciometria Web
* ResearchMap
* BiblioVision
* MapSci
* BiblioNexus

Nome sugerido para o projeto:

**BiblioMap Symfony**

Subtítulo:

**Plataforma web para análise bibliométrica, cientométrica e mapeamento da produção científica**

---

# 3. Objetivo principal

Criar um sistema web capaz de:

1. Importar dados bibliográficos de bases científicas.
2. Normalizar autores, instituições, países, fontes, palavras-chave e referências.
3. Gerar indicadores bibliométricos.
4. Criar gráficos estatísticos.
5. Construir redes bibliométricas.
6. Gerar mapas temáticos e estruturas de conhecimento.
7. Permitir filtros avançados.
8. Exportar tabelas, gráficos, redes e relatórios.
9. Manter histórico de projetos de análise.
10. Permitir uso por pesquisadores, grupos de pesquisa, universidades e instituições.

---

# 4. Escopo geral

## 4.1. O sistema terá

* Área de login.
* Gestão de usuários.
* Gestão de projetos bibliométricos.
* Upload de arquivos bibliográficos.
* Importação de múltiplas bases.
* Normalização automática e manual dos dados.
* Painel de indicadores.
* Relatórios por autores.
* Relatórios por documentos.
* Relatórios por fontes/periódicos.
* Relatórios por países.
* Relatórios por instituições.
* Relatórios por palavras-chave.
* Relatórios de citações.
* Relatórios de colaboração.
* Redes bibliométricas.
* Mapas temáticos.
* Exportação para Excel, CSV, PDF, PNG, SVG e JSON.
* Histórico das análises.
* Reprocessamento de dados.
* Controle de versões dos datasets importados.

## 4.2. O sistema não deverá tentar copiar diretamente o código do Bibliometrix

A proposta será desenvolver uma solução própria, inspirada nas funcionalidades bibliométricas conhecidas, mas com arquitetura, interface e implementação independentes.

---

# 5. Tecnologias recomendadas

## 5.1. Backend

* PHP 8.3 ou superior.
* Symfony 7.x.
* Doctrine ORM.
* Symfony Messenger.
* Symfony Scheduler ou cron.
* Symfony Security.
* Symfony UX, se desejado.
* API Platform, opcional.
* Monolog.
* Validator.
* Serializer.
* EasyAdmin, opcional para área administrativa interna.

## 5.2. Banco de dados

Opção recomendada:

* PostgreSQL.

Opção mais compatível com cPanel/DirectAdmin:

* MySQL ou MariaDB.

Observação importante:

Para análises de rede e consultas complexas, PostgreSQL tende a ser melhor, principalmente com JSONB, índices avançados e consultas analíticas. Porém, em hospedagem tradicional com cPanel/DirectAdmin, MySQL/MariaDB costuma ser mais disponível.

## 5.3. Frontend

* Twig.
* Bootstrap 5 ou Tailwind CSS.
* Stimulus.
* Chart.js.
* Apache ECharts.
* D3.js.
* Cytoscape.js.
* Sigma.js.
* vis-network.
* DataTables.
* Tom Select ou Select2.
* HTMX, opcional.

## 5.4. Processamento pesado

O sistema poderá ter três estratégias:

### Estratégia A — PHP puro

Mais simples para hospedagem comum.

Usar PHP para:

* Importar arquivos.
* Normalizar dados.
* Calcular estatísticas.
* Gerar matrizes simples.
* Criar redes simples.
* Exportar relatórios.

Vantagem:

* Funciona melhor em cPanel/DirectAdmin.

Desvantagem:

* Pode ficar pesado para bases grandes.
* Algumas análises científicas complexas exigirão mais desenvolvimento.

### Estratégia B — Symfony + Python

Recomendada para uma plataforma robusta.

Usar Symfony para interface e gestão, e Python para cálculos pesados.

Python faria:

* Matriz de coocorrência.
* Matriz de cocitação.
* Agrupamentos.
* Detecção de comunidades.
* Cálculo de centralidade.
* Redução dimensional.
* Clusterização.
* Tratamento de redes grandes.

Bibliotecas possíveis:

* pandas.
* numpy.
* scipy.
* networkx.
* scikit-learn.
* python-louvain.
* matplotlib, se precisar gerar imagens.
* pyvis, se quiser redes HTML.

### Estratégia C — Symfony + microserviço estatístico

Mais profissional.

Arquitetura:

* Symfony como aplicação principal.
* Worker Python separado.
* Fila de jobs.
* Comunicação por banco, Redis ou API interna.

Essa opção é mais escalável.

---

# 6. Arquitetura geral

## 6.1. Estrutura principal

O sistema será organizado assim:

```text
Usuário
  ↓
Interface Symfony
  ↓
Upload de base bibliográfica
  ↓
Importador
  ↓
Normalizador
  ↓
Banco de dados
  ↓
Motor de análise
  ↓
Geração de indicadores
  ↓
Geração de gráficos e redes
  ↓
Relatórios e exportações
```

## 6.2. Fluxo de uso

1. O usuário cria um novo projeto.
2. Informa título, descrição, área de pesquisa e objetivo da análise.
3. Faz upload de um ou mais arquivos.
4. Seleciona a fonte dos dados: Scopus, Web of Science, Lens, PubMed etc.
5. O sistema identifica ou confirma o formato.
6. O sistema importa os registros.
7. O sistema mostra uma prévia dos dados.
8. O usuário confirma a importação.
9. O sistema normaliza os dados.
10. O sistema detecta duplicidades.
11. O usuário revisa possíveis duplicidades.
12. O sistema gera os indicadores.
13. O usuário acessa dashboards, gráficos, redes e relatórios.
14. O usuário exporta os resultados.

---

# 7. Perfis de usuário

## 7.1. Administrador geral

Pode:

* Gerenciar todos os usuários.
* Gerenciar todos os projetos.
* Ver estatísticas globais.
* Configurar limites de upload.
* Gerenciar formatos de importação.
* Acompanhar logs de erro.
* Reprocessar análises.

## 7.2. Pesquisador

Pode:

* Criar projetos.
* Importar bases.
* Editar seus próprios projetos.
* Gerar relatórios.
* Exportar dados.
* Compartilhar projetos.

## 7.3. Orientador/coordenador

Pode:

* Ver projetos compartilhados.
* Comentar análises.
* Comparar datasets.
* Exportar relatórios.

## 7.4. Visitante público

Opcional.

Pode:

* Visualizar dashboards públicos.
* Acessar relatórios publicados.
* Baixar arquivos liberados pelo dono do projeto.

---

# 8. Módulos do sistema

## 8.1. Módulo de autenticação

Funcionalidades:

* Login.
* Logout.
* Recuperação de senha.
* Cadastro de usuário.
* Verificação de e-mail.
* Controle de permissões.
* Perfil do usuário.
* Preferências de idioma.
* Preferências visuais.

Campos principais do usuário:

* Nome.
* E-mail.
* Senha.
* Instituição.
* País.
* Área de pesquisa.
* Tipo de usuário.
* Data de cadastro.
* Último acesso.
* Status.

---

## 8.2. Módulo de projetos bibliométricos

Cada análise será organizada dentro de um projeto.

Campos do projeto:

* Título.
* Slug.
* Descrição.
* Pergunta de pesquisa.
* Objetivo geral.
* Base principal.
* Período analisado.
* Área do conhecimento.
* Palavras-chave da busca.
* String de busca.
* Observações metodológicas.
* Status.
* Visibilidade.
* Usuário responsável.
* Data de criação.
* Data de atualização.

Status possíveis:

* Rascunho.
* Aguardando importação.
* Importando.
* Importado.
* Normalizando.
* Pronto para análise.
* Processando relatórios.
* Concluído.
* Erro.
* Arquivado.

---

## 8.3. Módulo de importação de dados

Este será um dos módulos mais importantes.

O sistema deverá importar arquivos de diferentes fontes.

## 8.3.1. Formatos suportados na primeira versão

Prioridade alta:

* CSV.
* TXT.
* BibTeX.
* RIS.
* XLSX.
* JSON.

Prioridade média:

* XML.
* EndNote.
* Medline.
* PubMed XML.

Prioridade futura:

* Importação via API.
* Importação direta de DOI.
* Importação por consulta no OpenAlex.
* Importação por Crossref.
* Importação por PubMed API.
* Importação por Semantic Scholar API.

---

## 8.3.2. Bases suportadas

Prioridade inicial:

* Scopus.
* Web of Science.
* Lens.org.
* PubMed.
* OpenAlex.
* Crossref.

Prioridade futura:

* Dimensions.
* Cochrane.
* SciELO.
* Semantic Scholar.
* Google Scholar, com cuidado, pois não há API oficial simples.
* Zotero export.
* Mendeley export.

---

## 8.3.3. Detecção automática de fonte

O sistema poderá tentar detectar a fonte com base nos cabeçalhos do arquivo.

Exemplos:

### Scopus CSV

Campos comuns:

* Authors.
* Author full names.
* Title.
* Year.
* Source title.
* Volume.
* Issue.
* Art. No.
* Page start.
* Page end.
* Page count.
* Cited by.
* DOI.
* Link.
* Affiliations.
* Authors with affiliations.
* Abstract.
* Author Keywords.
* Index Keywords.
* References.
* Document Type.
* Publication Stage.
* Open Access.
* Source.
* EID.

### Web of Science

Campos comuns:

* AU.
* AF.
* TI.
* SO.
* LA.
* DT.
* DE.
* ID.
* AB.
* C1.
* RP.
* EM.
* RI.
* OI.
* FU.
* FX.
* CR.
* NR.
* TC.
* Z9.
* U1.
* U2.
* PY.
* VL.
* IS.
* BP.
* EP.
* DI.
* PG.
* WC.
* SC.
* UT.

### Lens.org

Campos comuns:

* Lens ID.
* Title.
* Publication Year.
* Publication Type.
* Source Title.
* ISSN.
* Publisher.
* Authors.
* Author/s Institution.
* Abstract.
* Fields of Study.
* Keywords.
* References.
* Scholarly Citations.
* DOI.
* PMID.
* PMCID.
* Patent Citations.
* Open Access.

### PubMed

Campos comuns:

* PMID.
* Title.
* Authors.
* Journal.
* Publication Date.
* Abstract.
* DOI.
* MeSH Terms.
* Publication Types.
* Affiliation.
* Language.

---

## 8.3.4. Mapeamento para formato interno

Independentemente da origem, o sistema deverá converter todos os dados para um modelo interno unificado.

Modelo interno de documento:

* ID interno.
* ID externo.
* Fonte da base.
* Título.
* Subtítulo.
* Resumo.
* Ano.
* Data de publicação.
* Tipo de documento.
* DOI.
* PMID.
* ISBN.
* ISSN.
* URL.
* Idioma.
* Nome da fonte/periódico.
* Volume.
* Número.
* Páginas.
* Editora.
* Número de citações globais.
* Número de citações locais.
* Palavras-chave dos autores.
* Palavras-chave indexadas.
* Termos controlados.
* Referências citadas.
* Afiliações.
* Países.
* Área do conhecimento.
* Status de acesso aberto.
* Texto bruto importado.
* Hash de deduplicação.

---

# 9. Normalização dos dados

## 9.1. Normalização de autores

Problema:

O mesmo autor pode aparecer de várias formas:

```text
Silva, J.
Silva, João
João Silva
J. Silva
Silva J
```

O sistema deverá:

* Separar autores.
* Padronizar caixa.
* Remover espaços extras.
* Identificar sobrenome.
* Identificar iniciais.
* Associar ORCID, quando disponível.
* Sugerir possíveis autores duplicados.
* Permitir mesclagem manual.

Tabela de autores normalizados:

* Nome original.
* Nome normalizado.
* Sobrenome.
* Iniciais.
* ORCID.
* Variações encontradas.
* Confiança da normalização.

Funcionalidades:

* Tela para revisar autores parecidos.
* Botão “mesclar autores”.
* Botão “separar autores”.
* Cadastro de equivalências.
* Dicionário de normalização por projeto.

---

## 9.2. Normalização de instituições

Problema:

A mesma instituição pode aparecer como:

```text
Universidade Federal de São Carlos
UFSCar
Federal University of São Carlos
Univ Fed Sao Carlos
```

O sistema deverá:

* Criar cadastro de instituições.
* Identificar variações.
* Normalizar siglas.
* Relacionar instituição ao país.
* Permitir correção manual.

Campos:

* Nome original.
* Nome normalizado.
* Sigla.
* País.
* Cidade.
* Identificador externo, se houver.
* Variações.

---

## 9.3. Normalização de países

O sistema deverá:

* Identificar países nas afiliações.
* Padronizar nomes em português e inglês.
* Usar código ISO.
* Permitir correção manual.

Exemplo:

```text
Brazil
Brasil
BRA
BR
```

Resultado:

```text
Brasil — BR
```

---

## 9.4. Normalização de palavras-chave

O sistema deverá:

* Separar palavras-chave por delimitadores.
* Remover espaços duplicados.
* Padronizar caixa.
* Remover pontuação excessiva.
* Unificar singular/plural, quando configurado.
* Permitir dicionário de sinônimos.
* Permitir tradução manual.
* Permitir junção de termos equivalentes.

Exemplo:

```text
Artificial Intelligence
AI
A.I.
Inteligência Artificial
```

Pode ser normalizado como:

```text
Artificial Intelligence
```

ou, em português:

```text
Inteligência Artificial
```

---

## 9.5. Normalização de referências

As referências são fundamentais para cocitação, acoplamento bibliográfico e historiografia.

O sistema deverá tentar extrair:

* Autor principal.
* Ano.
* Título.
* Fonte.
* DOI.
* Volume.
* Páginas.
* String original da referência.

Exemplo de referência:

```text
Aria M, Cuccurullo C, 2017, J INFORMETR, V11, P959
```

Campos internos:

* Autor citado.
* Ano citado.
* Título citado.
* Fonte citada.
* DOI citado.
* Referência original.
* Hash de referência.

---

# 10. Deduplicação

O sistema deverá detectar documentos duplicados.

## 10.1. Critérios de duplicidade

Prioridade:

1. DOI igual.
2. PMID igual.
3. Título muito parecido + ano igual.
4. Título muito parecido + autores parecidos.
5. Hash normalizado igual.
6. Fonte + volume + páginas iguais.

## 10.2. Tela de revisão

O usuário deverá ver:

* Documento A.
* Documento B.
* Percentual de similaridade.
* Campos divergentes.
* Botão “mesclar”.
* Botão “manter separados”.
* Botão “ignorar sugestão”.

## 10.3. Mesclagem

Ao mesclar registros, o sistema deverá:

* Manter os campos mais completos.
* Somar ou escolher citações conforme origem.
* Preservar os IDs externos.
* Registrar histórico da mesclagem.
* Permitir desfazer.

---

# 11. Estrutura de banco de dados

## 11.1. Entidades principais

### User

* id
* name
* email
* password
* institution
* country
* roles
* status
* createdAt
* updatedAt

### BibliometricProject

* id
* user
* title
* slug
* description
* researchQuestion
* objective
* searchString
* databaseSources
* startYear
* endYear
* status
* visibility
* createdAt
* updatedAt

### Dataset

* id
* project
* name
* source
* originalFilename
* filePath
* fileFormat
* recordsCount
* importedCount
* duplicatedCount
* status
* createdAt

### ImportedRecord

* id
* dataset
* rawData
* source
* externalId
* importStatus
* errorMessage
* createdAt

### Document

* id
* project
* title
* normalizedTitle
* abstractText
* year
* publicationDate
* documentType
* doi
* pmid
* isbn
* issn
* sourceTitle
* volume
* issue
* pages
* publisher
* language
* url
* citedBy
* globalCitations
* localCitations
* openAccessStatus
* hash
* createdAt
* updatedAt

### Author

* id
* name
* normalizedName
* surname
* initials
* orcid
* createdAt

### DocumentAuthor

* id
* document
* author
* position
* correspondingAuthor
* originalName

### Source

* id
* title
* normalizedTitle
* issn
* eissn
* publisher
* sourceType

### Keyword

* id
* term
* normalizedTerm
* language
* type

### DocumentKeyword

* id
* document
* keyword
* type
* originalTerm

### Institution

* id
* name
* normalizedName
* acronym
* country
* city

### DocumentInstitution

* id
* document
* institution
* originalAffiliation

### Country

* id
* namePt
* nameEn
* iso2
* iso3

### Reference

* id
* document
* rawReference
* citedAuthor
* citedYear
* citedTitle
* citedSource
* citedDoi
* hash

### CitationRelation

* id
* citingDocument
* citedDocument
* relationType

### AnalysisJob

* id
* project
* type
* status
* progress
* startedAt
* finishedAt
* errorMessage

### AnalysisResult

* id
* project
* analysisType
* parameters
* resultData
* chartData
* createdAt

### NetworkNode

* id
* project
* networkType
* nodeKey
* label
* groupName
* weight
* metadata

### NetworkEdge

* id
* project
* networkType
* sourceNode
* targetNode
* weight
* metadata

---

# 12. Importadores

## 12.1. Interface de importação

Criar uma interface comum:

```php
interface BibliographicImporterInterface
{
    public function supports(string $format, ?string $source = null): bool;

    public function parse(string $filePath): ImportResult;

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO;
}
```

## 12.2. Importadores específicos

Criar classes:

```text
ScopusCsvImporter
ScopusBibtexImporter
WebOfScienceTxtImporter
WebOfScienceBibtexImporter
LensCsvImporter
PubMedCsvImporter
PubMedXmlImporter
RisImporter
BibtexImporter
OpenAlexJsonImporter
CrossrefJsonImporter
GenericCsvImporter
```

## 12.3. Resultado da importação

O importador deverá retornar:

* Total de registros lidos.
* Total de registros válidos.
* Total de registros com erro.
* Lista de erros.
* Amostra dos dados.
* Mapeamento detectado.
* Fonte detectada.
* Formato detectado.

---

# 13. Dashboard principal

O dashboard do projeto deverá apresentar:

## 13.1. Indicadores principais

Cards:

* Total de documentos.
* Período analisado.
* Total de autores.
* Total de fontes/periódicos.
* Total de palavras-chave.
* Total de instituições.
* Total de países.
* Total de referências.
* Média de citações por documento.
* Taxa de colaboração internacional.
* Documentos de acesso aberto.
* Ano com maior produção.
* Autor mais produtivo.
* Fonte mais frequente.
* Palavra-chave mais frequente.

## 13.2. Gráficos principais

* Publicações por ano.
* Citações por ano.
* Tipos de documentos.
* Top autores.
* Top fontes.
* Top países.
* Top instituições.
* Top palavras-chave.
* Evolução das palavras-chave.
* Produção acumulada.
* Relação entre publicações e citações.

## 13.3. Filtros globais

* Ano inicial.
* Ano final.
* Tipo de documento.
* Fonte/base.
* País.
* Instituição.
* Autor.
* Palavra-chave.
* Periódico.
* Idioma.
* Acesso aberto.
* Mínimo de citações.

---

# 14. Relatórios bibliométricos

## 14.1. Relatório geral

Deverá conter:

* Informações do projeto.
* String de busca.
* Bases importadas.
* Total de registros brutos.
* Total após deduplicação.
* Período de análise.
* Produção anual.
* Crescimento anual.
* Média de citações.
* Principais autores.
* Principais fontes.
* Principais países.
* Principais palavras-chave.
* Principais documentos.
* Principais instituições.

---

## 14.2. Relatório de produção científica anual

Indicadores:

* Número de publicações por ano.
* Crescimento percentual por ano.
* Crescimento médio anual.
* Anos de maior produção.
* Anos de menor produção.
* Tendência de crescimento.

Gráficos:

* Linha temporal.
* Barras por ano.
* Produção acumulada.
* Média móvel.

Tabela:

* Ano.
* Número de documentos.
* Percentual do total.
* Citações recebidas.
* Média de citações por documento.

---

## 14.3. Relatório de fontes/periódicos

Indicadores:

* Fontes mais relevantes.
* Fontes mais citadas.
* Fontes com maior média de citações.
* Distribuição por tipo de fonte.
* Lei de Bradford.

Gráficos:

* Top fontes por número de documentos.
* Top fontes por citações.
* Mapa de Bradford.
* Produção de fontes ao longo do tempo.

Tabela:

* Fonte.
* ISSN.
* Documentos.
* Citações.
* Média de citações.
* Primeiro ano.
* Último ano.
* Percentual do total.

---

## 14.4. Relatório de autores

Indicadores:

* Autores mais produtivos.
* Autores mais citados.
* Autores com maior média de citações.
* Autores com maior colaboração.
* Autores com maior centralidade na rede.
* Produção dos autores ao longo do tempo.
* Lei de Lotka.

Gráficos:

* Top autores por documentos.
* Top autores por citações.
* Produção temporal dos autores.
* Rede de coautoria.
* Distribuição de produtividade.

Tabela:

* Autor.
* Documentos.
* Citações.
* Média de citações.
* H-index dentro da base.
* G-index dentro da base.
* M-index dentro da base.
* Primeiro ano.
* Último ano.
* Instituições relacionadas.

---

## 14.5. Relatório de documentos

Indicadores:

* Documentos mais citados globalmente.
* Documentos mais citados localmente.
* Documentos mais recentes.
* Documentos mais relevantes por palavras-chave.
* Documentos centrais na rede de citações.

Gráficos:

* Top documentos por citações.
* Distribuição de citações.
* Citações por ano.
* Historiografia de citações.

Tabela:

* Título.
* Autores.
* Ano.
* Fonte.
* DOI.
* Citações globais.
* Citações locais.
* Tipo de documento.
* Palavras-chave.

---

## 14.6. Relatório de palavras-chave

Indicadores:

* Palavras-chave mais frequentes.
* Palavras-chave dos autores.
* Palavras-chave indexadas.
* Crescimento de termos ao longo do tempo.
* Termos emergentes.
* Termos em declínio.
* Coocorrência de palavras-chave.

Gráficos:

* Barras de frequência.
* Nuvem de palavras.
* Evolução temporal.
* Rede de coocorrência.
* Mapa temático.
* Dendrograma de termos.
* Mapa de calor termo × ano.

Tabela:

* Palavra-chave.
* Frequência.
* Documentos relacionados.
* Primeiro ano.
* Último ano.
* Tendência.
* Cluster.

---

## 14.7. Relatório de países

Indicadores:

* Países mais produtivos.
* Países mais citados.
* Colaboração entre países.
* Produção nacional e internacional.
* MCP/SCP, quando aplicável:

  * Single Country Publications.
  * Multiple Country Publications.

Gráficos:

* Mapa mundial.
* Barras por país.
* Rede de colaboração entre países.
* Produção por continente.
* Evolução temporal por país.

Tabela:

* País.
* Documentos.
* Citações.
* Média de citações.
* Colaborações internacionais.
* Percentual do total.

---

## 14.8. Relatório de instituições

Indicadores:

* Instituições mais produtivas.
* Instituições mais citadas.
* Instituições com maior colaboração.
* Redes de colaboração institucional.

Gráficos:

* Top instituições.
* Rede de colaboração.
* Mapa geográfico, se houver país/cidade.
* Evolução temporal.

Tabela:

* Instituição.
* País.
* Documentos.
* Citações.
* Autores relacionados.
* Colaborações.

---

## 14.9. Relatório de citações

Indicadores:

* Citações globais.
* Citações locais.
* Documentos mais citados.
* Autores mais citados.
* Fontes mais citadas.
* Referências mais citadas.

Gráficos:

* Top documentos citados.
* Top referências citadas.
* Rede de cocitação.
* Historiografia.
* Distribuição de citações.

Tabela:

* Documento.
* Ano.
* Citações globais.
* Citações locais.
* Referências citadas.
* Documentos citantes.

---

## 14.10. Relatório de colaboração

Indicadores:

* Coautoria.
* Colaboração entre autores.
* Colaboração entre instituições.
* Colaboração entre países.
* Densidade da rede.
* Grau médio.
* Componentes da rede.
* Autores isolados.
* Grupos de colaboração.

Gráficos:

* Rede de coautoria.
* Rede institucional.
* Rede internacional.
* Mapa de comunidades.
* Grafo filtrável.

Tabela:

* Nó.
* Tipo.
* Grau.
* Centralidade.
* Cluster.
* Peso.

---

# 15. Redes bibliométricas

## 15.1. Tipos de rede

O sistema deverá gerar:

1. Rede de coautoria.
2. Rede de colaboração entre instituições.
3. Rede de colaboração entre países.
4. Rede de coocorrência de palavras-chave.
5. Rede de cocitação de autores.
6. Rede de cocitação de documentos.
7. Rede de cocitação de fontes.
8. Rede de acoplamento bibliográfico.
9. Rede de citações diretas.
10. Rede historiográfica.
11. Rede de termos do resumo.
12. Rede de termos do título.
13. Rede temática.

---

## 15.2. Métricas de rede

Para cada rede, calcular:

* Grau.
* Grau ponderado.
* Centralidade de intermediação.
* Centralidade de proximidade.
* Centralidade de autovetor.
* PageRank.
* Modularidade.
* Comunidade/cluster.
* Densidade.
* Diâmetro.
* Número de componentes.
* Tamanho do componente principal.
* Coeficiente de agrupamento.

---

## 15.3. Visualização de redes

Recursos visuais:

* Zoom.
* Arrastar nós.
* Filtro por peso mínimo.
* Filtro por frequência mínima.
* Filtro por cluster.
* Busca por nó.
* Destaque de vizinhos.
* Tamanho do nó por frequência.
* Cor do nó por cluster.
* Espessura da aresta por força da relação.
* Legenda.
* Exportação em PNG.
* Exportação em SVG.
* Exportação em GraphML.
* Exportação em GEXF.
* Exportação em JSON.

Bibliotecas possíveis:

* Cytoscape.js.
* Sigma.js.
* vis-network.
* D3.js.

Recomendação:

* Usar **Cytoscape.js** para a primeira versão.
* Permitir exportação para Gephi em GEXF ou GraphML.

---

# 16. Estruturas de conhecimento

O sistema deverá organizar as análises em três grandes dimensões:

## 16.1. Estrutura conceitual

Foco:

* O que o campo pesquisa.
* Quais temas aparecem.
* Quais palavras-chave se agrupam.
* Quais tópicos estão emergindo.

Análises:

* Coocorrência de palavras-chave.
* Mapa temático.
* Evolução temática.
* Análise de termos de títulos.
* Análise de termos de resumos.
* Dendrograma de termos.
* Clusterização conceitual.

---

## 16.2. Estrutura intelectual

Foco:

* Quais autores, documentos e fontes fundamentam o campo.
* Quais referências são centrais.
* Quais escolas teóricas aparecem.

Análises:

* Cocitação de autores.
* Cocitação de documentos.
* Cocitação de fontes.
* Referências mais citadas.
* Historiografia.
* Citações locais.

---

## 16.3. Estrutura social

Foco:

* Como autores, instituições e países colaboram.

Análises:

* Coautoria.
* Colaboração institucional.
* Colaboração internacional.
* Redes por país.
* Redes por grupo de pesquisa.

---

# 17. Mapas temáticos

## 17.1. Objetivo

Criar mapas que classifiquem temas conforme densidade e centralidade.

Quadrantes:

1. Temas motores.
2. Temas básicos.
3. Temas emergentes ou em declínio.
4. Temas altamente desenvolvidos e isolados.

## 17.2. Dados necessários

* Palavras-chave.
* Frequência.
* Coocorrência.
* Clusters.
* Centralidade.
* Densidade.

## 17.3. Visualização

Gráfico de bolhas:

* Eixo X: centralidade.
* Eixo Y: densidade.
* Tamanho da bolha: frequência.
* Cor: cluster.
* Rótulo: principal termo do cluster.

## 17.4. Tela

A tela deverá permitir:

* Escolher campo de análise:

  * Author Keywords.
  * Keywords Plus.
  * Título.
  * Resumo.
  * Todos os termos.
* Definir número mínimo de ocorrências.
* Escolher algoritmo de cluster.
* Selecionar período.
* Exportar imagem.
* Exportar tabela dos clusters.

---

# 18. Evolução temática

## 18.1. Objetivo

Mostrar como os temas mudam ao longo do tempo.

## 18.2. Funcionamento

O usuário poderá dividir o período em fatias temporais.

Exemplo:

```text
2010–2015
2016–2020
2021–2026
```

Para cada período, o sistema calculará:

* Termos mais frequentes.
* Clusters temáticos.
* Relações entre clusters de períodos diferentes.

## 18.3. Visualizações

* Sankey diagram.
* Linha temporal de temas.
* Heatmap termo × período.
* Tabela de evolução.

Bibliotecas:

* ECharts Sankey.
* D3 Sankey.
* Plotly, se adotado.
* Chart.js matrix, se necessário.

---

# 19. Historiografia

## 19.1. Objetivo

Mostrar a evolução das citações dentro do corpus.

## 19.2. Funcionamento

A partir das referências citadas e documentos presentes na própria base, o sistema deverá identificar:

* Documento citante.
* Documento citado.
* Ano.
* Relação direta de citação.
* Caminhos principais.

## 19.3. Visualização

* Linha do tempo.
* Rede orientada.
* Documento mais antigo à esquerda.
* Documento mais recente à direita.
* Setas indicando citações.
* Tamanho do nó por citações locais.
* Cor por cluster.

---

# 20. Análises estatísticas específicas

## 20.1. Lei de Bradford

Aplicação:

* Identificar núcleo de periódicos mais produtivos.

Saída:

* Zonas de Bradford.
* Periódicos por zona.
* Quantidade de documentos por zona.
* Gráfico de dispersão ou barras.

## 20.2. Lei de Lotka

Aplicação:

* Distribuição da produtividade dos autores.

Saída:

* Número de autores com 1 publicação.
* Número de autores com 2 publicações.
* Número de autores com 3 ou mais.
* Ajuste esperado pela Lei de Lotka.
* Gráfico comparativo.

## 20.3. Lei de Zipf

Aplicação:

* Frequência de palavras nos títulos, resumos e palavras-chave.

Saída:

* Termos mais frequentes.
* Distribuição rank-frequência.
* Gráfico log-log.

---

# 21. Indicadores bibliométricos

## 21.1. Indicadores de produção

* Total de documentos.
* Documentos por ano.
* Documentos por autor.
* Documentos por fonte.
* Documentos por país.
* Documentos por instituição.
* Taxa de crescimento anual.
* Produção acumulada.

## 21.2. Indicadores de impacto

* Total de citações.
* Média de citações por documento.
* Mediana de citações.
* Documentos sem citação.
* Documentos altamente citados.
* H-index do corpus.
* H-index por autor dentro do corpus.
* G-index.
* M-index.
* Citações locais.
* Citações globais.

## 21.3. Indicadores de colaboração

* Número médio de autores por documento.
* Documentos de autoria única.
* Documentos em coautoria.
* Índice de colaboração.
* Colaboração internacional.
* Colaboração institucional.
* Rede de coautoria.

## 21.4. Indicadores temáticos

* Frequência de palavras-chave.
* Termos emergentes.
* Termos em declínio.
* Clusters temáticos.
* Centralidade dos temas.
* Densidade dos temas.

---

# 22. Interface visual

## 22.1. Direção visual

A interface deverá ser mais moderna e agradável que o Biblioshiny.

Sugestão visual:

* Layout limpo.
* Menu lateral.
* Cards com indicadores.
* Gráficos grandes.
* Abas por tipo de análise.
* Filtros fixos no topo.
* Tabelas interativas.
* Redes em tela cheia.
* Modo claro e modo escuro.
* Exportações visíveis.

## 22.2. Estrutura do painel

Menu lateral:

```text
Dashboard
Importações
Documentos
Autores
Fontes
Palavras-chave
Instituições
Países
Citações
Redes
Mapas temáticos
Evolução temática
Relatórios
Exportações
Configurações
```

## 22.3. Tela inicial do projeto

Componentes:

* Nome do projeto.
* Período da base.
* Total de documentos.
* Status da análise.
* Última importação.
* Botão “nova importação”.
* Botão “processar análises”.
* Botão “exportar relatório”.

---

# 23. Exportações

## 23.1. Exportação de dados

Formatos:

* CSV.
* XLSX.
* JSON.
* BibTeX.
* RIS.

## 23.2. Exportação de gráficos

Formatos:

* PNG.
* SVG.
* PDF.

## 23.3. Exportação de redes

Formatos:

* JSON.
* GEXF.
* GraphML.
* CSV de nós.
* CSV de arestas.

## 23.4. Exportação de relatório

Formatos:

* PDF.
* DOCX.
* HTML.
* Markdown.

## 23.5. Relatório automático completo

O sistema deverá gerar um relatório com:

1. Capa.
2. Dados do projeto.
3. Metodologia da busca.
4. Bases utilizadas.
5. Critérios de importação.
6. Deduplicação.
7. Indicadores gerais.
8. Produção anual.
9. Autores.
10. Fontes.
11. Documentos.
12. Países.
13. Instituições.
14. Palavras-chave.
15. Citações.
16. Redes.
17. Mapas temáticos.
18. Considerações finais.
19. Apêndice com tabelas.

---

# 24. Sistema de filtros

Todos os relatórios deverão aceitar filtros.

Filtros principais:

* Ano inicial.
* Ano final.
* Autor.
* Fonte.
* País.
* Instituição.
* Palavra-chave.
* Tipo de documento.
* Base de origem.
* Idioma.
* Citações mínimas.
* Acesso aberto.

Filtros avançados:

* Apenas documentos com DOI.
* Apenas documentos com resumo.
* Apenas documentos com referências.
* Apenas documentos com afiliação.
* Apenas documentos em colaboração internacional.
* Excluir revisões.
* Excluir conferências.
* Excluir documentos sem autor.

---

# 25. Módulo de limpeza de dados

## 25.1. Limpeza automática

O sistema deverá:

* Remover espaços duplicados.
* Corrigir encoding.
* Padronizar maiúsculas/minúsculas.
* Remover caracteres inválidos.
* Separar autores.
* Separar palavras-chave.
* Separar instituições.
* Separar países.
* Separar referências.
* Criar hashes.

## 25.2. Limpeza manual

Telas:

* Normalizar autores.
* Normalizar instituições.
* Normalizar países.
* Normalizar palavras-chave.
* Normalizar fontes.
* Revisar duplicados.
* Revisar documentos com erro.

## 25.3. Dicionários de equivalência

O usuário poderá cadastrar:

```text
AI = Artificial Intelligence
A.I. = Artificial Intelligence
GenAI = Generative Artificial Intelligence
UFSCar = Universidade Federal de São Carlos
Brazil = Brasil
```

Esses dicionários poderão ser:

* Do projeto.
* Do usuário.
* Globais do sistema.

---

# 26. Processamento assíncrono

Análises bibliométricas podem ser pesadas. Portanto, o sistema deverá usar fila.

## 26.1. Symfony Messenger

Jobs possíveis:

* ImportDatasetJob.
* NormalizeDatasetJob.
* DetectDuplicatesJob.
* CalculateGeneralIndicatorsJob.
* CalculateAuthorMetricsJob.
* CalculateSourceMetricsJob.
* CalculateKeywordMetricsJob.
* GenerateCoAuthorNetworkJob.
* GenerateKeywordNetworkJob.
* GenerateCitationNetworkJob.
* GenerateThematicMapJob.
* GenerateReportJob.

## 26.2. Controle de progresso

Cada job deverá registrar:

* Status.
* Percentual.
* Etapa atual.
* Tempo iniciado.
* Tempo finalizado.
* Erros.
* Logs.

## 26.3. Interface de progresso

Durante o processamento, exibir:

```text
Importando registros: 68%
Normalizando autores: 42%
Calculando redes: 15%
Gerando relatório: aguardando
```

---

# 27. Performance

## 27.1. Cuidados

* Usar paginação.
* Usar índices no banco.
* Evitar carregar datasets inteiros em memória.
* Processar arquivos em stream.
* Usar cache.
* Salvar resultados pré-calculados.
* Separar tabelas de dados brutos e dados normalizados.
* Usar jobs assíncronos.
* Usar agregações no banco quando possível.

## 27.2. Índices importantes

Criar índices em:

* document.project_id.
* document.year.
* document.doi.
* document.hash.
* author.normalized_name.
* keyword.normalized_term.
* source.normalized_title.
* reference.hash.
* document_author.document_id.
* document_author.author_id.
* document_keyword.document_id.
* document_keyword.keyword_id.

## 27.3. Limites por plano

Se o sistema for SaaS, criar limites:

Plano gratuito:

* 1 projeto.
* Até 500 documentos.
* Exportação limitada.

Plano pesquisador:

* 10 projetos.
* Até 10.000 documentos.
* Exportações completas.

Plano institucional:

* Projetos ilimitados.
* Até 100.000 documentos por projeto.
* Usuários em equipe.
* Relatórios personalizados.

---

# 28. Segurança

## 28.1. Uploads

Validar:

* Extensão.
* MIME type.
* Tamanho máximo.
* Encoding.
* Conteúdo malicioso.
* Nome do arquivo.

Salvar arquivos fora da pasta pública.

## 28.2. Permissões

Garantir que:

* Um usuário não veja projeto privado de outro.
* Projetos públicos exponham apenas dados permitidos.
* Exportações respeitem permissões.
* Jobs pertençam ao usuário correto.

## 28.3. Logs

Registrar:

* Login.
* Upload.
* Importação.
* Erros de importação.
* Exportações.
* Exclusões.
* Mesclagens.
* Alterações em dicionários.

---

# 29. API interna

O sistema poderá ter API para uso futuro.

Endpoints possíveis:

```text
GET /api/projects
GET /api/projects/{id}
POST /api/projects
POST /api/projects/{id}/datasets
GET /api/projects/{id}/documents
GET /api/projects/{id}/authors
GET /api/projects/{id}/keywords
GET /api/projects/{id}/indicators
GET /api/projects/{id}/networks/coauthorship
GET /api/projects/{id}/networks/keywords
GET /api/projects/{id}/reports/general
```

---

# 30. Telas do sistema

## 30.1. Tela de login

* E-mail.
* Senha.
* Recuperar senha.
* Criar conta.

## 30.2. Tela de projetos

* Lista de projetos.
* Status.
* Total de documentos.
* Última atualização.
* Botões de ação.

## 30.3. Tela de criação de projeto

Campos:

* Título.
* Descrição.
* Pergunta de pesquisa.
* String de busca.
* Bases utilizadas.
* Período.
* Visibilidade.

## 30.4. Tela de importação

Passos:

1. Upload do arquivo.
2. Detecção do formato.
3. Mapeamento dos campos.
4. Prévia dos dados.
5. Confirmação.
6. Processamento.

## 30.5. Tela de revisão de dados

Abas:

* Documentos.
* Autores.
* Palavras-chave.
* Instituições.
* Países.
* Duplicados.
* Erros.

## 30.6. Tela de dashboard

* Indicadores.
* Gráficos principais.
* Filtros.

## 30.7. Tela de documentos

* Tabela filtrável.
* Busca por título.
* Filtros.
* Detalhe do documento.

## 30.8. Tela de autores

* Ranking.
* Produção temporal.
* Rede de coautoria.
* Detalhe do autor.

## 30.9. Tela de fontes

* Ranking de periódicos.
* Bradford.
* Produção temporal.

## 30.10. Tela de palavras-chave

* Frequências.
* Nuvem.
* Coocorrência.
* Evolução temporal.

## 30.11. Tela de redes

* Seleção de tipo de rede.
* Filtros.
* Grafo interativo.
* Métricas de rede.
* Exportação.

## 30.12. Tela de mapa temático

* Parâmetros.
* Gráfico de bolhas.
* Clusters.
* Tabela.

## 30.13. Tela de evolução temática

* Períodos.
* Sankey.
* Clusters por período.

## 30.14. Tela de relatórios

* Seleção de relatório.
* Pré-visualização.
* Exportação.

---

# 31. Algoritmos principais

## 31.1. Produção anual

Agrupar documentos por ano.

Resultado:

```text
Ano | Documentos | Citações | Média de citações
```

## 31.2. Autores mais produtivos

Contar documentos por autor.

Opções:

* Contagem completa: cada autor recebe 1 ponto por documento.
* Contagem fracionada: cada autor recebe 1/n autores.

## 31.3. Coautoria

Para cada documento com vários autores:

* Criar pares de autores.
* Incrementar peso da aresta.
* Gerar rede.

Exemplo:

Documento com autores A, B, C:

```text
A-B
A-C
B-C
```

## 31.4. Coocorrência de palavras-chave

Para cada documento:

* Pegar palavras-chave.
* Criar pares de termos.
* Incrementar peso.

Exemplo:

Documento com termos X, Y, Z:

```text
X-Y
X-Z
Y-Z
```

## 31.5. Cocitação

Dois autores/documentos/fontes são cocitados quando aparecem juntos nas referências de um mesmo documento.

Para cada documento:

* Pegar lista de referências.
* Criar pares de referências/autores/fontes citadas.
* Incrementar peso.

## 31.6. Acoplamento bibliográfico

Dois documentos estão acoplados quando compartilham referências.

Para cada par de documentos:

* Comparar referências.
* Calcular quantidade de referências em comum.
* Criar aresta ponderada.

## 31.7. Citação local

Uma citação local ocorre quando um documento do próprio corpus cita outro documento também presente no corpus.

Critérios:

* DOI citado bate com DOI de documento importado.
* Título citado bate com título importado.
* Referência normalizada bate com hash de documento.

---

# 32. Relatórios visuais recomendados

## 32.1. Dashboard executivo

Para apresentação rápida:

* Cards de números principais.
* Produção por ano.
* Top autores.
* Top países.
* Top palavras-chave.
* Top documentos.
* Rede principal.

## 32.2. Relatório metodológico

Para dissertação/artigo:

* Base consultada.
* String de busca.
* Data da coleta.
* Critérios de inclusão.
* Critérios de exclusão.
* Processo de deduplicação.
* Quantidade final analisada.

## 32.3. Relatório analítico

Para discussão acadêmica:

* Evolução da área.
* Principais autores.
* Principais instituições.
* Principais países.
* Temas emergentes.
* Lacunas de pesquisa.
* Redes de colaboração.
* Estrutura intelectual.

## 32.4. Relatório comparativo

Comparar:

* Duas bases.
* Dois períodos.
* Dois temas.
* Dois países.
* Dois grupos de autores.

---

# 33. Funcionalidade especial: assistente de interpretação

O sistema poderá ter um módulo de apoio textual que ajuda o pesquisador a interpretar os resultados.

Exemplo:

Com base nos dados:

```text
O termo “Generative AI” aparece apenas a partir de 2022, com forte crescimento em 2023 e 2024.
```

O sistema poderia gerar uma sugestão:

```text
Os dados indicam que o tema “Generative AI” é recente no corpus analisado e apresenta tendência de crescimento, sugerindo uma frente emergente de pesquisa.
```

Esse módulo pode ser feito com templates inicialmente, sem IA externa.

Futuramente, poderia usar integração com IA para auxiliar na redação acadêmica.

---

# 34. Hospedagem em cPanel/DirectAdmin

## 34.1. Cenário simples

Se for hospedagem compartilhada:

* Usar PHP + MySQL.
* Evitar workers persistentes.
* Usar cron para processamentos.
* Limitar tamanho dos arquivos.
* Evitar bases muito grandes.
* Usar gráficos renderizados no navegador.

## 34.2. Cenário recomendado

Servidor VPS com DirectAdmin:

* PHP 8.3+.
* Composer.
* MySQL/MariaDB ou PostgreSQL.
* Supervisor para Symfony Messenger.
* Redis, se possível.
* Node.js para build frontend.
* Cron.
* Espaço para uploads.
* Backup automático.

## 34.3. Melhor cenário

VPS dedicado:

* Symfony.
* PostgreSQL.
* Redis.
* Supervisor.
* Worker Python.
* Nginx ou Apache.
* Storage separado para arquivos.
* Backup diário.
* Monitoramento.

---

# 35. Etapas de desenvolvimento

## Fase 1 — Fundação do projeto

Entregas:

* Criar projeto Symfony.
* Configurar autenticação.
* Criar layout base.
* Criar usuários.
* Criar projetos bibliométricos.
* Criar upload de arquivos.
* Criar estrutura inicial do banco.

Objetivo:

Ter a base administrativa funcionando.

---

## Fase 2 — Importação inicial

Entregas:

* Importador CSV genérico.
* Importador Scopus CSV.
* Importador Web of Science TXT.
* Importador Lens CSV.
* Tela de prévia.
* Mapeamento para formato interno.
* Persistência dos documentos.
* Log de importação.

Objetivo:

Conseguir importar bases reais.

---

## Fase 3 — Normalização e deduplicação

Entregas:

* Normalização de autores.
* Normalização de palavras-chave.
* Normalização de fontes.
* Normalização de países.
* Detecção de duplicados.
* Tela de revisão.
* Mesclagem manual.

Objetivo:

Limpar a base antes da análise.

---

## Fase 4 — Indicadores básicos

Entregas:

* Dashboard geral.
* Produção anual.
* Top autores.
* Top fontes.
* Top documentos.
* Top palavras-chave.
* Top países.
* Exportação CSV/XLSX.

Objetivo:

Ter os primeiros relatórios úteis.

---

## Fase 5 — Gráficos e relatórios visuais

Entregas:

* Gráficos com Chart.js/ECharts.
* Nuvem de palavras.
* Relatórios em PDF.
* Exportação de imagens.
* Filtros globais.

Objetivo:

Criar visual profissional e utilizável em dissertações e apresentações.

---

## Fase 6 — Redes bibliométricas

Entregas:

* Rede de coautoria.
* Rede de palavras-chave.
* Rede de países.
* Rede de instituições.
* Métricas básicas de rede.
* Visualização com Cytoscape.js.
* Exportação GEXF/GraphML.

Objetivo:

Aproximar o sistema das análises do Bibliometrix.

---

## Fase 7 — Citações e referências

Entregas:

* Parser de referências.
* Citações locais.
* Citações globais, quando disponíveis.
* Cocitação.
* Acoplamento bibliográfico.
* Historiografia.

Objetivo:

Permitir análises intelectuais mais avançadas.

---

## Fase 8 — Mapas temáticos e evolução temática

Entregas:

* Clusters de palavras-chave.
* Mapa temático.
* Evolução temática.
* Sankey.
* Heatmap termo × período.

Objetivo:

Oferecer análises conceituais avançadas.

---

## Fase 9 — Comparações e publicação

Entregas:

* Comparar datasets.
* Comparar períodos.
* Publicar dashboard público.
* Compartilhar projeto por link.
* Gerar relatório acadêmico completo.

Objetivo:

Transformar a ferramenta em produto utilizável por grupos de pesquisa.

---

# 36. Prioridade de desenvolvimento

## MVP — Versão mínima viável

O MVP deverá ter:

1. Login.
2. Projetos.
3. Upload de CSV.
4. Importação Scopus, WoS e Lens.
5. Normalização básica.
6. Deduplicação por DOI.
7. Dashboard geral.
8. Produção anual.
9. Top autores.
10. Top fontes.
11. Top palavras-chave.
12. Top documentos.
13. Exportação Excel.
14. Exportação PDF simples.

## Versão 2

Adicionar:

1. Redes de coautoria.
2. Redes de palavras-chave.
3. Redes de países.
4. Nuvem de palavras.
5. Citações locais.
6. Relatórios avançados.
7. Exportação de gráficos.

## Versão 3

Adicionar:

1. Cocitação.
2. Acoplamento bibliográfico.
3. Mapa temático.
4. Evolução temática.
5. Historiografia.
6. API.
7. Compartilhamento público.

---

# 37. Possível estrutura de pastas Symfony

```text
src/
  Controller/
    ProjectController.php
    DatasetController.php
    ImportController.php
    DashboardController.php
    DocumentController.php
    AuthorController.php
    SourceController.php
    KeywordController.php
    NetworkController.php
    ReportController.php

  Entity/
    User.php
    BibliometricProject.php
    Dataset.php
    ImportedRecord.php
    Document.php
    Author.php
    DocumentAuthor.php
    Source.php
    Keyword.php
    DocumentKeyword.php
    Institution.php
    Country.php
    Reference.php
    CitationRelation.php
    AnalysisJob.php
    AnalysisResult.php
    NetworkNode.php
    NetworkEdge.php

  Service/
    Import/
      BibliographicImporterInterface.php
      ImporterResolver.php
      ScopusCsvImporter.php
      WebOfScienceTxtImporter.php
      LensCsvImporter.php
      BibtexImporter.php
      RisImporter.php

    Normalize/
      AuthorNormalizer.php
      KeywordNormalizer.php
      InstitutionNormalizer.php
      SourceNormalizer.php
      CountryNormalizer.php
      ReferenceNormalizer.php

    Analysis/
      GeneralMetricsCalculator.php
      AnnualProductionCalculator.php
      AuthorMetricsCalculator.php
      SourceMetricsCalculator.php
      KeywordMetricsCalculator.php
      CitationMetricsCalculator.php

    Network/
      CoAuthorshipNetworkBuilder.php
      KeywordCoOccurrenceNetworkBuilder.php
      CountryCollaborationNetworkBuilder.php
      InstitutionCollaborationNetworkBuilder.php
      CoCitationNetworkBuilder.php
      BibliographicCouplingNetworkBuilder.php

    Report/
      PdfReportGenerator.php
      ExcelExportGenerator.php
      MarkdownReportGenerator.php

  Message/
    ImportDatasetMessage.php
    NormalizeDatasetMessage.php
    CalculateIndicatorsMessage.php
    GenerateNetworkMessage.php
    GenerateReportMessage.php

  MessageHandler/
    ImportDatasetMessageHandler.php
    NormalizeDatasetMessageHandler.php
    CalculateIndicatorsMessageHandler.php
    GenerateNetworkMessageHandler.php
    GenerateReportMessageHandler.php

templates/
  project/
  dataset/
  dashboard/
  document/
  author/
  source/
  keyword/
  network/
  report/

assets/
  controllers/
  styles/
  charts/
  networks/
```

---

# 38. Bibliotecas PHP úteis

## Importação e arquivos

* league/csv
* phpoffice/phpspreadsheet
* renanbr/bibtex-parser, ou parser próprio
* symfony/filesystem
* symfony/mime

## Exportação

* phpoffice/phpspreadsheet
* dompdf/dompdf
* knplabs/knp-snappy, se usar wkhtmltopdf
* tecnickcom/tcpdf
* mpdf/mpdf

## Processamento

* doctrine/collections
* symfony/string
* symfony/uid
* symfony/process

## Filas

* symfony/messenger
* redis, opcional
* doctrine transport, se quiser começar simples

---

# 39. Bibliotecas JavaScript úteis

## Gráficos

* Apache ECharts.
* Chart.js.
* D3.js.

## Redes

* Cytoscape.js.
* Sigma.js.
* vis-network.

## Tabelas

* DataTables.
* Tabulator.

## Interface

* Bootstrap.
* Tailwind.
* Flowbite.
* Preline.
* Stimulus.
* Hotwire Turbo.

---

# 40. Cuidados metodológicos

O sistema deverá registrar a metodologia da análise para que o pesquisador possa reproduzir os resultados.

Registrar:

* Data da coleta.
* Base pesquisada.
* String de busca.
* Filtros aplicados na base original.
* Tipo de documento incluído.
* Idiomas incluídos.
* Período analisado.
* Quantidade inicial de registros.
* Quantidade removida por duplicidade.
* Quantidade final.
* Critérios de normalização.
* Critérios de análise.

---

# 41. Diferenças em relação ao Bibliometrix/Biblioshiny

O sistema poderá se diferenciar por:

1. Interface mais moderna.
2. Sistema multiusuário.
3. Projetos salvos online.
4. Controle de histórico.
5. Relatórios em português.
6. Exportação acadêmica pronta.
7. Melhor fluxo de revisão de dados.
8. Integração com Symfony.
9. Possibilidade de publicar dashboards.
10. Dicionários personalizados de normalização.
11. Comparação entre bases.
12. Visualizações mais amigáveis para apresentações.
13. Relatórios narrativos para dissertações e artigos.

---

# 42. Riscos do projeto

## 42.1. Complexidade dos dados

Cada base exporta campos diferentes. O importador precisará ser flexível.

## 42.2. Normalização de autores

É uma das partes mais difíceis. Não deve ser totalmente automática sem revisão.

## 42.3. Referências bibliográficas

A extração de referências pode variar muito entre bases.

## 42.4. Performance

Redes grandes podem ficar pesadas.

## 42.5. Validação científica

Os cálculos precisam ser testados comparando resultados com ferramentas consolidadas.

## 42.6. Escopo muito grande

Recriar tudo de uma vez seria arriscado. O ideal é começar pelo MVP.

---

# 43. Estratégia de validação

Para validar o sistema:

1. Escolher uma base pequena do Scopus.
2. Rodar no Bibliometrix.
3. Rodar no novo sistema.
4. Comparar:

   * total de documentos;
   * autores;
   * fontes;
   * palavras-chave;
   * produção anual;
   * documentos citados;
   * redes.
5. Ajustar divergências.
6. Repetir com Web of Science.
7. Repetir com Lens.
8. Documentar diferenças metodológicas.

---

# 44. Cronograma sugerido

## Mês 1

* Estrutura Symfony.
* Login.
* Projetos.
* Upload.
* Importador CSV genérico.
* Importador Scopus.

## Mês 2

* Importador WoS.
* Importador Lens.
* Normalização básica.
* Deduplicação por DOI/título.
* Dashboard inicial.

## Mês 3

* Relatórios de autores.
* Relatórios de fontes.
* Relatórios de documentos.
* Relatórios de palavras-chave.
* Exportação Excel/PDF.

## Mês 4

* Redes de coautoria.
* Redes de palavras-chave.
* Redes de países.
* Visualização interativa.
* Exportação de redes.

## Mês 5

* Citações locais.
* Cocitação.
* Acoplamento bibliográfico.
* Historiografia inicial.

## Mês 6

* Mapa temático.
* Evolução temática.
* Relatório acadêmico completo.
* Refinamento visual.
* Validação metodológica.

---

# 45. Estimativa de esforço

## MVP

Estimativa:

* 180 a 260 horas.

Inclui:

* Projetos.
* Upload.
* Importação básica.
* Dashboard.
* Relatórios simples.
* Exportação.

## Versão intermediária

Estimativa:

* 350 a 500 horas.

Inclui:

* Normalização melhor.
* Deduplicação avançada.
* Redes.
* Relatórios visuais.
* Exportações completas.

## Versão avançada

Estimativa:

* 700 a 1.200 horas.

Inclui:

* Cocitação.
* Acoplamento.
* Mapa temático.
* Evolução temática.
* Historiografia.
* Comparação com Bibliometrix.
* Multiusuário robusto.
* Publicação de dashboards.
* Processamento assíncrono robusto.

---

# 46. Primeiro backlog prático

## Sprint 1

* Criar projeto Symfony.
* Configurar banco.
* Criar entidade User.
* Criar autenticação.
* Criar layout base.
* Criar entidade BibliometricProject.
* Criar CRUD de projetos.

## Sprint 2

* Criar Dataset.
* Upload de arquivo.
* Salvar arquivo com segurança.
* Criar tela de importação.
* Ler CSV.
* Mostrar prévia.

## Sprint 3

* Criar Document.
* Mapear Scopus CSV.
* Importar documentos.
* Criar listagem de documentos.
* Criar detalhe do documento.

## Sprint 4

* Criar Author.
* Criar DocumentAuthor.
* Separar autores.
* Normalizar autores.
* Criar ranking de autores.

## Sprint 5

* Criar Keyword.
* Criar DocumentKeyword.
* Separar palavras-chave.
* Criar ranking de palavras-chave.
* Criar gráfico de barras.

## Sprint 6

* Criar dashboard geral.
* Publicações por ano.
* Top autores.
* Top fontes.
* Top palavras-chave.
* Exportar XLSX.

---

# 47. Exemplo de menu do sistema

```text
BiblioMap

Projetos
  - Meus projetos
  - Novo projeto
  - Projetos compartilhados

Importação
  - Arquivos importados
  - Nova importação
  - Erros de importação
  - Duplicados

Análise
  - Dashboard
  - Produção anual
  - Autores
  - Fontes
  - Documentos
  - Palavras-chave
  - Países
  - Instituições

Redes
  - Coautoria
  - Coocorrência
  - Cocitação
  - Acoplamento
  - Colaboração internacional
  - Historiografia

Mapas
  - Mapa temático
  - Evolução temática
  - Mapa de calor

Relatórios
  - Relatório geral
  - Relatório metodológico
  - Relatório analítico
  - Exportações

Configurações
  - Dicionários
  - Normalização
  - Usuários
  - Preferências
```

---

# 48. Conclusão

O desenvolvimento de uma alternativa ao Bibliometrix em Symfony é tecnicamente possível, especialmente se o projeto for dividido em fases.

A primeira versão deverá focar em importação, limpeza, indicadores principais e relatórios básicos. Depois, o sistema poderá evoluir para redes bibliométricas, cocitação, acoplamento bibliográfico, mapas temáticos e evolução temática.

A recomendação técnica mais equilibrada será usar Symfony como plataforma principal, banco relacional para persistência, JavaScript para visualizações e processamento assíncrono para análises pesadas. Para uma versão mais avançada, o uso de Python como motor analítico complementar poderá tornar o sistema mais poderoso e escalável.

O maior cuidado será não tentar recriar todas as funcionalidades de uma vez. O ideal será construir um MVP validável, comparar os resultados com ferramentas consolidadas e avançar módulo por módulo.

A melhor decisão técnica, na prática, seria: **Symfony para o sistema, PHP para importações e relatórios básicos, e um worker Python opcional para as análises pesadas de rede e clusterização**. Assim você mantém compatibilidade com cPanel/DirectAdmin quando precisar, mas não trava o projeto quando ele crescer.

[1]: https://www.bibliometrix.org/vignettes/Data-Importing-and-Converting.html?utm_source=chatgpt.com "Bibliometrix: Data Importing and Converting"

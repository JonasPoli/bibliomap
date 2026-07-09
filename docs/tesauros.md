Preciso implementar no projeto Symfony BiblioMap uma camada de tesauro/vocabulário controlado para normalizar metadados bibliométricos importados de bases como Web of Science e Scopus.

Objetivo:
Criar uma estrutura que permita agrupar variações, sinônimos e formas alternativas de termos em um conceito oficial. Isso deve ser usado principalmente para palavras-chave, instituições, países/lugares e, com mais cautela, autores.

Conceito técnico:
O sistema deve seguir a lógica do SKOS de forma relacional, sem necessidade inicial de RDF. Cada conceito deve ter um termo preferido, termos alternativos, termos ocultos e relações semânticas como broader, narrower e related.

Criar as entidades Doctrine:

1. ThesaurusScheme
   Campos:

* id
* name
* slug
* type: keyword, institution, place, author, journal, area
* description
* createdAt
* updatedAt

2. ThesaurusConcept
   Campos:

* id
* scheme
* preferredLabel
* normalizedLabel
* description
* externalCode
* status
* createdAt
* updatedAt

3. ThesaurusLabel
   Campos:

* id
* concept
* label
* normalizedLabel
* language
* type: preferred, alternative, hidden
* source
* createdAt
* updatedAt

4. ThesaurusRelation
   Campos:

* id
* sourceConcept
* targetConcept
* relationType: broader, narrower, related, exact_match, close_match
* createdAt
* updatedAt

5. ThesaurusMatch ou equivalente
   Campos:

* id
* entityType
* entityId
* originalValue
* normalizedValue
* concept
* confidence
* status: automatic, pending, reviewed
* createdAt
* updatedAt

Regras:

* Separar conceito de rótulo.
* Um conceito pode ter vários labels.
* O label preferido deve ser usado nos gráficos e relatórios.
* O termo original importado nunca deve ser apagado.
* Durante a importação, normalizar strings removendo acentos, padronizando caixa, espaços e pontuação.
* Procurar o termo normalizado em ThesaurusLabel.
* Se encontrar, vincular ao ThesaurusConcept.
* Se não encontrar, criar registro pendente para revisão manual.
* Criar uma área administrativa para gerenciar conceitos, labels, relações, termos pendentes, importação CSV e mesclagem de conceitos.

Aplicações:

* Normalizar palavras-chave como AI, Artificial Intelligence e Inteligência Artificial.
* Normalizar instituições como USP, Univ Sao Paulo e University of São Paulo.
* Normalizar países como Brazil, Brasil, BR e BRA.
* Para autores, usar com cautela e prever campos como ORCID, instituição e validação manual.

Resultado esperado:
As análises bibliométricas devem usar os conceitos normalizados para gerar contagens, redes, filtros, mapas temáticos e relatórios mais consistentes, mantendo sempre o dado original para auditoria.


Deve permitir importar e exportar.
Também deve "criar um modelo" para download para ser preenchido, com alguns dados de exemplo

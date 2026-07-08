# Como cadastrar as instituições não encontradas — Projeto 5

**Arquivos analisados**

- `instituicoes_nao_encontradas_projeto_5(2).csv`
- `instituicoes (1).csv`

**Total de nomes brutos analisados:** 89  
**Total de ocorrências nesses nomes:** 126

---

## 1. O que está acontecendo

A lista de “instituições não encontradas” não contém apenas universidades. Ela mistura:

1. variações de nomes de instituições que já estão cadastradas;
2. siglas ou abreviações que ainda não estão em `instituicao_variacoes_nome`;
3. centros, laboratórios, departamentos e programas de pós-graduação;
4. hospitais e redes hospitalares;
5. empresas privadas e consultorias;
6. órgãos públicos e empresas estatais;
7. endereços físicos que foram extraídos incorretamente como se fossem instituições.

Por isso, **não é correto cadastrar todos esses nomes diretamente em `instituicoes_ensino` como Universidade**.

A regra correta é:

- se for apenas uma escrita alternativa de uma instituição já existente, cadastrar como **variação**;
- se for centro, laboratório, departamento, campus ou programa, cadastrar como **unidade interna**;
- se for empresa, hospital, consultoria, órgão público ou associação, cadastrar como **organização**;
- se for endereço, **não cadastrar**;
- se o nome estiver truncado ou genérico, deixar para **revisão manual**.

---

## 2. Atenção importante sobre o funcionamento atual do sistema

Pelo funcionamento atual da sincronização, a reconciliação de instituições considera principalmente a tabela de instituições de ensino/pesquisa e suas variações.

Isso significa que, se você cadastrar uma empresa ou hospital apenas em `organizacoes`, mas o sincronizador ainda não consultar `organizacoes`, o nome pode continuar aparecendo como “não encontrado”.

Existem duas formas de resolver:

### Opção A — solução rápida

Cadastrar também essas organizações em `instituicoes_ensino`, mas com `institution_type` correto, por exemplo:

```text
Empresa privada
Hospital / Sistema de saúde
Órgão público
Associação científica
Instituto de pesquisa
Empresa estatal
Consultoria
```

Essa opção faz o sincronismo atual parar de listar esses nomes como pendentes, mas mistura instituições acadêmicas e organizações na mesma tabela.

### Opção B — solução correta de arquitetura

Manter empresas, hospitais e órgãos públicos em `organizacoes`, criar uma tabela de vínculo documental para organizações e alterar o serviço de sincronização para também procurar em:

```text
organizacoes
organizacao_variacoes_nome
documento_organizacoes
```

Essa é a melhor solução se o objetivo for diferenciar corretamente universidades, institutos de pesquisa, empresas, hospitais, órgãos públicos e consultorias nos relatórios.

---

## 3. Resumo por ação recomendada

| grupo_acao                                       |   itens |   ocorrencias |
|:-------------------------------------------------|--------:|--------------:|
| Adicionar variação a cadastro existente          |      13 |            29 |
| Cadastrar como organização, não universidade     |      23 |            25 |
| Revisão manual                                   |       6 |            21 |
| Cadastrar como organização após revisão          |      16 |            16 |
| Não cadastrar; limpar como endereço              |       6 |             6 |
| Corrigir cadastro existente e adicionar variação |       3 |             5 |
| Cadastrar como unidade interna após revisão      |       5 |             5 |
| Cadastrar instituição/unidade de pesquisa        |       4 |             4 |
| Cadastrar como unidade interna                   |       3 |             3 |
| Cadastrar instituto/organização de pesquisa      |       1 |             3 |
| Cadastrar organização/instituto de pesquisa      |       3 |             3 |
| Cadastrar unidade vinculada                      |       2 |             2 |
| Cadastrar organização e unidade                  |       1 |             1 |
| Cadastrar organização/unidade de saúde           |       1 |             1 |
| Cadastrar órgão público                          |       1 |             1 |
| Separar valor composto                           |       1 |             1 |

---

## 4. Como cadastrar cada tipo

### 4.1. Variação de instituição existente

Use quando o nome bruto é apenas uma abreviação de uma instituição já cadastrada.

Exemplo:

```text
Univ Fed Rio de Janeiro → Universidade Federal do Rio de Janeiro
Univ Fed Campina UFCG → Universidade Federal de Campina Grande
Univ Fed Pelotas UFEPL → Universidade Federal de Pelotas
```

Cadastro recomendado:

```sql
INSERT INTO instituicao_variacoes_nome (
    institution_id,
    variation_name,
    variation_type,
    normalized_name,
    status
)
VALUES (
    <ID_DA_INSTITUICAO>,
    '<NOME_BRUTO_DO_ARQUIVO>',
    'scopus_abbreviation',
    '<NOME_NORMALIZADO>',
    1
);
```

Preferencialmente, gere `normalized_name` usando a mesma normalização do sistema, não digitando manualmente.

---

### 4.2. Unidade interna

Use para:

```text
Programa de Pós-Graduação
Departamento
Centro
Laboratório
Campus
School / Faculty
Key Laboratory
```

Exemplos:

```text
CRHEA EESC USP
Programa Posgrad Ciencias Engn Ambiental EESC USP
Programa Pos graduacao Biotecnol & Bioproc UFRJ
Shanghai Key Lab Mech Energy Engn
State Key Lab Intelligent Agr Power Equipment
```

Cadastro recomendado:

```text
instituicao_unidades
- original_variation_name
- canonical_name
- type
- parent_institution_id
- confidence
- observation
```

Se o sistema precisar que a unidade deixe de aparecer como não encontrada, cadastre também a variação no registro da instituição-mãe.

---

### 4.3. Organização, empresa, hospital ou órgão público

Use para:

```text
NHS Trusts
State Grid
AbbVie
Samsung Electronics
American Heart Association
Mass General Brigham
National Renewable Energy Laboratory
USDA ARS
Consultorias
```

Cadastro recomendado em `organizacoes`:

```text
organizacoes
- original_variation_name
- canonical_name
- type
- confidence
- observation
```

Se optar pela solução rápida, também crie um registro em `instituicoes_ensino` com `institution_type` adequado, por exemplo `Empresa privada`, `Hospital`, `Órgão público`, `Instituto de pesquisa` ou `Consultoria`.

---

### 4.4. Endereços que não devem ser cadastrados

Os itens abaixo são endereços, não instituições:

```text
30 Xueyuan Rd
12127 Old Oaks Dr
1180 Ctr Dr
5510 Nathan Shock Dr
118 Parr St
1280 Montgomery Blvd
```

Eles devem ser tratados como erro de parser/importação, não como entidade institucional.

---

## 5. Tabela completa de identificação e cadastro

| raw_institution_name                               |   occurrences | acao                                 | tipo_sugerido                                   | nome_canonico_sugerido                                                                     | observacao                                                                                                                                                                                                                      |
|:---------------------------------------------------|--------------:|:-------------------------------------|:------------------------------------------------|:-------------------------------------------------------------------------------------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Sustainable AgriFoodtech Innovat & Res SAFIR       |            14 | Revisão manual                       | provável centro/projeto/organização de pesquisa | Sustainable AgriFoodtech Innovation & Research (SAFIR)                                     | Não encontrei correspondência pública confiável pelo nome abreviado; manter em revisão manual. Como aparece 14 vezes, pode ser cadastrado provisoriamente como organização/instituto com esse nome canônico e a variação bruta. |
| Embrapa Digital Agr                                |             8 | Adicionar variação                   | unidade de pesquisa                             | Embrapa Agricultura Digital                                                                | Unidade da Embrapa em Campinas/SP. Adicionar variação ao cadastro canônico da unidade, não criar universidade.                                                                                                                  |
| Embrapa Agr Digital                                |             4 | Adicionar variação                   | unidade de pesquisa                             | Embrapa Agricultura Digital                                                                | Mesma correção de Embrapa Digital Agr.                                                                                                                                                                                          |
| Baker Heart & Diabet Inst                          |             3 | Cadastrar instituição/organização    | instituto de pesquisa médica                    | Baker Heart and Diabetes Institute                                                         | Melbourne, Victoria, Austrália; site: https://baker.edu.au. Não é universidade.                                                                                                                                                 |
| Ctr Energy & Environm Sustainabil                  |             3 | Revisão manual                       | centro/laboratório                              | Center/Centre for Energy and Environmental Sustainability                                  | Nome muito genérico; falta instituição-mãe. Não cadastrar sem validar a afiliação completa.                                                                                                                                     |
| Univ Fed Rio de Janeiro                            |             3 | Adicionar variação                   | universidade                                    | Universidade Federal do Rio de Janeiro                                                     | Já existe como UFRJ no cadastro. Adicionar exatamente essa variação se ainda estiver faltando.                                                                                                                                  |
| EMBRAPA                                            |             2 | Adicionar variação                   | instituto/empresa pública de pesquisa           | Empresa Brasileira de Pesquisa Agropecuária                                                | Já existe cadastro de Embrapa. Adicionar EMBRAPA como variação direta do cadastro canônico da empresa pública.                                                                                                                  |
| Natl Inst Ind Engn NITIE                           |             2 | Atualizar/Adicionar variação         | instituição de ensino superior                  | Indian Institute of Management Mumbai                                                      | Antigo National Institute of Industrial Engineering (NITIE), atual IIM Mumbai. Adicionar variação e, se o cadastro estiver só como NITIE, corrigir/mesclar.                                                                     |
| Int Ctr Agr Res Dry Areas ICARDA                   |             2 | Adicionar variação                   | instituto internacional de pesquisa             | International Center for Agricultural Research in the Dry Areas                            | ICARDA. Deve ser instituto de pesquisa/organização internacional, não universidade.                                                                                                                                             |
| UNSW Canberra                                      |             2 | Adicionar variação/unidade           | campus/unidade                                  | University of New South Wales                                                              | Campus Canberra da UNSW. Pode ser variação da UNSW ou unidade interna vinculada.                                                                                                                                                |
| State Grid Qinghai Elect Power Co                  |             2 | Cadastrar organização                | empresa estatal de energia                      | State Grid Qinghai Electric Power Company                                                  | Empresa/companhia de energia; não cadastrar como universidade.                                                                                                                                                                  |
| Daegu Gyeongbuk Inst Sci & Technol DGIST           |             2 | Atualizar/Adicionar variação         | universidade/instituto de ciência e tecnologia  | Daegu Gyeongbuk Institute of Science and Technology                                        | DGIST, Daegu, Coreia do Sul. Corrigir grafia duplicada existente e adicionar variação.                                                                                                                                          |
| Embrapa                                            |             2 | Adicionar variação                   | instituto/empresa pública de pesquisa           | Empresa Brasileira de Pesquisa Agropecuária                                                | Mesma regra de EMBRAPA.                                                                                                                                                                                                         |
| Guys & St Thomas NHS Fdn Trust                     |             2 | Cadastrar organização                | hospital/NHS trust                              | Guy's and St Thomas' NHS Foundation Trust                                                  | London, Reino Unido. Organização hospitalar, não universidade.                                                                                                                                                                  |
| Inst Nacl Invest Agr INIA                          |             1 | Revisão manual                       | instituto nacional de pesquisa agropecuária     | Instituto Nacional de Investigación Agropecuaria (INIA)                                    | INIA é ambíguo entre países. Validar país na afiliação completa antes de cadastrar.                                                                                                                                             |
| SEIDOR Consulting SL                               |             1 | Cadastrar organização                | empresa privada/consultoria                     | SEIDOR Consulting S.L.                                                                     | Espanha; empresa de consultoria/tecnologia. Não cadastrar como instituição acadêmica.                                                                                                                                           |
| North Bristol NHS Trust                            |             1 | Cadastrar organização                | hospital/NHS trust                              | North Bristol NHS Trust                                                                    | Bristol, Reino Unido. Organização hospitalar.                                                                                                                                                                                   |
| Ajna Consulting                                    |             1 | Cadastrar organização/revisão        | consultoria                                     | Ajna Consulting                                                                            | Nome comercial; cadastrar em organizações somente se a afiliação completa confirmar país/site.                                                                                                                                  |
| Rosengren Epidemiol Consulting                     |             1 | Cadastrar organização/revisão        | consultoria epidemiológica                      | Rosengren Epidemiology Consulting                                                          | Consultoria; não universidade. Validar país/site.                                                                                                                                                                               |
| Natl Remote Sensing Ctr NRSC                       |             1 | Adicionar variação                   | centro governamental de pesquisa                | National Remote Sensing Centre                                                             | NRSC, centro da ISRO, Hyderabad, Índia. Adicionar variação.                                                                                                                                                                     |
| Shanghai Key Lab Mech Energy Engn                  |             1 | Cadastrar unidade/revisão            | laboratório                                     | Shanghai Key Laboratory of Mechanics in Energy Engineering                                 | Provável laboratório chinês; precisa instituição-mãe na afiliação completa. Cadastrar como unidade, não instituição principal.                                                                                                  |
| Geisinger Orthopaed & Sports Med                   |             1 | Cadastrar organização/unidade        | hospital/clínica                                | Geisinger Orthopaedics and Sports Medicine                                                 | Unidade/serviço do sistema Geisinger, EUA. Pode ser organização/unidade vinculada a Geisinger.                                                                                                                                  |
| Essex Partnership NHS Fdn Trust                    |             1 | Cadastrar organização                | hospital/NHS trust                              | Essex Partnership University NHS Foundation Trust                                          | Reino Unido. Hospital trust; não universidade, apesar de conter University no nome oficial.                                                                                                                                     |
| Acacia Consulting                                  |             1 | Cadastrar organização/revisão        | consultoria                                     | Acacia Consulting                                                                          | Consultoria. Validar país/site.                                                                                                                                                                                                 |
| Agr Res & Rural Extens Co Santa Catarina CEPAF EPA |             1 | Cadastrar organização + unidade      | empresa pública de pesquisa/extensão            | Epagri - Empresa de Pesquisa Agropecuária e Extensão Rural de Santa Catarina               | CEPAF/Epagri. Cadastrar Epagri como organização/instituto e CEPAF como unidade se necessário.                                                                                                                                   |
| Amer Heart Assoc Int                               |             1 | Cadastrar organização                | associação científica/saúde                     | American Heart Association                                                                 | AHA. Organização científica e de saúde dos EUA.                                                                                                                                                                                 |
| Shahid Gangalal Natl Heart Ctr                     |             1 | Cadastrar organização                | hospital/centro cardíaco                        | Shahid Gangalal National Heart Centre                                                      | Kathmandu, Nepal. Centro/hospital cardíaco.                                                                                                                                                                                     |
| Interact Technol Inst ITI LARSyS & ARDITI          |             1 | Cadastrar instituição/unidade        | instituto de pesquisa                           | Interactive Technologies Institute (ITI / LARSyS)                                          | Portugal/Madeira. Vínculo com LARSyS e ARDITI; cadastrar como instituto/unidade de pesquisa.                                                                                                                                    |
| AbbVie                                             |             1 | Cadastrar organização                | empresa privada farmacêutica                    | AbbVie Inc.                                                                                | Empresa farmacêutica; não universidade.                                                                                                                                                                                         |
| Power Nutr                                         |             1 | Revisão manual                       | empresa/organização                             | Power Nutr / Power Nutrition                                                               | Nome incompleto. Validar pela afiliação completa.                                                                                                                                                                               |
| Natl Renewable Energy Lab                          |             1 | Cadastrar organização/instituto      | laboratório nacional de pesquisa                | National Renewable Energy Laboratory                                                       | NREL, laboratório nacional dos EUA em Golden/Colorado.                                                                                                                                                                          |
| 30 Xueyuan Rd                                      |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| 12127 Old Oaks Dr                                  |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| Inst Fed Educ Ciencia & Tecnol Ceara IFCE          |             1 | Atualizar/Adicionar variação         | instituto federal                               | Instituto Federal de Educação, Ciência e Tecnologia do Ceará                               | IFCE, Ceará/Brasil. No cadastro há IFCE genérico; corrigir dados e adicionar variação.                                                                                                                                          |
| Univ Fed Pelotas UFEPL                             |             1 | Adicionar variação                   | universidade                                    | Universidade Federal de Pelotas                                                            | UFPel. UFEPL parece erro de sigla; adicionar como variação para capturar o erro.                                                                                                                                                |
| Energy Consulting Mali                             |             1 | Cadastrar organização/revisão        | consultoria                                     | Energy Consulting Mali                                                                     | Consultoria/empresa; validar dados antes.                                                                                                                                                                                       |
| CIRAD CATIE                                        |             1 | Separar em duas instituições         | institutos de pesquisa                          | CIRAD + CATIE                                                                              | O valor junta duas instituições. Melhor corrigir parser/importação ou cadastrar variação composta apontando para ambos se o sistema permitir.                                                                                   |
| 1180 Ctr Dr                                        |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| Retina Care Int                                    |             1 | Cadastrar organização/revisão        | saúde/oftalmologia                              | Retina Care International                                                                  | Organização/serviço de saúde; validar país/site.                                                                                                                                                                                |
| Yunnan Key Lab Rural Energy Engn                   |             1 | Cadastrar unidade/revisão            | laboratório                                     | Yunnan Key Laboratory of Rural Energy Engineering                                          | Laboratório chinês; precisa instituição-mãe pela afiliação completa.                                                                                                                                                            |
| Rooney Heart Inst                                  |             1 | Cadastrar organização/revisão        | centro/instituto cardíaco                       | Rooney Heart Institute                                                                     | Centro/instituto de saúde; validar instituição-mãe e país.                                                                                                                                                                      |
| Natl Sch Elect & Telecommun Sfax                   |             1 | Cadastrar instituição/unidade        | escola de engenharia                            | National School of Electronics and Telecommunications of Sfax                              | Tunísia; ligada à University of Sfax. Cadastrar como instituição ou unidade vinculada.                                                                                                                                          |
| Chinese Acad Sci SKL MCCS                          |             1 | Adicionar variação/unidade           | laboratório/unidade de pesquisa                 | Chinese Academy of Sciences                                                                | SKL MCCS é laboratório/unidade da CAS; adicionar variação/unidade, não criar instituição principal nova.                                                                                                                        |
| Mass Gen Brigham                                   |             1 | Cadastrar organização                | sistema hospitalar/pesquisa médica              | Mass General Brigham                                                                       | Boston, EUA. Sistema hospitalar e de pesquisa; não universidade.                                                                                                                                                                |
| Stat Scope Consulting                              |             1 | Cadastrar organização/revisão        | consultoria estatística                         | Stat Scope Consulting                                                                      | Consultoria; validar site/país.                                                                                                                                                                                                 |
| Singapore Natl Heart Ctr Singapore                 |             1 | Cadastrar organização                | hospital/centro cardíaco                        | National Heart Centre Singapore                                                            | Singapura. Hospital/centro nacional de cardiologia.                                                                                                                                                                             |
| Programa Posgrad Ciencias Engn Ambiental EESC USP  |             1 | Cadastrar unidade                    | programa de pós-graduação                       | Programa de Pós-Graduação em Ciências da Engenharia Ambiental - EESC USP                   | Unidade/programa da Escola de Engenharia de São Carlos da USP.                                                                                                                                                                  |
| CRHEA EESC USP                                     |             1 | Cadastrar unidade                    | centro de pesquisa                              | Centro de Recursos Hídricos e Estudos Ambientais - EESC USP                                | Unidade da EESC/USP.                                                                                                                                                                                                            |
| State Grid Wuhan Elect Power Co                    |             1 | Cadastrar organização                | empresa estatal de energia                      | State Grid Wuhan Electric Power Company                                                    | Empresa/companhia de energia; não universidade.                                                                                                                                                                                 |
| Abbvie                                             |             1 | Cadastrar organização                | empresa privada farmacêutica                    | AbbVie Inc.                                                                                | Mesma correção de AbbVie; normalizar caixa.                                                                                                                                                                                     |
| Xiangyang Elect Power Supply Co                    |             1 | Cadastrar organização                | empresa de energia                              | Xiangyang Electric Power Supply Company                                                    | Empresa/companhia de energia; não universidade.                                                                                                                                                                                 |
| Inst Tecnol Quim & Biol ITQB NOVA                  |             1 | Cadastrar instituição/unidade        | instituto de pesquisa universitário             | Instituto de Tecnologia Química e Biológica António Xavier - ITQB NOVA                     | Oeiras, Portugal; unidade da Universidade NOVA de Lisboa.                                                                                                                                                                       |
| Crocombe Spectroscop Consulting                    |             1 | Cadastrar organização/revisão        | consultoria técnica                             | Crocombe Spectroscopic Consulting                                                          | Consultoria; não universidade.                                                                                                                                                                                                  |
| ACER Consulting                                    |             1 | Cadastrar organização/revisão        | consultoria                                     | ACER Consulting                                                                            | Nome comercial; validar país/site.                                                                                                                                                                                              |
| State Key Lab Intelligent Agr Power Equipment      |             1 | Cadastrar unidade/revisão            | laboratório estadual/chave                      | State Key Laboratory of Intelligent Agricultural Power Equipment                           | Laboratório chinês; identificar instituição-mãe na afiliação completa.                                                                                                                                                          |
| Hydropower Engn Geol Reconnaissance Consulting & P |             1 | Cadastrar organização/revisão        | consultoria/engenharia                          | Hydropower Engineering Geological Reconnaissance Consulting & Planning                     | Nome truncado; validar nome completo antes de cadastrar.                                                                                                                                                                        |
| Univ Fed Rio De Janeiro                            |             1 | Adicionar variação                   | universidade                                    | Universidade Federal do Rio de Janeiro                                                     | Mesmo caso de UFRJ; diferença apenas de caixa.                                                                                                                                                                                  |
| Anant Bajaj Retina Inst                            |             1 | Cadastrar organização/instituto      | instituto oftalmológico                         | Anant Bajaj Retina Institute                                                               | Índia. Instituto/serviço de retina; não universidade.                                                                                                                                                                           |
| Cent & North West London NHS Fdn Trust             |             1 | Cadastrar organização                | hospital/NHS trust                              | Central and North West London NHS Foundation Trust                                         | Reino Unido. NHS foundation trust.                                                                                                                                                                                              |
| Nat Resources Consulting Engineers                 |             1 | Cadastrar organização/revisão        | consultoria/engenharia                          | Natural Resources Consulting Engineers                                                     | Consultoria/empresa; não universidade.                                                                                                                                                                                          |
| FarmB Digital Agr PC                               |             1 | Cadastrar organização/revisão        | empresa/agritech                                | FarmB Digital Agriculture PC                                                               | Empresa/agritech; validar país/site.                                                                                                                                                                                            |
| Programa Pos graduacao Biotecnol & Bioproc UFRJ    |             1 | Cadastrar unidade                    | programa de pós-graduação                       | Programa de Pós-Graduação em Biotecnologia e Bioprocessos - UFRJ                           | Programa/unidade vinculada à UFRJ.                                                                                                                                                                                              |
| JM Farber Global Food Safety Consulting            |             1 | Cadastrar organização                | consultoria em segurança de alimentos           | J. M. Farber Global Food Safety Consulting                                                 | Consultoria; não universidade.                                                                                                                                                                                                  |
| Knewfutures Consulting                             |             1 | Cadastrar organização/revisão        | consultoria                                     | KnewFutures Consulting                                                                     | Consultoria; validar país/site.                                                                                                                                                                                                 |
| Agr Informat Inst CAAS                             |             1 | Cadastrar unidade/adicionar variação | instituto de pesquisa                           | Agricultural Information Institute, Chinese Academy of Agricultural Sciences               | Unidade/instituto da CAAS; adicionar variação e vincular à CAAS se possível.                                                                                                                                                    |
| Samsung Elect                                      |             1 | Cadastrar organização                | empresa privada                                 | Samsung Electronics                                                                        | Coreia do Sul. Empresa; não universidade.                                                                                                                                                                                       |
| Medway NHS Fdn Trust                               |             1 | Cadastrar organização                | hospital/NHS trust                              | Medway NHS Foundation Trust                                                                | Reino Unido. Hospital trust.                                                                                                                                                                                                    |
| Inst Natl Rech Agr Alimentat & Environm INRAE      |             1 | Adicionar variação                   | instituto nacional de pesquisa                  | Institut national de recherche pour l’agriculture, l’alimentation et l’environnement       | INRAE, França. Já existe; adicionar variação.                                                                                                                                                                                   |
| Agr Informat Inst Chinese Acad Agr Sci             |             1 | Cadastrar unidade/adicionar variação | instituto de pesquisa                           | Agricultural Information Institute, Chinese Academy of Agricultural Sciences               | Mesmo caso de CAAS Agricultural Information Institute.                                                                                                                                                                          |
| SciWest Consulting                                 |             1 | Cadastrar organização/revisão        | consultoria                                     | SciWest Consulting                                                                         | Consultoria; validar país/site.                                                                                                                                                                                                 |
| 5510 Nathan Shock Dr                               |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| State Grid Tianjin Elect Power Co                  |             1 | Cadastrar organização                | empresa estatal de energia                      | State Grid Tianjin Electric Power Company                                                  | Empresa/companhia de energia.                                                                                                                                                                                                   |
| State Grid Hebei Elect Power Co                    |             1 | Cadastrar organização                | empresa estatal de energia                      | State Grid Hebei Electric Power Company                                                    | Empresa/companhia de energia.                                                                                                                                                                                                   |
| Borgess Heart Inst                                 |             1 | Cadastrar organização                | hospital/instituto cardíaco                     | Borgess Heart Institute                                                                    | EUA; unidade de saúde/cardiologia.                                                                                                                                                                                              |
| N Ohio Heart Ctr                                   |             1 | Cadastrar organização                | centro cardíaco/clínica                         | North Ohio Heart Center                                                                    | EUA; serviço/centro de cardiologia.                                                                                                                                                                                             |
| State Environm Protect Key Lab Synerget Control &  |             1 | Cadastrar unidade/revisão            | laboratório estadual/chave                      | State Environmental Protection Key Laboratory of Synergistic Control and Joint Remediation | Nome truncado; laboratório/unidade, precisa instituição-mãe.                                                                                                                                                                    |
| Agr Res Stn ARS                                    |             1 | Revisão manual                       | estação/serviço de pesquisa agrícola            | Agricultural Research Station / Agricultural Research Service                              | Pode se referir a estação de pesquisa ou USDA ARS. Validar afiliação completa.                                                                                                                                                  |
| SINTEF Energy Res                                  |             1 | Cadastrar instituição/unidade        | instituto de pesquisa                           | SINTEF Energy Research                                                                     | Noruega; unidade/instituto do grupo SINTEF.                                                                                                                                                                                     |
| Sustainable Energy Catapult Ctr                    |             1 | Revisão manual                       | centro/organização de energia                   | Sustainable Energy Catapult Centre                                                         | Nome possivelmente truncado. Validar se é Energy Systems Catapult ou outro centro.                                                                                                                                              |
| Agstat Consulting                                  |             1 | Cadastrar organização                | consultoria estatística/agro                    | AgStat Consulting                                                                          | Consultoria; não universidade.                                                                                                                                                                                                  |
| Conflict Management Consulting                     |             1 | Cadastrar organização                | consultoria                                     | Conflict Management Consulting                                                             | Consultoria; não universidade.                                                                                                                                                                                                  |
| Shandong Engn Res Ctr Environm Protect & Remediat  |             1 | Cadastrar unidade/revisão            | centro de engenharia/pesquisa                   | Shandong Engineering Research Center for Environmental Protection and Remediation          | Nome truncado; precisa instituição-mãe.                                                                                                                                                                                         |
| S&F Pro Consulting                                 |             1 | Cadastrar organização/revisão        | consultoria                                     | S&F Pro Consulting                                                                         | Consultoria; validar país/site.                                                                                                                                                                                                 |
| 118 Parr St                                        |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| 1280 Montgomery Blvd                               |             1 | Não cadastrar                        | endereço                                        | -                                                                                          | É endereço/logradouro, não instituição.                                                                                                                                                                                         |
| Natl Secretariat Reduct Teenage Pregnancy          |             1 | Cadastrar organização governamental  | órgão público/secretaria                        | National Secretariat for the Reduction of Teenage Pregnancy                                | Órgão/secretaria pública; não universidade.                                                                                                                                                                                     |
| Agr Res Serv ARS                                   |             1 | Cadastrar organização/instituto      | agência pública de pesquisa                     | Agricultural Research Service (USDA ARS)                                                   | Serviço de Pesquisa Agrícola do USDA, EUA.                                                                                                                                                                                      |
| Univ Fed Campina UFCG                              |             1 | Adicionar variação                   | universidade                                    | Universidade Federal de Campina Grande                                                     | UFCG. Já existe; adicionar variação.                                                                                                                                                                                            |
| Chrysalis Consulting                               |             1 | Cadastrar organização/revisão        | consultoria                                     | Chrysalis Consulting                                                                       | Consultoria; validar país/site.                                                                                                                                                                                                 |

---

## 6. Correções prioritárias

### 6.1. Corrigir registros que já existem, mas estão incompletos ou duplicados

Prioridade alta:

| Nome bruto | Correção |
|---|---|
| `Univ Fed Rio de Janeiro` / `Univ Fed Rio De Janeiro` | Adicionar variação ao cadastro de `Universidade Federal do Rio de Janeiro`. |
| `Univ Fed Campina UFCG` | Adicionar variação a `Universidade Federal de Campina Grande`. |
| `Univ Fed Pelotas UFEPL` | Adicionar variação a `Universidade Federal de Pelotas`; `UFEPL` é provável erro de sigla para `UFPel`. |
| `Inst Fed Educ Ciencia & Tecnol Ceara IFCE` | Corrigir cadastro genérico `IFCE` para `Instituto Federal de Educação, Ciência e Tecnologia do Ceará` e adicionar a variação. |
| `Daegu Gyeongbuk Inst Sci & Technol DGIST` | Corrigir/mesclar registros duplicados de `DGIST` e adicionar essa variação. |
| `Int Ctr Agr Res Dry Areas ICARDA` | Corrigir tipo para instituto internacional de pesquisa e adicionar variação. |
| `Inst Natl Rech Agr Alimentat & Environm INRAE` | Adicionar variação ao cadastro do `INRAE`. |
| `Agr Informat Inst CAAS` e `Agr Informat Inst Chinese Acad Agr Sci` | Cadastrar como unidade/instituto vinculado à `Chinese Academy of Agricultural Sciences`. |
| `CIRAD CATIE` | Separar em duas instituições: `CIRAD` e `CATIE`, se o sistema permitir múltiplos vínculos. |

---

## 7. Sugestão de campos canônicos para cadastros novos

### Embrapa Agricultura Digital

```text
official_name: Embrapa Agricultura Digital
short_name: Embrapa Agricultura Digital
sigla: EMBRAPA
institution_type: Instituto de Pesquisa
natureza: Pública
country_name: Brasil
state_sigla: SP
city_name: Campinas
official_website: https://www.embrapa.br/agricultura-digital
variations: Embrapa Digital Agr; Embrapa Agr Digital
```

### Baker Heart and Diabetes Institute

```text
official_name: Baker Heart and Diabetes Institute
short_name: Baker Institute
sigla:
institution_type: Instituto de Pesquisa Médica
natureza: Privada / sem fins lucrativos
country_name: Austrália
state_sigla: VIC
city_name: Melbourne
official_website: https://baker.edu.au
variations: Baker Heart & Diabet Inst; Baker Heart and Diabet Inst; Baker IDI Heart and Diabetes Institute
```

### Daegu Gyeongbuk Institute of Science and Technology

```text
official_name: Daegu Gyeongbuk Institute of Science and Technology
short_name: DGIST
sigla: DGIST
institution_type: Universidade / Instituto de Ciência e Tecnologia
natureza: Pública
country_name: Coreia do Sul
city_name: Daegu
official_website: https://www.dgist.ac.kr/en/
variations: Daegu Gyeongbuk Inst Sci & Technol DGIST; DGIST; Daegu Gyeonbuk Institute Science & Engineering DGIST
```

### International Center for Agricultural Research in the Dry Areas

```text
official_name: International Center for Agricultural Research in the Dry Areas
short_name: ICARDA
sigla: ICARDA
institution_type: Instituto Internacional de Pesquisa
natureza: Organização internacional / CGIAR
country_name: Líbano
city_name: Beirut
official_website: https://www.icarda.org
variations: Int Ctr Agr Res Dry Areas ICARDA; ICARDA
```

### National Remote Sensing Centre

```text
official_name: National Remote Sensing Centre
short_name: NRSC
sigla: NRSC
institution_type: Centro Governamental de Pesquisa
natureza: Pública
country_name: Índia
city_name: Hyderabad
official_website: https://www.nrsc.gov.in
variations: Natl Remote Sensing Ctr NRSC; National Remote Sensing Centre NRSC
```

### National Renewable Energy Laboratory

```text
official_name: National Renewable Energy Laboratory
short_name: NREL
sigla: NREL
institution_type: Laboratório Nacional de Pesquisa
natureza: Pública
country_name: Estados Unidos
state_sigla: CO
city_name: Golden
official_website: https://www.nrel.gov
variations: Natl Renewable Energy Lab; National Renewable Energy Lab; NREL
```

### ITQB NOVA

```text
official_name: Instituto de Tecnologia Química e Biológica António Xavier
short_name: ITQB NOVA
sigla: ITQB NOVA
institution_type: Instituto de Pesquisa Universitário
natureza: Pública
country_name: Portugal
city_name: Oeiras
official_website: https://www.itqb.unl.pt
variations: Inst Tecnol Quim & Biol ITQB NOVA; ITQB NOVA; Instituto Tecnologia Quimica Biologica ITQB NOVA
```

### INRAE

```text
official_name: Institut national de recherche pour l’agriculture, l’alimentation et l’environnement
short_name: INRAE
sigla: INRAE
institution_type: Instituto Nacional de Pesquisa
natureza: Pública
country_name: França
city_name: Paris
official_website: https://www.inrae.fr
variations: Inst Natl Rech Agr Alimentat & Environm INRAE; INRAE; INRA
```

### USDA Agricultural Research Service

```text
official_name: Agricultural Research Service
short_name: USDA ARS
sigla: ARS
institution_type: Agência Pública de Pesquisa
natureza: Pública
country_name: Estados Unidos
official_website: https://www.ars.usda.gov
variations: Agr Res Serv ARS; USDA ARS; Agricultural Research Service ARS
```

---

## 8. Fontes e páginas úteis para validação

- Embrapa: https://www.embrapa.br
- Embrapa Agricultura Digital: https://www.embrapa.br/agricultura-digital
- UFRJ: https://ufrj.br
- UFPel: https://portal.ufpel.edu.br
- UFCG: https://portal.ufcg.edu.br
- IFCE: https://ifce.edu.br
- ICARDA: https://www.icarda.org
- UNSW Canberra: https://www.unsw.edu.au/canberra
- DGIST: https://www.dgist.ac.kr/en/
- Baker Heart and Diabetes Institute: https://baker.edu.au
- NRSC / ISRO: https://www.nrsc.gov.in/
- NREL: https://www.nrel.gov
- INRAE: https://www.inrae.fr
- ITQB NOVA: https://www.itqb.unl.pt
- Mass General Brigham: https://www.massgeneralbrigham.org
- Guy's and St Thomas' NHS Foundation Trust: https://www.guysandstthomas.nhs.uk
- National Heart Centre Singapore: https://www.nhcs.com.sg
- AbbVie: https://www.abbvie.com
- Samsung Electronics: https://www.samsung.com
- SINTEF Energy Research: https://www.sintef.no/en/sintef-energy/

---

## 9. Próximo passo recomendado

1. Primeiro aplique as variações de instituições brasileiras e grandes institutos já cadastrados.
2. Depois corrija duplicidades e registros incompletos, principalmente `DGIST`, `ICARDA`, `IFCE` e `NITIE`.
3. Em seguida cadastre organizações/hospitais/empresas.
4. Por fim, trate os nomes truncados e genéricos com revisão manual usando a afiliação completa do artigo.
5. Rode novamente o sincronismo em `/projects/5/sync-geography`.
6. Exporte novamente as instituições não encontradas para verificar o que sobrou.

---

## 10. Observação técnica sobre o parser

Alguns nomes aparecem porque o parser de afiliações quebra o texto por vírgula e ponto-e-vírgula. Isso pode gerar:

- endereço como instituição;
- duas instituições grudadas em um único valor;
- laboratório sem instituição-mãe;
- sigla sem nome completo.

Por isso, além de cadastrar variações, é recomendável melhorar o parser para guardar:

```text
raw_affiliation
institution_candidate
organization_candidate
unit_candidate
address_candidate
country_candidate
```

Assim o sistema evita transformar endereço, laboratório ou consultoria em instituição principal.

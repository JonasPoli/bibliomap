# Pacote de correção - Países, localidades e instituições acadêmicas

Este pacote foi gerado para resolver os problemas apontados nos últimos prompts: países não encontrados, localidades dos EUA tratadas como país e instituições/organizações não encontradas.

## Arquivos

1. `01_paises_nao_encontrados_classificados.csv`
   - Lista completa dos valores de país/localidade do arquivo de inconsistências.
   - Classifica cada item como `PAIS_REAL` ou `LOCALIDADE_EUA_NAO_PAIS`.

2. `02_paises_para_adicionar.csv`
   - Países reais que devem ser adicionados ao `paises.csv`.
   - Já vem no formato próximo ao cadastro: official_name, common_name, sigla, iso_code, continente, nationality, status e variations.

3. `03_paises_variacoes_para_adicionar.csv`
   - Valores que parecem ser apenas variações de países já existentes, por exemplo caixa alta ou abreviações.

4. `04_localidades_eua_para_corrigir.csv`
   - Valores como `CA 95616 USA`, `FL 32611 USA`, `TX 77843 USA`.
   - Não devem ser adicionados em países. Devem ser separados em `country_name=Estados Unidos`, `state_sigla` e `postal_code`.

5. `05_instituicoes_variacoes_classificadas.csv`
   - Classificação ampla das variações de instituições do arquivo original.
   - Indica se cada valor deve ir para `instituicoes`, `instituicao_unidades`, `organizacoes` ou `revisao_manual`.

6. `06_instituicoes_correcoes_alta_confianca.csv`
   - Correções com maior confiança, incluindo nomes citados nos últimos prompts.
   - Use este arquivo primeiro.

7. `07_organizacoes_para_cadastrar.csv`
   - Ministérios, empresas, agências, hospitais independentes, organismos internacionais etc.
   - Não devem entrar como universidades.

8. `08_unidades_para_cadastrar.csv`
   - Departamentos, hospitais universitários, centros, campi e unidades internas.
   - Devem ser vinculados à instituição principal.

9. `09_variacoes_instituicoes_para_adicionar.csv`
   - Variações de escrita que devem ser adicionadas na tabela de variações das instituições.

10. `10_institutos_pesquisa_para_validar.csv`
   - Institutos/centros de pesquisa que podem ficar em `instituicoes`, mas com tipo `Instituto de Pesquisa`, não `Universidade`.

11. `11_resumo_correcoes.csv`
   - Resumo quantitativo do pacote.

## Ordem recomendada de aplicação

1. Aplicar `04_localidades_eua_para_corrigir.csv` para limpar países que são na verdade estado/CEP dos EUA.
2. Inserir os países de `02_paises_para_adicionar.csv` em `paises.csv`.
3. Acrescentar as variações de `03_paises_variacoes_para_adicionar.csv` nos países existentes.
4. Aplicar primeiro `06_instituicoes_correcoes_alta_confianca.csv`.
5. Criar ou alimentar a tabela `organizacoes` com `07_organizacoes_para_cadastrar.csv`.
6. Criar ou alimentar a tabela `instituicao_unidades` com `08_unidades_para_cadastrar.csv`.
7. Inserir variações de instituições com `09_variacoes_instituicoes_para_adicionar.csv`.
8. Validar manualmente os registros de baixa confiança no arquivo `05_instituicoes_variacoes_classificadas.csv`.

## Regra principal

Não cadastre tudo automaticamente como universidade. O erro original acontece porque empresas, ministérios, hospitais, agências públicas, departamentos e centros foram tratados como se fossem instituições de ensino superior.

## Totais

- Itens de países/localidades analisados: 881
- Localidades dos EUA que não são países: 791
- Países reais para adicionar: 86
- Variações de países existentes: 0
- Variações de instituições analisadas: 11075
- Correções de instituições de alta confiança: 1566
- Organizações identificadas: 1380
- Unidades/departamentos/hospitais universitários identificados: 118

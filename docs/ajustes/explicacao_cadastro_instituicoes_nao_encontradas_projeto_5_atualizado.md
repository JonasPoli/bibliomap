# Instituições ainda não encontradas no Projeto 5 — análise atualizada

Arquivo analisado: `instituicoes_nao_encontradas_projeto_5(3).csv`  
Total de itens: **21**  
Total de ocorrências somadas: **36**

Este documento considera o projeto atualizado no GitHub e a lista mais recente de instituições ainda não resolvidas.

---

## 1. O que mudou no projeto atualizado

O projeto já avançou bastante em relação ao problema anterior.

Na versão atual, o `DocumentEnrichmentService` não consulta apenas a tabela principal de instituições. Ele carrega também:

- `instituicoes_ensino`
- `instituicao_variacoes_nome`
- `organizacoes`
- `instituicao_unidades`

A ordem de tentativa de resolução agora é:

1. procurar em **instituições principais**;
2. procurar em **organizações**;
3. procurar em **unidades de instituição**;
4. se nada for encontrado, colocar em `unresolved_institutions`.

Isso significa que empresas, hospitais, centros, institutos independentes e unidades internas agora podem ser tratados sem precisar cadastrar tudo como universidade.

Também houve melhoria na parte de países: o importador Scopus agora converte padrões como `FL 32611 USA` para `USA`, e também converte `Peoples R China` para `China`.

---

## 2. Por que ainda existem instituições não encontradas

Mesmo com a melhoria, ainda sobraram casos por cinco motivos principais.

### 2.1. Alguns itens ainda não estão cadastrados como variação exata

O sincronizador normaliza o texto, mas faz busca por correspondência direta. Para `organizacoes` e `instituicao_unidades`, ele usa principalmente:

- `canonical_name`
- `original_variation_name`

Então, se o arquivo traz:

```text
Inst Tecnol Quim & Biol ITQB NOVA
```

e no cadastro existe apenas:

```text
Instituto de Tecnologia Química e Biológica António Xavier
```

o sistema só resolverá se essa forma abreviada também estiver cadastrada como variação/original.

### 2.2. Existem nomes compostos

Exemplo:

```text
Interact Technol Inst ITI LARSyS & ARDITI
```

Esse texto mistura mais de uma entidade:

- Interactive Technologies Institute / ITI / LARSyS
- ARDITI

Como o sistema ainda não separa automaticamente entidades unidas por `&`, é preciso cadastrar essa string bruta como variação de uma entidade principal, ou melhorar o importador para dividir nomes compostos.

### 2.3. Existem siglas ambíguas

Exemplo:

```text
Inst Nacl Invest Agr INIA
```

`INIA` pode significar instituições diferentes em vários países. Sem olhar o país do artigo, não é seguro decidir se é:

- INIA Uruguay;
- INIA Spain / CSIC;
- INIA Peru;
- INIA Chile;
- INIA Venezuela;
- INIAP Ecuador.

Nesse caso, não é recomendado cadastrar a variação globalmente sem verificar o país associado ao documento.

### 2.4. Existem endereços extraídos como instituição

Os itens abaixo não são instituições:

```text
30 Xueyuan Rd
12127 Old Oaks Dr
1180 Ctr Dr
5510 Nathan Shock Dr
118 Parr St
1280 Montgomery Blvd
```

Esses registros devem ser tratados como **ruído de importação**, não como cadastro.

### 2.5. Algumas entidades são clínicas, empresas ou organizações privadas

Exemplos:

```text
Power Nutr
Retina Care Int
Rooney Heart Inst
Borgess Heart Inst
Sustainable Energy Catapult Ctr
```

Esses itens não devem entrar em `instituicoes_ensino` sem validação. Na maioria dos casos, se confirmados, devem entrar em `organizacoes`.

---

## 3. Resumo das ações recomendadas

| Nome bruto | Ocorr. | Ação | Nome canônico sugerido | Tabela | Conf. | Observação |
|---|---:|---|---|---|---|---|
| Sustainable AgriFoodtech Innovat & Res SAFIR | 14 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Sustainable AgriFoodtech Innovation and Research (SAFIR) | organizacoes ou instituicao_unidades | baixa | Como tem 14 ocorrências, é prioritário validar no documento original ou afiliação completa. Se for apenas centro independente, usar organizacoes. Se for centro interno de universidade, usar instituicao_unidades com parent_institution_id. |
| Ctr Energy & Environm Sustainabil | 3 | CADASTRAR_UNIDADE | International Centre for Energy and Environmental Sustainability Research (ICEESR) | instituicao_unidades | média | Criar/validar antes a instituição-pai University of Uyo. Cadastrar o raw como original_variation_name. |
| Inst Nacl Invest Agr INIA | 1 | REVISAR_PAIS_DO_DOCUMENTO | Depende do país: ex. Instituto Nacional de Investigación Agropecuaria (INIA, Uruguay) ou Instituto Nacional de Investigación y Tecnología Agraria y Alimentaria (INIA-CSIC, Spain) | instituicoes_ensino + instituicao_variacoes_nome | baixa sem país | Não cadastrar uma variação global “Inst Nacl Invest Agr INIA” sem checar o país, porque INIA é sigla compartilhada por vários países. |
| Interact Technol Inst ITI LARSyS & ARDITI | 1 | CADASTRAR_UNIDADE_E_ORG_SEPARADA | Interactive Technologies Institute (ITI/LARSyS) | instituicao_unidades + organizacoes | alta para ITI/LARSyS; média para a string composta | Para resolver o raw atual, cadastre exatamente esta string como original_variation_name da unidade ITI/LARSyS. Opcionalmente cadastre ARDITI como organizacao separada. |
| Power Nutr | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Power Nutrition | organizacoes | baixa | Validar afiliação completa no CSV original antes de cadastrar. Se for marca/empresa, usar organizacoes. |
| 30 Xueyuan Rd | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |
| 12127 Old Oaks Dr | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |
| 1180 Ctr Dr | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |
| Retina Care Int | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Retina Care International | organizacoes | baixa | Se confirmado no artigo, cadastrar em organizacoes como clínica/organização médica. Não é universidade. |
| Rooney Heart Inst | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Rooney Heart Institute | organizacoes ou instituicao_unidades | baixa | Se for unidade de hospital, cadastrar como instituicao_unidades somente se houver instituição-pai cadastrada; caso contrário, organizacoes. |
| Natl Sch Elect & Telecommun Sfax | 1 | CADASTRAR_UNIDADE | École nationale d'électronique et des télécommunications de Sfax (ENET’Com) | instituicao_unidades ou instituicoes_ensino | alta | Preferível como unidade da Université de Sfax. Se o sistema não tiver a universidade-pai, criar primeiro Université de Sfax. |
| Inst Tecnol Quim & Biol ITQB NOVA | 1 | CADASTRAR_UNIDADE | Instituto de Tecnologia Química e Biológica António Xavier (ITQB NOVA) | instituicao_unidades | alta | Criar/validar Universidade NOVA de Lisboa e cadastrar o raw como original_variation_name do ITQB NOVA. |
| Anant Bajaj Retina Inst | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Anant Bajaj Retina Institute | organizacoes | baixa | Não cadastrar até confirmar o nome completo na afiliação original. |
| Agr Informat Inst Chinese Acad Agr Sci | 1 | CADASTRAR_UNIDADE | Agricultural Information Institute, Chinese Academy of Agricultural Sciences | instituicao_unidades | alta | Criar/validar primeiro Chinese Academy of Agricultural Sciences. Cadastrar este raw como original_variation_name da unidade. |
| 5510 Nathan Shock Dr | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |
| Borgess Heart Inst | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Borgess Heart Institute | organizacoes | média-baixa | Cadastrar como organizacao médica se confirmado. Não é instituição acadêmica. |
| Agr Res Stn ARS | 1 | REVISAR_PAIS_DO_DOCUMENTO | Agricultural Research Station (ARS) | instituicao_unidades ou organizacoes | baixa sem contexto | Não cadastrar globalmente porque ARS pode significar USDA Agricultural Research Service ou Agricultural Research Station de universidade/agência local. |
| SINTEF Energy Res | 1 | CADASTRAR_ORGANIZACAO_OU_UNIDADE | SINTEF Energy Research | organizacoes ou instituicao_unidades | alta | Se quiser vincular a produção ao grupo SINTEF como instituição, cadastre SINTEF em instituicoes_ensino e esta como unidade com parent_institution_id. Se só quiser remover dos não encontrados, organizacoes resolve. |
| Sustainable Energy Catapult Ctr | 1 | REVISAR_MANUALMENTE_ANTES_DE_CADASTRAR | Sustainable Energy Catapult Centre | organizacoes | baixa | Validar afiliação completa. Não cadastrar automaticamente como instituição acadêmica. |
| 118 Parr St | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |
| 1280 Montgomery Blvd | 1 | NAO_CADASTRAR_BLACKLIST | N/A | nenhuma | alta | Remover do JSON bruto document.institutions ou criar filtro/blacklist no importador. |

---

## 4. Como cadastrar cada tipo de item

### 4.1. Instituição acadêmica principal

Use `instituicoes_ensino` quando for uma universidade, academia científica, instituto de pesquisa acadêmico principal ou entidade que você quer que apareça como instituição no dashboard.

Exemplo: se ainda não existir `Chinese Academy of Agricultural Sciences`.

```sql
INSERT INTO instituicoes_ensino (
    official_name,
    short_name,
    sigla,
    institution_type,
    natureza,
    country_id,
    status,
    created_at,
    updated_at
)
VALUES (
    'Chinese Academy of Agricultural Sciences',
    'Chinese Academy of Agricultural Sciences',
    'CAAS',
    'Academia/Instituto de Pesquisa',
    'Pública',
    (SELECT id FROM paises WHERE common_name = 'China' OR official_name = 'China' LIMIT 1),
    1,
    NOW(),
    NOW()
);
```

Depois, cadastre variações em `instituicao_variacoes_nome`.

```sql
INSERT INTO instituicao_variacoes_nome (
    institution_id,
    variation_name,
    variation_type,
    normalized_name,
    status
)
VALUES (
    (SELECT id FROM instituicoes_ensino WHERE sigla = 'CAAS' LIMIT 1),
    'Chinese Acad Agr Sci',
    'scopus_abbreviation',
    'chinese acad agr sci',
    1
);
```

---

### 4.2. Unidade interna de uma instituição

Use `instituicao_unidades` quando for departamento, escola, centro, laboratório, instituto interno ou unidade ligada a uma universidade/instituição-pai.

Importante: para o documento ser vinculado à instituição-pai, preencha `parent_institution_id`.

Exemplo: `Inst Tecnol Quim & Biol ITQB NOVA`.

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Inst Tecnol Quim & Biol ITQB NOVA',
    'Instituto de Tecnologia Química e Biológica António Xavier (ITQB NOVA)',
    'Instituto de pesquisa e educação avançada',
    'alta',
    'Variação abreviada do Scopus para ITQB NOVA.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'Universidade NOVA de Lisboa'
           OR sigla = 'NOVA'
        LIMIT 1
    )
);
```

Se `Universidade NOVA de Lisboa` ainda não estiver cadastrada, crie primeiro a instituição-pai.

---

### 4.3. Organização, empresa, hospital ou centro independente

Use `organizacoes` quando o item não for uma instituição de ensino, por exemplo:

- empresa privada;
- hospital independente;
- agência governamental;
- centro de inovação;
- organização de pesquisa não acadêmica;
- clínica.

Exemplo: `SINTEF Energy Res`.

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'SINTEF Energy Res',
    'SINTEF Energy Research',
    'Organização de pesquisa',
    'alta',
    'Variação abreviada do Scopus para SINTEF Energy Research.'
);
```

Se você quiser que `SINTEF Energy Research` também conte como instituição no dashboard, há duas opções:

1. cadastrar `SINTEF` em `instituicoes_ensino` como instituto de pesquisa;
2. cadastrar `SINTEF Energy Research` em `instituicao_unidades` com `parent_institution_id` apontando para `SINTEF`.

---

### 4.4. Endereços e ruídos

Não cadastre endereços como instituição.

Lista desta rodada:

```text
30 Xueyuan Rd
12127 Old Oaks Dr
1180 Ctr Dr
5510 Nathan Shock Dr
118 Parr St
1280 Montgomery Blvd
```

Esses itens devem ser removidos do campo bruto `document.institutions` ou ignorados pelo importador.

---

## 5. Cadastros recomendados, item por item

### 5.1. Sustainable AgriFoodtech Innovat & Res SAFIR

- Ocorrências: **14**
- Ação: **revisão manual prioritária**
- Motivo: tem muitas ocorrências, mas o nome parece truncado.
- Nome sugerido provisório: `Sustainable AgriFoodtech Innovation and Research (SAFIR)`
- Tabela provável: `organizacoes` ou `instituicao_unidades`
- Confiança: **baixa**

Recomendação:

```text
Não cadastrar automaticamente como universidade.
Abrir a afiliação completa no artigo/documento original e confirmar:
1. se SAFIR é uma organização independente;
2. se é centro interno de alguma universidade;
3. qual país/cidade aparece na afiliação completa.
```

Se quiser resolver provisoriamente para sair da lista de não encontrados, cadastre como `organizacoes` com confiança baixa:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Sustainable AgriFoodtech Innovat & Res SAFIR',
    'Sustainable AgriFoodtech Innovation and Research (SAFIR)',
    'Centro/organização de pesquisa',
    'baixa',
    'Cadastro provisório. Nome completo não validado publicamente; confirmar na afiliação original.'
);
```

---

### 5.2. Ctr Energy & Environm Sustainabil

Identificação provável: `International Centre for Energy and Environmental Sustainability Research (ICEESR)`, ligado à `University of Uyo`.

Cadastrar como unidade:

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Ctr Energy & Environm Sustainabil',
    'International Centre for Energy and Environmental Sustainability Research (ICEESR)',
    'Centro de pesquisa',
    'média',
    'Variação abreviada para centro ligado à University of Uyo.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'University of Uyo'
        LIMIT 1
    )
);
```

Se `University of Uyo` não existir, cadastre antes:

```sql
INSERT INTO instituicoes_ensino (
    official_name,
    short_name,
    sigla,
    institution_type,
    natureza,
    country_id,
    status,
    created_at,
    updated_at
)
VALUES (
    'University of Uyo',
    'University of Uyo',
    NULL,
    'Universidade',
    'Pública',
    (SELECT id FROM paises WHERE common_name = 'Nigéria' OR official_name = 'Nigeria' LIMIT 1),
    1,
    NOW(),
    NOW()
);
```

---

### 5.3. Inst Nacl Invest Agr INIA

Este item é ambíguo.

Não cadastre diretamente sem olhar o país do documento.

Possíveis nomes canônicos:

```text
Instituto Nacional de Investigación Agropecuaria (INIA) — Uruguay
Instituto Nacional de Investigación y Tecnología Agraria y Alimentaria (INIA-CSIC) — Spain
Instituto Nacional de Innovación Agraria (INIA) — Peru
Instituto de Investigaciones Agropecuarias (INIA) — Chile
Instituto Nacional de Investigaciones Agrícolas (INIA) — Venezuela
Instituto Nacional de Investigaciones Agropecuarias (INIAP) — Ecuador
```

Procedimento correto:

```sql
SELECT id, title, countries, institutions
FROM document
WHERE project_id = 5
  AND institutions LIKE '%Inst Nacl Invest Agr INIA%';
```

Depois, escolha a instituição pelo país do artigo.

---

### 5.4. Interact Technol Inst ITI LARSyS & ARDITI

Identificação principal: `Interactive Technologies Institute (ITI/LARSyS)`.

Cadastrar como unidade:

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Interact Technol Inst ITI LARSyS & ARDITI',
    'Interactive Technologies Institute (ITI/LARSyS)',
    'Instituto/centro de pesquisa',
    'alta',
    'String composta; ARDITI aparece junto na afiliação, mas a unidade acadêmica principal é ITI/LARSyS.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'Instituto Superior Técnico'
           OR sigla = 'IST'
        LIMIT 1
    )
);
```

Opcionalmente, cadastre ARDITI separadamente:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'ARDITI',
    'Agência Regional para o Desenvolvimento da Investigação, Tecnologia e Inovação',
    'Agência pública de pesquisa e inovação',
    'média',
    'Entidade mencionada junto ao ITI/LARSyS.'
);
```

---

### 5.5. Power Nutr

Provável organização privada, mas o nome está truncado.

Recomendação:

```text
Validar no arquivo original antes de cadastrar.
Se confirmado como empresa/marca, cadastrar em organizacoes.
```

Cadastro provisório, se necessário:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Power Nutr',
    'Power Nutrition',
    'Empresa/organização privada',
    'baixa',
    'Nome truncado. Confirmar afiliação completa antes de usar em relatórios finais.'
);
```

---

### 5.6. Endereços: não cadastrar

Não cadastre estes itens:

```text
30 Xueyuan Rd
12127 Old Oaks Dr
1180 Ctr Dr
5510 Nathan Shock Dr
118 Parr St
1280 Montgomery Blvd
```

A correção correta é filtro no importador.

Sugestão de função para adicionar no importador:

```php
private function looksLikeAddress(string $part): bool
{
    $part = trim($part);

    if (preg_match('/^\d+\s+[A-Za-z0-9 .\-]+\s+(Rd|Road|Dr|Drive|St|Street|Ave|Avenue|Blvd|Boulevard|Ln|Lane|Way)$/i', $part)) {
        return true;
    }

    if (preg_match('/^\d+\s+[A-Za-z0-9 .\-]+$/i', $part)) {
        return true;
    }

    return false;
}
```

E dentro de `extractInstitutions()`:

```php
foreach ($parts as $part) {
    if ($this->looksLikeAddress($part)) {
        continue;
    }

    // segue a lógica atual...
}
```

Para os dados já importados, a alternativa mais segura é criar um comando Symfony que percorra `document.institutions`, remova os ruídos e salve o JSON novamente.

---

### 5.7. Retina Care Int

Provável clínica/organização médica, mas sem validação suficiente.

Se confirmado:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Retina Care Int',
    'Retina Care International',
    'Clínica/organização médica',
    'baixa',
    'Confirmar nome completo na afiliação original.'
);
```

---

### 5.8. Rooney Heart Inst

Provável instituto/centro médico.

Se confirmado:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Rooney Heart Inst',
    'Rooney Heart Institute',
    'Instituto/centro médico',
    'baixa',
    'Confirmar hospital ou sistema de saúde associado na afiliação completa.'
);
```

---

### 5.9. Natl Sch Elect & Telecommun Sfax

Identificação: `École nationale d'électronique et des télécommunications de Sfax (ENET’Com)`, ligada à `Université de Sfax`.

Cadastrar como unidade:

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Natl Sch Elect & Telecommun Sfax',
    'École nationale d''électronique et des télécommunications de Sfax (ENET''Com)',
    'Escola de ensino superior',
    'alta',
    'Variação abreviada do Scopus para ENET''Com, vinculada à Université de Sfax.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'Université de Sfax'
           OR official_name = 'University of Sfax'
        LIMIT 1
    )
);
```

---

### 5.10. Inst Tecnol Quim & Biol ITQB NOVA

Identificação: `Instituto de Tecnologia Química e Biológica António Xavier (ITQB NOVA)`, da `Universidade NOVA de Lisboa`.

Cadastrar como unidade:

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Inst Tecnol Quim & Biol ITQB NOVA',
    'Instituto de Tecnologia Química e Biológica António Xavier (ITQB NOVA)',
    'Instituto de pesquisa e educação avançada',
    'alta',
    'Variação abreviada do Scopus para ITQB NOVA.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'Universidade NOVA de Lisboa'
           OR official_name = 'NOVA University Lisbon'
           OR sigla = 'NOVA'
        LIMIT 1
    )
);
```

---

### 5.11. Anant Bajaj Retina Inst

Provável instituto/clínica oftalmológica. Precisa validação.

Cadastro provisório, se confirmado:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Anant Bajaj Retina Inst',
    'Anant Bajaj Retina Institute',
    'Instituto/clínica oftalmológica',
    'baixa',
    'Confirmar nome completo e país na afiliação original.'
);
```

---

### 5.12. Agr Informat Inst Chinese Acad Agr Sci

Identificação: `Agricultural Information Institute, Chinese Academy of Agricultural Sciences`.

Cadastrar como unidade da CAAS:

```sql
INSERT INTO instituicao_unidades (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation,
    parent_institution_id
)
VALUES (
    'Agr Informat Inst Chinese Acad Agr Sci',
    'Agricultural Information Institute, Chinese Academy of Agricultural Sciences',
    'Instituto de pesquisa',
    'alta',
    'Variação abreviada do Scopus para Agricultural Information Institute da CAAS.',
    (
        SELECT id
        FROM instituicoes_ensino
        WHERE official_name = 'Chinese Academy of Agricultural Sciences'
           OR sigla = 'CAAS'
        LIMIT 1
    )
);
```

Se a CAAS ainda não estiver cadastrada, crie primeiro a instituição-pai.

---

### 5.13. Borgess Heart Inst

Provável organização médica.

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Borgess Heart Inst',
    'Borgess Heart Institute',
    'Instituto/centro médico',
    'média-baixa',
    'Provável unidade médica. Confirmar vínculo institucional completo antes de usar em relatório final.'
);
```

---

### 5.14. Agr Res Stn ARS

Item ambíguo.

Pode significar:

```text
Agricultural Research Station
Agricultural Research Service
Agricultural Research Station de alguma universidade
Agricultural Research Station de agência nacional/local
```

Não cadastre globalmente.

Procedimento:

```sql
SELECT id, title, countries, institutions
FROM document
WHERE project_id = 5
  AND institutions LIKE '%Agr Res Stn ARS%';
```

Depois cadastre conforme país e afiliação completa.

---

### 5.15. SINTEF Energy Res

Identificação: `SINTEF Energy Research`.

Se o objetivo for apenas tirar dos não encontrados:

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'SINTEF Energy Res',
    'SINTEF Energy Research',
    'Organização de pesquisa',
    'alta',
    'Variação abreviada do Scopus para SINTEF Energy Research.'
);
```

Se quiser vincular ao dashboard como instituição:

1. crie `SINTEF` em `instituicoes_ensino` como instituto/organização de pesquisa;
2. crie `SINTEF Energy Research` em `instituicao_unidades` com `parent_institution_id` apontando para `SINTEF`.

---

### 5.16. Sustainable Energy Catapult Ctr

Nome provável, mas ainda não validado.

Não cadastre como instituição acadêmica.

```sql
INSERT INTO organizacoes (
    original_variation_name,
    canonical_name,
    type,
    confidence,
    observation
)
VALUES (
    'Sustainable Energy Catapult Ctr',
    'Sustainable Energy Catapult Centre',
    'Centro/organização de inovação em energia',
    'baixa',
    'Nome não validado com segurança. Confirmar se corresponde a Energy Systems Catapult ou outro Catapult Centre.'
);
```

---

## 6. Ajuste recomendado no sistema

### 6.1. Adicionar blacklist de endereços no importador

Hoje o problema dos endereços precisa ser bloqueado na origem.

Sugestão:

```php
private function looksLikeAddress(string $part): bool
{
    $part = trim($part);

    return (bool) preg_match(
        '/^\d+\s+[A-Za-z0-9 .\-]+\s+(Rd|Road|Dr|Drive|St|Street|Ave|Avenue|Blvd|Boulevard|Ln|Lane|Way)$/i',
        $part
    );
}
```

Dentro do loop de instituições:

```php
foreach ($parts as $part) {
    if ($this->looksLikeAddress($part)) {
        continue;
    }

    // lógica atual de detecção de instituição
}
```

### 6.2. Criar tabela de variações para organizações e unidades

Atualmente `organizacoes` e `instituicao_unidades` aceitam apenas um `original_variation_name` por registro.

Para longo prazo, o ideal é criar:

```text
organizacao_variacoes_nome
instituicao_unidade_variacoes_nome
```

Assim você não precisará duplicar registros para cada variação abreviada do Scopus.

### 6.3. Resolver nomes compostos

Adicionar tratamento para nomes com:

```text
&
and
/
```

Exemplo:

```text
Interact Technol Inst ITI LARSyS & ARDITI
```

O sistema deveria conseguir separar:

```text
Interact Technol Inst ITI LARSyS
ARDITI
```

e resolver cada um individualmente.

### 6.4. Usar país como critério para siglas ambíguas

Para casos como `INIA` e `ARS`, a resolução não deveria ser apenas por nome. O ideal é usar também o país do documento.

Exemplo:

```text
Inst Nacl Invest Agr INIA + Uruguay → Instituto Nacional de Investigación Agropecuaria (INIA)
Inst Nacl Invest Agr INIA + Spain → Instituto Nacional de Investigación y Tecnología Agraria y Alimentaria (INIA-CSIC)
```

---

## 7. Ordem recomendada de aplicação

1. **Não cadastrar endereços.**
   - Remover/ignorar `30 Xueyuan Rd`, `12127 Old Oaks Dr`, `1180 Ctr Dr`, `5510 Nathan Shock Dr`, `118 Parr St`, `1280 Montgomery Blvd`.

2. **Cadastrar unidades de alta confiança.**
   - ITQB NOVA
   - ENET’Com Sfax
   - Agricultural Information Institute / CAAS
   - Interactive Technologies Institute / ITI-LARSyS
   - SINTEF Energy Research

3. **Cadastrar centro de média confiança.**
   - International Centre for Energy and Environmental Sustainability Research / University of Uyo

4. **Revisar manualmente nomes ambíguos.**
   - INIA
   - ARS
   - SAFIR
   - Power Nutr
   - Retina Care Int
   - Rooney Heart Inst
   - Anant Bajaj Retina Inst
   - Sustainable Energy Catapult Ctr

5. **Rodar novamente a sincronização.**

```bash
php bin/console cache:clear
```

Depois acessar:

```text
https://127.0.0.1:8001/projects/5/sync-geography
```

e clicar em:

```text
Executar Sincronismo
```

---

## 8. Resultado esperado

Depois dos cadastros de alta confiança e da limpeza de endereços, a lista de não encontrados deve cair bastante.

Provavelmente ainda ficarão apenas os itens que exigem validação manual:

```text
Sustainable AgriFoodtech Innovat & Res SAFIR
Inst Nacl Invest Agr INIA
Power Nutr
Retina Care Int
Rooney Heart Inst
Anant Bajaj Retina Inst
Agr Res Stn ARS
Sustainable Energy Catapult Ctr
```

Esses não devem ser resolvidos cegamente, porque podem gerar cadastros errados e distorcer os relatórios bibliométricos.

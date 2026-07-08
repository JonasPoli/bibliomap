# Regras de normalização para importação

## Países

### Regra EUA

Se o valor seguir o padrão:

```text
SIGLA_ESTADO CEP USA
```

Exemplo:

```text
CA 95616 USA
```

Aplicar:

```text
country_name = Estados Unidos
state_sigla = CA
postal_code = 95616
```

Não adicionar `CA 95616 USA` em `paises.csv`.

### Regra país real

Se o valor for um país real ausente, adicionar em `paises.csv` com:

```text
official_name, common_name, sigla, iso_code, continente, nationality, status, variations
```

## Instituições

### Universidade/instituição acadêmica

Se for uma variação como:

```text
Univ Fed Campina Grande
```

Cadastrar a instituição canônica:

```text
Universidade Federal de Campina Grande
```

E adicionar a variação na tabela de variações.

### Ministério, agência, governo, organismo internacional

Se contém:

```text
Minist, Govt, Dept, Agcy, USDA, NASA, NOAA, FAO, UNDP, World Bank
```

Mover para `organizacoes`.

### Empresa

Se contém:

```text
Corp, Ltd, LLC, Inc, GmbH, SA, Srl, Pvt Ltd, Co Ltd, Microsoft, IBM, Bayer, BASF, Google
```

Mover para `organizacoes` com `organization_type = Empresa privada`.

### Hospital

Se contém:

```text
Hosp, Hospital, Med Ctr, Clinic, Health Network
```

Mover para `organizacoes`. Se for hospital universitário, cadastrar em `instituicao_unidades` e vincular à universidade principal.

### Unidade interna

Se contém departamento, centro, laboratório, campus ou programa:

```text
Dept, Ctr, Lab, Campus, Program, Programa, School of, Faculty
```

Cadastrar em `instituicao_unidades`, não em `instituicoes`.

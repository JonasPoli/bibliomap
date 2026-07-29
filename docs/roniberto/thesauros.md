o arquivo docs/roniberto/thesauro_Instituicoes_2020.the é um arquivo no formato de Thesaurus,
Esse trecho é um **tesauro de padronização do VantagePoint**. Ele serve para reunir diferentes maneiras de escrever o nome de uma mesma instituição em um único termo padronizado.

## 1. Termo principal

```text
**#universidade federal de são carlos
```

Os dois asteriscos `**` indicam o **termo principal**, também chamado de termo preferencial ou termo agrupador.

Portanto, todas as variações listadas abaixo serão agrupadas como:

```text
#universidade federal de são carlos
```

O caractere `#` faz parte do nome escolhido pelo autor do tesauro. Nesse caso, provavelmente foi usado para identificar visualmente que se trata de uma instituição padronizada.

No VantagePoint, os termos iniciados por `**` são os itens principais; as linhas iniciadas por `100 1` são os termos subordinados que serão incorporados ao principal. ([The VantagePoint][1])

---

## 2. Variantes da instituição

Cada linha iniciada por:

```text
100 1
```

representa uma forma alternativa encontrada nos dados.

Por exemplo:

```text
100 1 ^cca ufscar$
```

Significa:

> Quando encontrar exatamente `cca ufscar`, substitua ou agrupe essa ocorrência em `#universidade federal de são carlos`.

Assim, o resultado seria:

```text
cca ufscar
        ↓
#universidade federal de são carlos
```

O conjunto `100 1` deve ser entendido como um **marcador de termo subordinado**, e não como quantidade, frequência, porcentagem ou nível de relevância.

---

## 3. Significado de `^` e `$`

Esses símbolos são expressões regulares:

```text
^cca ufscar$
```

* `^` indica o **começo do texto**;
* `$` indica o **fim do texto**.

Juntos, eles determinam uma correspondência exata.

Portanto:

```text
^cca ufscar$
```

encontra:

```text
cca ufscar
```

mas não encontra:

```text
pesquisador cca ufscar
cca ufscar departamento
antigo cca ufscar campus
```

Esse recurso evita que uma expressão curta seja encontrada indevidamente dentro de um nome maior. A própria documentação do VantagePoint recomenda `^` e `$` para construir tesauros de correspondência exata. ([The VantagePoint][1])

---

## 4. O que o bloco está dizendo

Em linguagem comum, o trecho significa:

> Considere todas as instituições, departamentos, centros e formas abreviadas abaixo como pertencentes à Universidade Federal de São Carlos.

Por exemplo:

| Forma encontrada nos dados | Forma padronizada                  |
| -------------------------- | ---------------------------------- |
| `cca ufscar`               | Universidade Federal de São Carlos |
| `ccbs ufscar`              | Universidade Federal de São Carlos |
| `cech ufscar`              | Universidade Federal de São Carlos |
| `dept fis ufscar`          | Universidade Federal de São Carlos |
| `dept enfermagem ufscar`   | Universidade Federal de São Carlos |
| `dema ufscar`              | Universidade Federal de São Carlos |
| `cmdmc liec ufscar`        | Universidade Federal de São Carlos |

Isso é especialmente útil em dados da Web of Science ou Scopus, porque uma mesma universidade pode aparecer com inúmeras grafias, abreviações e nomes de departamentos.

Sem o tesauro, o programa poderia contar:

```text
cca ufscar                         5 publicações
ccbs ufscar                        8 publicações
dept fis ufscar                    3 publicações
universidade federal de sao carlos 20 publicações
```

como instituições diferentes.

Depois da aplicação do tesauro, todas seriam agrupadas:

```text
Universidade Federal de São Carlos: 36 publicações
```

---

## 5. Por que as palavras estão abreviadas

Algumas linhas aparecem assim:

```text
^dept engn mat ufscar$
^dept hidrobiol ufscar$
^agroecol desenvolvimento rural ufscar pesquis$
```

Provavelmente os dados passaram por um processo anterior de limpeza ou abreviação. Assim:

* `dept` = departamento;
* `engn mat` = engenharia de materiais;
* `hidrobiol` = hidrobiologia;
* `agroecol` = agroecologia;
* `pesquis` = pesquisa, pesquisador ou alguma forma truncada pelo tratamento dos dados.

O `$` em `pesquis$` **não significa “qualquer palavra que comece com pesquis”**. Ele apenas indica que `pesquis` deve estar no final da expressão. Portanto, a correspondência continua sendo exata para a frase inteira:

```text
agroecol desenvolvimento rural ufscar pesquis
```

---

## 6. Linhas duplicadas

Estas linhas aparecem duas vezes:

```text
100 1 ^cac sor fed univ sao carlos$
100 1 ^cac sor fed univ sao carlos$
```

Isso é apenas uma duplicação no arquivo.

Em princípio, as duas regras fazem exatamente a mesma coisa. A repetição não acrescenta uma nova equivalência e pode ser removida para deixar o tesauro mais organizado:

```text
100 1 ^cac sor fed univ sao carlos$
```

---

## 7. Como ler uma linha completa

Considere:

```text
100 1 ^cdmf ufscar univ fed sao carlos$
```

A leitura seria:

* `100 1`: esta é uma variante subordinada;
* `^`: a variante deve começar exatamente aqui;
* `cdmf ufscar univ fed sao carlos`: texto que será procurado;
* `$`: o texto deve terminar exatamente aqui;
* termo de destino: `#universidade federal de são carlos`.

Em forma de regra:

```text
SE o nome encontrado for exatamente
"cdmf ufscar univ fed sao carlos"

ENTÃO agrupar como
"#universidade federal de são carlos"
```

## Síntese

A estrutura geral é:

```text
**TERMO PADRONIZADO
100 1 ^VARIAÇÃO 1$
100 1 ^VARIAÇÃO 2$
100 1 ^VARIAÇÃO 3$
```

No seu exemplo:

```text
**#universidade federal de são carlos
```

é a instituição padronizada, enquanto todas as linhas `100 1` são grafias, abreviações, centros ou departamentos que devem ser contabilizados como parte da **Universidade Federal de São Carlos**.

[1]: https://www.thevantagepoint.com/_Analyst_Guide_Online_/Getting%20Started/Reference-Thesaurus_files.html?utm_source=chatgpt.com "Thesaurus Files - The VantagePoint"



# O que fazer com isso:
Vamos criar importador e exportador de Thesaurus para:
https://127.0.0.1:8000/admin/institutions
https://127.0.0.1:8000/admin/geography
https://127.0.0.1:8000/admin/authors
https://127.0.0.1:8000/admin/keywords

## vamos adicionar o controle de Thesaurus nas revistas
Novo campor para Edição em Massa das Variações
em
https://127.0.0.1:8000/admin/journals/60/edit
e aqui:
https://127.0.0.1:8000/admin/journals
um exportar e importar Thesaurus


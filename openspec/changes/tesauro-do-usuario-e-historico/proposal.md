# Proposal

## Why

Continuação de `tesauro-em-camadas` (fases 0 a 3 publicadas em 12/11/2026). Falta: o tesauro pessoal do usuário (vale só nos trabalhos dele, sobrescreve o global e é aplicado como padrão em todos os projetos dele), o tratamento do histórico de mesclagens antigas (o banco não registra quem as fez; a regra "administrador → global, usuário comum → só dele" valerá daqui para frente) e as autoridades que ainda são apagadas (instituições, países, estados, cidades, revistas).

## What Changes

- **Fase 4**: camada `scope='user'` (importar e exportar `.the`/CSV por usuário), "congelar o global" num projeto (copia os mapeamentos globais em uso para o escopo do projeto), lista de divergências entre o global e as exceções, "adotar o global".
- **Fase 5** (opcional): restaurar o id bruto e o texto original onde mesclagens antigas os apagaram, a partir dos arquivos guardados em `var/uploads`, e converter as fusões antigas em mapeamento (global se foram administrativas; sem autoria, decisão do Jonas).
- **Fase 6**: instituições, geografia e revistas sem apagar (inativação com redirecionamento); cache das análises por projeto; tipo da palavra-chave copiado para o vínculo, se o projeto 9 pedir.

## Capabilities

### New Capabilities
- `autoridade-do-usuario`: tesauro pessoal do usuário sobre o padrão global.

## Impact

Telas de normalização e de administração, importação/exportação de tesauro, cópia de projeto, cache das análises. Sem decisões pendentes para começar a fase 4; a fase 5 depende de decisão do Jonas sobre a autoria das fusões antigas.

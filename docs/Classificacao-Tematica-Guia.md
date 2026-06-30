# Guia: Reproduzindo a Classificação do PPGCTS no BiblioMap

**Referência original:** `/Users/jonaspoli/work/html/ufscar-ppgcts-classificador/classifica.php`  
**Painel de destino:** `https://127.0.0.1:8000/projects/5/classification`

---

## Como funciona o motor de classificação

O motor processa cada documento do corpus em 4 etapas ordenadas:

```
① Ruído      → documento com termo proibido → descartado
② Validador  → documento SEM termo de IA → descartado  
③ Temático   → primeiro grupo (por posição) com match → classificado
④ Sem grupo  → nenhum match → aguarda revisão manual
```

---

## PASSO 1 — Criar o Grupo Validador

Acesse o painel → clique **"+ Novo Grupo"**

| Campo       | Valor                                                                 |
|-------------|-----------------------------------------------------------------------|
| Nome        | Validador de IA                                                       |
| Tipo        | ✅ Validador                                                           |
| Cor         | `#f59e0b`                                                             |
| Ícone       | `bi-shield-check`                                                     |
| Posição     | `0`                                                                   |
| Descrição   | Documento deve conter ao menos um desses termos para ser sobre IA     |

**Termos** — cole no campo de termos:
```
intelligence,intelligent,machine learning,language model,neural,algorithm,vision,robot,uav,drone,sensor,iot,llm,genai,generative ai,gpt,transformer,automation,chatbot,assistant,agentic,conversational,virtual agent,planning,rag
```

---

## PASSO 2 — Criar o Grupo de Ruído (Falsos Positivos)

| Campo       | Valor                                                                 |
|-------------|-----------------------------------------------------------------------|
| Nome        | Ruído / Falso Positivo                                                |
| Tipo        | 🚫 Ruído                                                              |
| Cor         | `#ef4444`                                                             |
| Ícone       | `bi-ban`                                                              |
| Posição     | `1`                                                                   |
| Descrição   | Documentos com esses termos são falsos positivos e serão descartados  |

**Termos** — cole no campo de termos:
```
ragtime,rag darbari,shukla,russian researchers,kilovoltage x-ray,radiation therapy,mammography,colonoscopy,endoscopy,serum cholesterol,cerebral infarction,dietary lipids,diabetic retinopathy,chromosome translocation,calmodulin,smooth-muscle myosin,anesthesia,acute toxicity of,ecotoxicological effects,fipronil on,paraquat influenced,herbicide evaluation,weed control in,insecticide formulation,cholinesterase activity,snowpack water-equivalent,snow indices,snow cover depth,listeria monocytogenes,haccp,anemia detection in broiler to aia,chronostratigraphic,longithorols
```

---

## PASSO 3 — Criar os 12 Grupos Temáticos

Crie cada grupo com tipo **Normal**, na ordem de posição indicada.

---

### Grupo 1 — Posição `10`

| Campo   | Valor                          |
|---------|--------------------------------|
| Nome    | Assistência Virtual e Educação |
| Cor     | `#8b5cf6`                      |
| Ícone   | `bi-chat-dots`                 |

**Termos:**
```
chatbot,conversational,virtual assistant,voice assistant,avatar,educational technology,students,learning,tutoring,knowledge graphs,extension service,advisory,telescreening
```

---

### Grupo 2 — Posição `20`

| Campo   | Valor                                    |
|---------|------------------------------------------|
| Nome    | Diagnóstico e Proteção Fitossanitária    |
| Cor     | `#10b981`                                |
| Ícone   | `bi-bug`                                 |

**Termos:**
```
disease,pest,pathogen,weed,herbicide,infestation,protection,glyphosate,atrazine,diabetes,retinopathy
```

---

### Grupo 3 — Posição `30`

| Campo   | Valor                               |
|---------|-------------------------------------|
| Nome    | Visão Computacional e Fenotipagem   |
| Cor     | `#3b82f6`                           |
| Ícone   | `bi-camera`                         |

**Termos:**
```
computer vision,image sensing,spectral,hyperspectral,remote sensing,phenotyping,object detection,segmentation
```

---

### Grupo 4 — Posição `40`

| Campo   | Valor                    |
|---------|--------------------------|
| Nome    | Robótica e Mecanização   |
| Cor     | `#f97316`                |
| Ícone   | `bi-robot`               |

**Termos:**
```
robot,uav,drone,autonomous,machinery,tractor,harvester
```

---

### Grupo 5 — Posição `50`

| Campo   | Valor                          |
|---------|--------------------------------|
| Nome    | Cadeia Produtiva e Qualidade   |
| Cor     | `#06b6d4`                      |
| Ícone   | `bi-boxes`                     |

**Termos:**
```
supply chain,agribusiness,agro-industry,agritech,traceability,blockchain,food security,logistics,food model,mineral intakes
```

---

### Grupo 6 — Posição `60`

| Campo   | Valor                       |
|---------|-----------------------------|
| Nome    | Mercado, Risco e Previsão   |
| Cor     | `#eab308`                   |
| Ícone   | `bi-graph-up`               |

**Termos:**
```
forecasting,market,economic,financial,risk,price,decision making,business model,investment
```

---

### Grupo 7 — Posição `70`

| Campo   | Valor                                   |
|---------|-----------------------------------------|
| Nome    | Governança, Gestão e Impacto Social     |
| Cor     | `#ec4899`                               |
| Ícone   | `bi-people`                             |

**Termos:**
```
ethics,ethical,policy,digital divide,digital inclusion,empower,gender,inequality,technology acceptance,tam,utaut,labor,employment
```

---

### Grupo 8 — Posição `80`

| Campo   | Valor                         |
|---------|-------------------------------|
| Nome    | Clima, Solo e Meio Ambiente   |
| Cor     | `#22c55e`                     |
| Ícone   | `bi-cloud-sun`                |

**Termos:**
```
weather,climate,sustainable,carbon,pollution,residue,emissions,ecological,renewable,habitats
```

---

### Grupo 9 — Posição `90`

| Campo   | Valor             |
|---------|-------------------|
| Nome    | Recursos Hídricos |
| Cor     | `#0ea5e9`         |
| Ícone   | `bi-droplet`      |

**Termos:**
```
irrigation,water,hydrological,moisture,groundwater
```

---

### Grupo 10 — Posição `100`

| Campo   | Valor          |
|---------|----------------|
| Nome    | IoT e Sensores |
| Cor     | `#a855f7`      |
| Ícone   | `bi-cpu`       |

**Termos:**
```
internet of things,iot,sensor,wsn,cybersecurity
```

---

### Grupo 11 — Posição `110`

| Campo   | Valor                              |
|---------|------------------------------------|
| Nome    | Agricultura de Precisão e Cultivo  |
| Cor     | `#84cc16`                          |
| Ícone   | `bi-flower1`                       |

**Termos:**
```
precision agriculture,smart farm,yield,fertilizer,seeding,orchard
```

---

### Grupo 12 — Posição `120`

| Campo   | Valor                      |
|---------|----------------------------|
| Nome    | IA: Arquiteturas e Métodos |
| Cor     | `#6366f1`                  |
| Ícone   | `bi-diagram-3`             |

**Termos:**
```
large language model,llm,generative ai,genai,gpt,neural network,deep learning,machine learning,algorithm,transformer,reinforcement,ontology,simulation,modeling,artificial intelligence,fuzzy,agentic,planning,rag
```

---

## PASSO 4 — Executar a Classificação

No painel principal, clique **"Executar Classificação"**.

O motor irá processar todos os documentos do projeto em sequência:

1. Verifica se o documento contém ao menos um termo do grupo **Validador de IA**
   - Se **não** → vai para Ruído automaticamente
2. Verifica se o documento contém algum termo de **Ruído**
   - Se **sim** → descartado como falso positivo
3. Percorre os 12 grupos temáticos **na ordem da posição**
   - O primeiro grupo cujos termos derem match vence
4. Documentos sem nenhum match → **"Sem Classificação"** (para revisão manual)

> ⚠️ Re-executar sobrescreve os resultados anteriores, exceto movimentações manuais marcadas como Override.

---

## PASSO 5 — Revisar os Resultados

Acesse **"Ver Resultados"** após a execução.

Para cada grupo você pode:

- **Ver o abstract** expandível com o **termo de match destacado** em amarelo
- **Ver as palavras-chave** do documento em chips coloridos
- **Mover** o documento para outro grupo usando o dropdown lateral
- **Copiar o nome reduzido** (≤250 chars) para uso externo
- Acessar o **DOI** e o **Sci-Hub** pelo ícone de chave 🔑

---

## PASSO 6 — Ajustar e Re-executar

Após revisar os resultados:

1. Identifique termos que faltam em algum grupo → edite o grupo e adicione
2. Identifique falsos positivos que passaram → adicione o termo ao grupo de Ruído
3. Clique **"Re-executar"** na tela de resultados para reprocessar com os ajustes
4. Repita até estar satisfeito com a distribuição

---

## PASSO 7 — Exportar CSV

Clique **"Exportar CSV"** para baixar a planilha com:

- Título, Ano, DOI
- Grupo atribuído e Tipo
- Termo que causou a classificação
- Se foi movido manualmente
- Data da classificação

O arquivo CSV vem com codificação **UTF-8 com BOM** para abrir corretamente no Excel.

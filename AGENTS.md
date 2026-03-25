# AGENTS.md — Diretriz Mestre: Jogo da Amazônia
> Atualizado após testes de campo do MVP (13/03/2026).
> Este arquivo é a referência de codificação e geração de conteúdo para todos os agentes.

---

## 🎯 Visão do Produto

Um plugin WordPress de autoria para jornalistas que utiliza RAG para transformar o acervo da
Amazônia Real (450 MB / ChromaDB) em dilemas de impacto socioambiental interativos.

---

## 🧠 Lógica de Geração — A Matriz de 52

Para cada tema (ex: `'Crise Climática'`), o sistema gera uma **matriz completa de 52 perguntas**,
dividida em dois níveis de linguagem:

| Nível | Público-alvo | Linguagem |
|---|---|---|
| **Curumim** | Estudantes / Ensino Médio | Direta, simplificada, acessível |
| **Guardião** | Profissionais / Adultos | Sóbria, focada em dilemas éticos e políticos complexos |

### Distribuição por Nível (26 perguntas cada)

- **6 Perguntas Gerais** — focadas em problemas coletivos do tema
- **20 Perguntas Específicas** — 4 perguntas para cada um dos 5 Personagens:

| Personagem | Perspectiva |
|---|---|
| 🌿 Ambientalista | Defesa territorial, biodiversidade, direitos da floresta |
| 🪶 Indígena | Soberania, território, saberes tradicionais |
| 🏛️ Político | Legislação, pressão de lobbies, governança |
| 💼 Empresário | Interesse econômico, licenciamento, impacto produtivo |
| 🏙️ Cidadão | Consumo, consciência, ação coletiva |

---

## 🎮 Mecânica e Conteúdo

### Dilemas Reais
- As perguntas devem apresentar cenários extraídos da **síntese de múltiplas matérias via RAG**,
  nunca de um fato isolado. Isso garante densidade jornalística e evita alucinação.

### Respostas
- **3 alternativas curtas** por pergunta.
- Focadas em **ações e consequências** reais.
- Sem binário certo/errado — cada escolha carrega um custo e um benefício.

### Botão `i` (Contexto Jornalístico)
- Popup com síntese jornalística gerada pelo RAG.
- Link para a reportagem original (`link_original`).

### Veredito de Impacto
- Ao final do jogo, o jogador recebe um **perfil narrativo** baseado no acumulado de escolhas.
- Exemplos:
  - `"Suas decisões ajudaram a salvar o planeta."`
  - `"Suas escolhas priorizam ganhos imediatos com consequências desastrosas."`
  - `"Você equilibrou progresso e conservação — mas a floresta ainda espera mais."`

---

## 🛠️ Arquitetura Técnica

### Princípio: Independência de Layout
- O foco de desenvolvimento é a **geração do JSON** e a **lógica do Shortcode PHP**.
- O design visual (CSS/tema) será finalizado em fase posterior.
- Nunca bloquear progresso por questões de apresentação.

### Princípio: Human-in-the-loop
- O Python gera o rascunho (JSON / TXT) para que o **editor humano valide** antes da publicação.
- Nenhum conteúdo vai direto ao WordPress sem revisão editorial.

### Stack Confirmada (pós-MVP)
- **Geração:** Python + LangChain + Gemini (`gemini-2.5-flash`, `temperature=0.3`)
- **Embeddings:** `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`
- **Banco vetorial:** ChromaDB (`./chroma_db`)
- **Estrutura de dados:** Pydantic → JSON → `./dilemas_gerados/`
- **WordPress:** CPT `dilema_amazonia` + Shortcode `[dilema-amazonia id="slug"]`
- **Renderização:** Filtro `the_content` (MVP sem Gutenberg)
- **Importação:** Admin Page + WP-CLI (`wp jda import`)

### Lições do Campo (13/03/2026)
- `register_post_meta` com chaves `_prefixadas` exige `'auth_callback' => '__return_true'` explícito.
- `object_subtype` não deve ser passado nos `$args` de `register_post_meta`.
- O filtro `the_content` + `is_singular('dilema_amazonia')` é a rota de renderização mais robusta.
- Gutenberg pode apresentar erro `removeChild` por conflito de tema/outro plugin — investigar isoladamente.

---

## 📋 Regras Gerais de Comportamento

- **Idioma obrigatório:** Português do Brasil em todo conteúdo gerado.
- **Fonte de dados:** `acervo_amazonia.txt` (450 MB, indexado no ChromaDB).
- **Integridade narrativa:** Nunca fragmentar ou descontextualizar o texto das matérias.
- **Modelo padrão:** `gemini-2.5-flash` (substituiu o Gemini 1.5 Pro).
- **Temperatura:** `0.3` para fatos; aumentar para `0.7` apenas em textos criativos de veredito.

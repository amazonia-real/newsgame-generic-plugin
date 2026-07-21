# AGENTS.md — Diretrizes de Geração de Conteúdo
> Referência para agentes IA e desenvolvedores que trabalham com o sistema de geração de dilemas.
> Atualizado para refletir a arquitetura do News Game Plugin universal.

---

## 1. Princípios Fundadores

### Human-in-the-loop
O sistema gera rascunhos — não publica automaticamente. Cada dilema passa
por revisão editorial antes de entrar em produção. Nenhuma lógica deve
contornar esse princípio criando pipelines de publicação automática.

### Sem binário certo/errado
Dilemas não são perguntas de quiz com resposta correta. Cada alternativa
carrega um custo real e um benefício real. O objetivo é revelar tensões de
valor, não testar conhecimento ou induzir culpa.

### Densidade jornalística
Cenários devem emergir da síntese de múltiplas matérias via RAG — nunca
de um fato isolado. Isso garante contexto e reduz risco de alucinação.
Se o corpus RAG não tiver contexto suficiente para um slot, o slot é descartado.

### Contexto sempre rastreável
Todo dilema tem um `info_contextual` com síntese jornalística e link
para a reportagem original. O link vem obrigatoriamente do campo `SOURCE_URL`
no contexto RAG — nunca inventado ou suposto pelo modelo.

---

## 2. Arquitetura de Instância

Cada instância do jogo define:

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| `theme_prompt` | string | Identidade do acervo — instrui o modelo sobre o domínio |
| `characters` | `CharacterDef[]` | Personagens da instância (slug, label, descrição) |
| `num_options` | `2` ou `3` | Número de alternativas por dilema |
| `enable_levels` | bool | Habilita nível COMPLEXO para os dilemas gerais |
| `nivel_padrao` | string | Nível narrativo padrão: `ludico`, `pedagogico` ou `complexo` |
| `density` | int | Quantidade total de dilemas a gerar |

**Personagens são definidos pelo editor por instância** — não há personagens
hardcoded no sistema. Um jogo sobre eleições terá personagens diferentes de
um jogo sobre saúde pública.

**Eixos de tensão são inferidos por `/analyze-domain`** a partir do corpus
fornecido pelo editor — não há eixos fixos. Ver seção 4 para detalhes.

---

## 3. Níveis Narrativos

Cada dilema é gerado em um dos três níveis. O nível determina o eixo temático,
a pergunta geradora e o tratamento dos nomes próprios.

### LÚDICO — Eixo narrativo/curiosidade
- **Pergunta geradora:** O que aconteceu de verdade?
- Nomes próprios **devem** aparecer — são a isca dramática
- As alternativas falsas são hipotéticos plausíveis ("o que poderia ter acontecido")
- O veredito sempre revela o fato verdadeiro e corrige as hipóteses
- A posição da alternativa correta pode rodar entre A, B e C a cada geração

### PEDAGÓGICO — Eixo moral/identificação
- **Pergunta geradora:** E se fosse com você?
- Nomes próprios **devem ser ocultados** no enunciado e nas alternativas
- A situação é escrita na 2ª ou 3ª pessoa universal ("Você descobre...", "Um gestor precisa...")
- As três alternativas representam escolhas morais, não fatos históricos

### COMPLEXO — Eixo crítico/estético
- **Pergunta geradora:** Que problema estrutural isso revela?
- Expõe paradoxo ou trade-off sistêmico sem solução fácil
- Nomes próprios podem aparecer como referência crítica e analítica
- As três alternativas representam posições distintas — estéticas, políticas ou epistemológicas
- O veredito conecta o caso particular a uma lei mais ampla

---

## 4. As 6 Regras de Geração

Estas regras estão embutidas no `SYSTEM_PROMPT` de `generator.py`. São
a especificação de qualidade que governa cada chamada ao LLM.

### Regra 1 — Elemento Permanente

Identifique o que é eterno no texto: o que não morre com a data, temporada
ou contexto geográfico/financeiro específico.

O **plano perecível** (datas, locais, valores de ingresso, temporadas encerradas)
deve ser banido da `situacao` e das `alternativas`, servindo apenas como âncora
factual no veredito.

> Correto: "Uma companhia de teatro enfrenta a decisão de manter um espetáculo
> em cartaz com baixa bilheteria:"
>
> Errado: "O teatro SESC Santo Amaro apresentou A Falecida de setembro a novembro
> de 2024 com ingressos a R$30:"

### Regra 2 — Eixo Temático por Nível

Escolha o eixo conforme o nível recebido (ver seção 3). O eixo determina
o que está em jogo em cada alternativa — não misture eixos narrativo e crítico
no mesmo dilema.

### Regra 3 — Arquitetura das Alternativas

Monte as alternativas seguindo esta distribuição:

- **A:** a escolha mais intuitiva, que o senso comum faria — a resposta fácil
- **B:** a escolha que o texto-fonte revela como real ou mais sofisticada
- **C:** a escolha mais complexa, negociada ou contraditória — a terceira via

O campo `alternativa_correta` é **sempre obrigatório** e deve conter `"A"`, `"B"`
ou `"C"`. No nível LÚDICO, a posição da correta pode variar. Nos níveis
PEDAGÓGICO e COMPLEXO, aponta para a alternativa mais embasada no texto-fonte.

Quando `num_options` for `2`, gerar apenas A e B. Omitir C inteiramente.

### Regra 4 — Consequências como Projeção

Cada consequência é uma extensão realista do dilema — nunca punição ou
recompensa óbvia. A consequência deve:

- Ter o mesmo tamanho emocional da alternativa
- Apenas deslocar o problema, não resolvê-lo
- Deixar o jogador pensando: "mas mesmo assim eu ainda escolheria essa opção"

> Errado: "Perde tudo e vai à falência."
>
> Correto: "Mantém a operação, mas o desgaste da equipe começa a aparecer
> nos próximos ensaios."

### Regra 5 — Estrutura do Veredito

O veredito tem três partes obrigatórias e distintas, mapeadas em campos
separados do schema:

| Parte | Campo | O que contém |
|-------|-------|-------------|
| Desfecho | `desfecho_real` | O fato verificável extraído do texto-fonte. Factual, sem opinião. |
| Lição | `licao` | Frase de alcance universal e abstrato — válida fora do contexto da matéria. |
| Síntese | `info_contextual.sintese` | Retorno ao texto específico, conectando o universal ao particular. |

As três partes devem ser distintas. Não repetir o mesmo conteúdo com palavras
diferentes entre desfecho e síntese.

### Regra 6 — Controle do Risco de Ficção (nível lúdico)

Para o nível LÚDICO, as alternativas falsas são hipotéticos plausíveis:
- Use verbos no condicional implícito — nunca afirme fatos que não ocorreram
- "Poderia ter seguido outro caminho" — sim. "Foi para o Rio" (quando não foi) — não.
- O veredito sempre corrige e revela o que de fato aconteceu

---

## 5. Schema de Saída

O LLM deve retornar **apenas um objeto JSON puro**, sem texto antes ou depois,
sem blocos markdown. O schema é validado por Pydantic em `models.py`.

Campos principais de `DilemaNg`:

| Campo | Limite | Notas |
|-------|--------|-------|
| `titulo` | 3 palavras, minúsculas | Apenas indexação interna — não aparece na tela |
| `situacao` | 130 chars | **Deve terminar obrigatoriamente com `:`** |
| `alternativas[].texto` | 90 chars | Verbo no presente do indicativo |
| `alternativas[].consequencia` | — | Mesmo tamanho emocional do texto |
| `alternativa_correta` | `"A"`, `"B"` ou `"C"` | Nunca vazio, nunca texto livre |
| `desfecho_real` | 2–3 frases | Factual, baseado no texto-fonte |
| `licao` | 1–2 frases | Universal e abstrata |
| `info_contextual.sintese` | 2–3 frases | Retorno ao particular |
| `info_contextual.link_original` | URL válida | Extraída do `SOURCE_URL` do RAG — nunca inventada |

---

## 6. Eixos de Tensão e Veredito Final

Os eixos são gerados por `/analyze-domain` a partir do corpus do editor.
Cada instância tem seu próprio `AxisSet` com três `AxisOption`:

```
eixo1, eixo2, eixo3 → cada um com: nome, descrição, polo_positivo, polo_negativo
```

Os pesos das alternativas (`-20` a `+20` por eixo) são atribuídos por
`/assign-weights` após a geração dos dilemas.

O veredito final (`/generate-verdict`) não usa esses eixos diretamente na
narrativa do jogador — seleciona o perfil mais próximo por distância
euclidiana e gera texto empático, não-moralizante, com:

- Nome do perfil
- Texto da força dominante (2–3 frases)
- Texto do ponto cego (2–3 frases, sem julgamento moral)
- Chamada de compartilhamento (1 frase convidativa)

---

## 7. Anti-Padrões

O que o gerador **não deve fazer**, baseado em erros recorrentes:

| Anti-padrão | Por que é problema |
|-------------|-------------------|
| Usar conteúdo dos exemplos few-shot (teatro, Nelson Rodrigues, Othon Bastos) na geração real | Os exemplos calibram formato e ritmo — nunca conteúdo |
| Retornar texto fora do objeto JSON (explicações, blocos ` ```json `) | Quebra o parser `_extract_json()` e a validação Pydantic |
| Deixar `alternativa_correta` vazio ou com texto como "não aplicável" | Campo obrigatório — o sistema aplica fallback `"B"` automaticamente, mas isso mascara a falha |
| Mencionar datas, preços ou temporadas encerradas em `situacao` ou `alternativas` | Viola Regra 1 — torna o dilema perecível |
| Escrever `situacao` sem terminar em `:` | Requisito do schema; a ausência indica dilema mal formado |
| Inventar ou supor `link_original` | Deve ser extraído de `SOURCE_URL` no contexto RAG; URL inventada quebra o botão de contexto |
| Criar consequências como punição explícita ("perde tudo", "é demitido") | Viola Regra 4 — consequência deve deslocar o problema, não punir |
| Ocultar nomes próprios no nível LÚDICO | Viola Regra 2 — no LÚDICO, nomes são a isca. Ocultamento é regra do PEDAGÓGICO |
| Revelar nomes próprios no nível PEDAGÓGICO | Viola Regra 2 — o PEDAGÓGICO exige universalização completa |
| Gerar `titulo` com mais de 3 palavras ou com letras maiúsculas | Campo de indexação interna; nunca aparece na interface |
| Gerar dilema a partir de fato único e isolado | Viola o princípio de densidade jornalística — síntese de múltiplas matérias é obrigatória |

---

## 8. Segurança e Comunicação Backend

O backend Python (`backend-python/app/`) **não é acessado diretamente pelo
browser em nenhum ponto**. Toda chamada do painel WordPress passa por um
proxy PHP (`admin-ajax`/`wp_remote_post`), que:

- Injeta o header `X-Api-Key` server-to-server — a chave nunca chega ao JS do navegador
- É a única superfície autenticada: as rotas do backend exigem
  `Depends(_verify_token)`, com o token comparado via `secrets.compare_digest`
  (proteção contra timing attack) e sem valor default (o servidor recusa
  subir sem `API_SECRET_TOKEN` configurado)

Por isso **não há `CORSMiddleware`** no backend — não há necessidade, pois
nenhuma origem de browser faz requisição direta a ele.

A chave do Gemini segue prioridade própria, resolvida a cada chamada às
rotas `/analyze-domain`, `/assign-weights` e `/generate-verdict`:
`header X-Gemini-Api-Key` → `body.gemini_api_key` → `env GEMINI_API_KEY`
(ver `CLAUDE.md` §5).

Detalhe histórico das decisões acima: `TECH_FLAGS.md` FLAGS 003 e 005.

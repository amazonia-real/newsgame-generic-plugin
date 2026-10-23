# Guia de Portabilidade — News Game Plugin
> Como instalar e configurar o plugin para um novo domínio editorial, do zero.

---

## Pré-requisitos

| Componente | Versão mínima | Notas |
|------------|---------------|-------|
| WordPress | 6.0 | PHP 8.1+ |
| Python | 3.11 | Para o backend FastAPI |
| pip + venv | — | Incluídos no Python 3.11+ |
| Chave de API de **um** provedor de IA | — | Gemini, DeepSeek, OpenAI ou Claude. O Gemini tem nível gratuito — [aistudio.google.com](https://aistudio.google.com/) |
| ChromaDB | — | Instalado via pip; sem servidor separado necessário |

**Não é necessário** instalar ChromaDB como serviço: ele é uma biblioteca Python
que cria um banco local no diretório configurado em `CHROMA_DB_DIR`.

---

## 1. Instalação do backend

```bash
# Clone o repositório
git clone https://github.com/amazonia-real/news-game.git
cd news-game/backend-python

# Crie e ative o ambiente virtual
python -m venv .venv
.venv\Scripts\activate          # Windows
# source .venv/bin/activate     # Linux/macOS

# Instale as dependências
pip install -r requirements.txt

# Configure as variáveis de ambiente
copy .env.example .env          # Windows
# cp .env.example .env          # Linux/macOS
```

Edite o `.env` com os valores do seu ambiente:

```env
# Obrigatório: a chave de UM provedor, no .env ou no painel do WordPress
# (ver a tabela abaixo). Com a chave no painel, pode ficar em branco aqui.
GEMINI_API_KEY=sua-chave-gemini-aqui

# Caminho do banco vetorial — ajuste para o seu sistema
# Windows:
CHROMA_DB_DIR=C:/caminho/para/news-game/backend-python/data/chroma_db
# Linux/macOS:
# CHROMA_DB_DIR=/home/usuario/news-game/backend-python/data/chroma_db

# Token de autenticação entre o plugin WP e o backend
# Gere qualquer string aleatória longa (ex: openssl rand -hex 32)
API_SECRET_TOKEN=seu-token-secreto-aqui

# Opcional — padrões já configurados
LLM_MODEL=gemini-3.6-flash
LLM_TEMPERATURE=0.3
```

Basta **uma** chave: a do provedor que você escolher em **News-Game → Configurações →
Provedor de IA**. Os nomes exatos:

| Provedor | Campo no painel | Variável no `.env` | Modelo padrão (variável) |
|----------|-----------------|--------------------|--------------------------|
| Gemini (padrão) | Gemini API Key | `GEMINI_API_KEY` | `gemini-3.6-flash` (`LLM_MODEL`) |
| DeepSeek | DeepSeek API Key | `DEEPSEEK_API_KEY` | `deepseek-v4-flash` (`LLM_MODEL_DEEPSEEK`) |
| OpenAI | OpenAI API Key | `OPENAI_API_KEY` | `gpt-5.6-luna` (`LLM_MODEL_OPENAI`) |
| Claude | Claude API Key | `CLAUDE_API_KEY` (não `ANTHROPIC_API_KEY`) | `claude-sonnet-5` (`LLM_MODEL_CLAUDE`) |

Suba o backend:

```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000
```

Confirme que está rodando:

```bash
curl http://localhost:8000/health
# Resposta esperada: {"status":"ok","version":"1.0.0"}
```

---

## 2. Instalação do plugin WordPress

### Opção A — ZIP (recomendada para quem só quer instalar)

1. Baixe o arquivo `news-game-plugin-<versão>.zip` da release: [LINK DA RELEASE — a publicar junto do repositório]
2. No painel WordPress, vá em **Plugins → Adicionar novo → Enviar plugin**, escolha o
   zip e clique em **Instalar agora**
3. Clique em **Ativar**

O **backend Python não vem no zip**: ele é instalado à parte, no servidor, como na §1.

### Opção B — copiar a pasta ou link simbólico (desenvolvimento)

Copie a pasta `news-game-plugin/` para o diretório de plugins do WordPress:

```
wp-content/
  plugins/
    news-game-plugin/       ← pasta copiada aqui
      news-game.php
      admin/
      includes/
      public/
```

Em ambiente de desenvolvimento, um link simbólico evita duplicação:

```bash
# Windows (prompt de administrador)
mklink /D "C:\xampp\htdocs\wordpress\wp-content\plugins\news-game-plugin" "C:\caminho\para\news-game\news-game-plugin"

# Linux/macOS
ln -s /caminho/para/news-game/news-game-plugin /var/www/html/wordpress/wp-content/plugins/news-game-plugin
```

### Ativar e configurar

1. No painel WordPress, vá em **Plugins → Plugins instalados**
2. Localize **News-Game** e clique em **Ativar**
3. Vá em **News-Game → Configurações** e preencha:

| Campo | Valor |
|-------|-------|
| Nome da Organização | O nome do seu veículo (ex: "Seu Veículo") |
| Provedor de IA e a chave dele | O provedor escolhido e a chave correspondente (campo da tabela da §1) |
| URL do Backend Python | `http://localhost:8000` (ou a URL pública do seu servidor) |
| Token do Backend | O mesmo valor definido em `API_SECRET_TOKEN` no `.env` |

> A chave do provedor pode ser configurada aqui **ou** diretamente no `.env` do
> backend. O backend usa a primeira que encontrar, nesta ordem: o cabeçalho que o
> plugin envia (a chave do painel), o corpo da requisição e, por último, a variável do
> `.env`. Com o campo do painel em branco, vale a do `.env`.

### Cache do site — depois de instalar ou atualizar o plugin

Se o site usa plugin de cache (LiteSpeed Cache, WP Rocket, W3 Total Cache e afins) ou
CDN, **esvazie o cache de página e o de CSS/JS** depois de instalar ou atualizar o
News Game — no LiteSpeed Cache, **"Purge All"**. A **minificação** e a **combinação
de CSS/JS** desses plugins geram cópias próprias dos arquivos e podem prender uma versão
antiga, mesmo com o plugin já atualizado: o jogo aparece com a skin ou o comportamento
anteriores.

Em teste com o LiteSpeed Cache, as skins Minimal e Pixel só apareceram depois do
"Purge All". O plugin versiona os próprios arquivos pela
data de modificação, o que resolve o cache do navegador, mas não o cache que o plugin de
cache guarda no servidor.

O cache costuma ter camadas (plugin de cache e cache do próprio servidor): depois de
trocar a skin ou atualizar o plugin, limpe todas.

---

## 3. Sincronizar o acervo

O acervo são os posts publicados no WordPress que servirão de base para gerar os dilemas.
O plugin extrai e indexa automaticamente esses textos no banco vetorial (ChromaDB).

1. Ainda em **News-Game → Configurações**, role até a seção **Acervo (Vault)**
2. Ela mostra quantos itens estão indexados e o caminho do vault local
3. Clique em **Sincronizar Acervo** — o plugin lê os posts via WP REST API,
   salva os textos como JSON no vault e envia para o backend indexar no ChromaDB

**Corpus mínimo recomendado:** 20 posts. Quanto mais artigos e mais diversidade
temática, maior a qualidade e variedade dos dilemas gerados.

Sempre que publicar novos posts relevantes, sincronize o acervo antes de gerar
novos dilemas.

---

## 4. Primeira instância — do acervo ao jogo publicado

Todo o fluxo de configuração, geração e revisão acontece em **uma única página**:
**News-Game → Instâncias → Nova Instância** (ou "Editar" em uma instância existente).

### Passo 1 — Identificação

Preencha o **Nome do Jogo** — este nome aparece no painel e gera automaticamente
o shortcode exibido na listagem de instâncias (ex: `[news-game id="1"]`).

### Passo 2 — Contexto de IA

Preencha a **Descrição do Acervo**: descreva o tema, o tipo de cobertura e o
público-alvo. Este texto é enviado à IA como contexto de geração. Redija-a como se fosse um prompt para uma IA.

> Exemplo: "Cobertura jornalística das eleições municipais brasileiras de 2026,
> com foco em candidatos de primeiro mandato e abstenção eleitoral."

Clique em **Analisar Acervo e Propor Eixos** — o backend consulta o ChromaDB,
lê o corpus e propõe 3 eixos de tensão de valor + 4–6 perfis de jogador.
Revise os eixos sugeridos antes de prosseguir.

### Passo 3 — Configuração

| Campo | O que define | Limite |
|-------|-------------|--------|
| Meta de perguntas aprovadas | O **conjunto**: quantas perguntas aprovadas a instância guarda e a geração tenta produzir | 4 a 120 (não menor que as perguntas por partida) |
| Perguntas por partida | A **partida**: quantas perguntas cada pessoa responde. Cada partida sorteia essa quantidade entre as aprovadas, em ordem embaralhada; com menos aprovadas, usa todas | 4 a 15 |
| Opções de Resposta | 2 opções (A/B) ou 3 opções (A/B/C) | — |
| Perfil do Jogo | Tom narrativo: Lúdico / Pedagógico / Complexo | — |

### Passo 4 — Capa e regras (opcional)

Na seção recolhível **Capa e regras**: título da capa, linha-fina, foto, logo e texto
de apoio, **Regras** (com texto, o jogo mostra uma tela de regras entre a capa e a
primeira pergunta) e **Abrir em página inteira** (ver a seção sobre o tema, abaixo).
Tudo vazio: o jogo usa a capa padrão.

### Passo 5 — Personagens do Jogo (opcional)

Seção recolhível. Adicione os personagens clicando em **+ Adicionar Personagem**.
Cada personagem tem:
- **Nome / Label** — como aparece no painel (ex: "Diretor de teatro")
- **Descrição para a IA** — instrução de perspectiva enviada à IA
  (ex: "Representa a perspectiva crítica de quem dá vida a um espetáculo")
- **Apresentação** (opcional) — texto mostrado ao jogador na tela de escolha

O interruptor **Perguntar ao jogador qual personagem quer ser** liga a tela de escolha;
desligado, o jogo sorteia o personagem. A tela só aparece com 2 ou mais personagens
Prontos.

Sem personagens: o motor usa **Modo Perguntas Gerais** — dilemas sem perspectiva
de personagem fixo.

**Receita da partida.** Com personagens, a partida combina perguntas **gerais** e
perguntas de **um** personagem: as gerais ocupam até 50% da partida (padrão), com piso
de 1 por grupo. Cada grupo tem a própria meta de aprovadas: 1,6 vez o que ele põe na
partida. Um grupo com aprovadas ≥ meta está **Pronto**; personagem não Pronto fica
escondido do jogador. O painel de geração trabalha por grupo.

Clique em **Salvar e Preparar para Geração →** para salvar e continuar.

### Passo 6 — Gerar as perguntas

Na seção **Geração de Perguntas** (parte inferior da mesma página):

- O painel mostra quantas perguntas já existem e quantas serão geradas
- Clique em **Gerar Perguntas Agora** — o backend gera um dilema por vez
  e salva em `wp_newsgame_dilemas`; o progresso é exibido em tempo real
- **Limpar e Resetar Todas as Perguntas** apaga tudo e permite recomeçar
  do zero (operação irreversível)

### Passo 7 — Revisar na Mesa Editorial

Abaixo da seção de geração está a **Mesa Editorial**, com três abas:

| Aba | O que contém |
|-----|-------------|
| **Pendentes** | Dilemas gerados aguardando revisão |
| **Aprovadas** | Dilemas aprovados — estes aparecem no jogo |
| **Descartadas** | Dilemas rejeitados (fora do jogo, mas preservados) |

Revise cada dilema pendente:
- Aprove os satisfatórios — ou use **Aprovar Todas as Pendentes** se o lote
  estiver bom
- Descarte os insatisfatórios — o backend pode gerar substitutos para
  completar a meta de perguntas

### Passo 8 — Publicar

1. Crie uma nova página no WordPress (ou use uma existente)
2. Insira o shortcode com o ID numérico da instância, visível na listagem:
   ```
   [news-game id="1"]
   ```
3. Publique a página — o plugin carrega automaticamente os dilemas aprovados,
   injeta o CSS/JS do jogo e configura o viewport

### Tema do site: coluna centralizada ou página inteira

A partir de 600 px de largura, o jogo **rompe a coluna de conteúdo do tema** para
ocupar a largura da janela, com a foto de fundo de ponta a ponta. O rompimento é feito
por **margens**: o bloco do jogo é deslocado meia coluna para um lado e meia janela
para o outro (`newsgame-game.css`, "Rompimento de container"). A conta só fecha se a
coluna de conteúdo estiver **centralizada** na janela (testado no Twenty Twenty-Five).
Abaixo de 600 px não há rompimento: no celular a coluna do tema já ocupa a largura
toda.

**Tema com coluna lateral** — conteúdo encostado num lado, com barra lateral ao lado,
como no tema **Newspaper** —: o rompimento sai **deslocado**. Nesse caso, ligue
**Abrir em página inteira (esconde o tema do site)**, na seção **Capa e regras** da
instância. Com ela, o jogo sai do fluxo do tema e ocupa a janela inteira, por cima
dele, com um link **← Voltar ao site** para a página inicial; quem rola é o jogo, e
não a página.

Limites do rompimento e do modo página inteira, registrados no CSS:

- um contêiner do tema com `overflow: hidden` **recorta** o rompimento, e não há
  contorno pelo lado do plugin — use a página inteira;
- a página inteira só esconde a rolagem do tema quando o shortcode está **no conteúdo
  da página**. Inserido por construtor de página ou template, o jogo ocupa a janela,
  mas a página atrás dele continua rolando;
- para quem está logado, a barra de administração do WordPress fica **atrás** do jogo.

Testado no tema Twenty Twenty-Five.

### Imagens: formatos e tamanhos

O jogo usa imagens da **Biblioteca de Mídia** do WordPress: a foto e o logo da capa
(seção **Capa e regras** da instância) e a foto de fundo de cada pergunta (na Mesa
Editorial).

| Formato | Situação |
|---------|----------|
| **JPEG** e **PNG** | Funcionam sempre. Testados de ponta a ponta (seletor de mídia, gravação e capa) no localhost |
| **WebP** | O plugin **não filtra** o formato, mas quem decide é o WordPress, **no envio**: ele precisa da extensão **GD** ou **Imagick** do PHP com suporte a WebP. Sem ela, o envio é recusado ("O servidor web não pode gerar tamanhos de imagem responsivos para esta imagem. Converta-a para JPEG ou PNG antes de enviá-la."). Visto no XAMPP local, com a GD desligada; **não testado** em servidor com GD |
| **SVG** | O WordPress **recusa** no envio, por padrão. Mesmo com um plugin que libere SVG, o salvamento da instância o descarta, porque `wp_attachment_is_image()` não considera SVG imagem. Não libere: SVG pode carregar script |

Tamanhos recomendados:

- **Foto da capa:** **1600 × 900 px**, horizontal (16:9), mínimo 1280 × 720, até
  ~300 KB. Aparece acima do título, com até 544 px de largura e no máximo 40% da altura
  da tela; imagem vertical ou quase quadrada é cortada em cima e embaixo. O jogo pede ao
  WordPress o tamanho `newsgame-bg` (1600 px de largura, sem recorte). É decorativa:
  sem texto alternativo.
- **Logo do apoio:** PNG com fundo transparente, desenho recortado sem margem, **~96 px
  de altura**, até 300 px de largura e até 50 KB. Aparece com até 48 px de altura e
  160 px de largura; o jogo pede o tamanho `medium` do WordPress. O texto alternativo é
  o texto do apoio.

---

## 5. Auditoria de caminhos — portabilidade garantida

O plugin não contém caminhos absolutos hardcoded. Todos os caminhos de arquivo
e URLs usam as funções nativas do WordPress:

```php
// URL pública dos assets (CSS, JS, imagens) — resolve automaticamente
// para qualquer domínio ou subdiretório
plugin_dir_url( __FILE__ )
// Ex: http://meusite.com/wp-content/plugins/news-game-plugin/

// Caminho do servidor (para includes e operações de arquivo)
plugin_dir_path( __FILE__ )
// Ex: /var/www/html/wp-content/plugins/news-game-plugin/
```

Isso garante que o plugin funcione sem alteração em:

| Ambiente | URL resultante |
|----------|---------------|
| XAMPP local | `http://localhost/wordpress/wp-content/plugins/news-game-plugin/` |
| VPS com domínio | `https://seuveiculo.com.br/wp-content/plugins/news-game-plugin/` |
| Subdiretório | `https://site.com/blog/wp-content/plugins/news-game-plugin/` |
| Multisite WP | Cada site resolve para o seu próprio caminho |

Isso vale para os **arquivos** do plugin. Ao mudar de ambiente, **dois** endereços
importam, e não um só:

1. **A URL do Backend Python**, em News-Game → Configurações — varia conforme onde o
   FastAPI está rodando.
2. **A URL do próprio site** (`home_url()` do WordPress). Ela **dá nome ao índice do
   acervo no backend**: o plugin a envia como `site_id` na indexação, na geração e na
   substituição de perguntas, e o backend guarda o acervo numa coleção do ChromaDB
   chamada `ng_` + essa URL, sem o `http://` ou `https://`, com `/`, `.` e `:` trocados
   por `_` e cortada em 63 caracteres (`get_collection()`, em
   `backend-python/app/rag.py`). Exemplo: `http://localhost/jda` vira
   `ng_localhost_jda`; `https://www.seuveiculo.com.br` vira
   `ng_www_seuveiculo_com_br`.

**Ao trocar a URL do site** — de `localhost` para o domínio, de domínio, ou para outro
subdiretório —, o backend passa a procurar o acervo numa coleção **nova, vazia**, e a
geração fica sem as matérias do veículo. Para refazer:

1. **Sincronizar Acervo** (News-Game → Configurações): regrava o vault com os links
   novos das matérias. Cada item guarda o endereço completo do post, e é dele que sai
   o link de origem das perguntas geradas daí em diante.
2. **Indexar Acervo no Backend**: cria a coleção com o nome novo.

O que **não** muda e **não** precisa ser refeito:

- trocar só `http://` por `https://` — o prefixo sai do nome da coleção;
- as imagens da capa e das perguntas — o plugin guarda o **ID** do anexo, não a URL.

O que **não** se ajusta sozinho:

- **as perguntas já geradas** guardam o link de origem (`source_url`) **completo**,
  com o endereço antigo — é ele que o botão "Aprofundar" abre. Corrija na Mesa
  Editorial (campo de link de cada pergunta) ou gere de novo;
- **a coleção antiga continua no backend.** O plugin não tem botão para apagá-la.

> Esta seção descreve o código; a troca de URL de ponta a ponta **não foi provada neste
> ambiente**.

---

## 6. Checklist de portabilidade

Use esta lista ao adaptar o plugin para um novo domínio editorial:

### Acervo
- [ ] Acervo sincronizado em News-Game → Configurações antes de gerar dilemas
- [ ] Mínimo de 20 posts indexados
- [ ] Os posts cobrem perspectivas diferentes do domínio (não apenas um ponto de vista)

### Eixos de tensão (após "Analisar Acervo e Propor Eixos")
- [ ] Os 3 eixos emergem do acervo específico — não são genéricos (ex: evitar
  "Ambiental × Econômico" para qualquer tema)
- [ ] Cada eixo tem polo positivo e polo negativo claramente distintos
- [ ] Os perfis de jogador fazem sentido narrativo para o domínio
- [ ] Se os eixos parecerem fracos, revise a **Descrição do Acervo** e re-analise

### Dilemas gerados (Mesa Editorial)
- [ ] A situação de cada dilema não menciona datas, preços ou eventos encerrados
- [ ] As alternativas apresentam trade-offs reais — sem opção obviamente certa
- [ ] O veredito cita fatos verificáveis do acervo, não afirmações vagas
- [ ] Dilemas com link de origem suspeito ou inventado foram descartados

### Publicação
- [ ] O shortcode usa o ID numérico correto da instância: `[news-game id="N"]`
- [ ] A instância está com o selo **Pronto** na listagem de instâncias (eixos e perfis
  completos, todas as aprovadas com peso, grupo de perguntas gerais — e ao menos um
  personagem, se houver — com aprovadas ≥ a meta dele). Em **Em revisão**, a listagem
  mostra o que falta
- [ ] Testou o jogo num dispositivo mobile antes de publicar
- [ ] A URL do backend Python está acessível a partir do servidor WordPress
  (não apenas de `localhost`)

### Segurança (antes de ir ao ar)
- [ ] **Token do Backend** em News-Game → Configurações é uma string aleatória longa,
  idêntica ao `API_SECRET_TOKEN` do backend (obrigatório e sem valor padrão — o
  FastAPI se recusa a subir sem ele)
- [ ] A chave do provedor de IA não está exposta em logs nem versionada no git
- [ ] O backend não está exposto diretamente na internet sem autenticação —
  use o token ou coloque o FastAPI atrás de um proxy reverso (nginx)

---

## 7. Modelo de segurança

> Para quem aprova a instalação (TI, segurança, direção). Descreve o que o código faz
> hoje; o que não foi provado está marcado como tal.

### As três peças e quem fala com quem

| De | Para | Como |
|----|------|------|
| Navegador do **jogador** | o próprio site WordPress | Carrega a página, o CSS, o JS, as fontes e as imagens, **todos do próprio site**. As perguntas aprovadas vão na página, como dado (`<script type="application/json">`). O resultado final (o veredito) é **calculado no navegador**, sem chamada ao backend nem ao provedor de IA durante a partida |
| Navegador do **editor** | o próprio site WordPress | Telas do plugin no painel, que exigem a permissão `manage_options` (administrador) |
| **Servidor** WordPress (PHP) | backend Python | Só o PHP fala com o backend, servidor a servidor. O navegador — do editor ou do jogador — **nunca** chama o backend: não há rota do backend aberta a outra origem (sem CORS) |
| Backend Python | provedor de IA | Chamadas de geração e análise, com a chave do provedor |

O jogador não envia nada ao servidor durante a partida: o plugin **não grava** respostas
nem resultados. Se o site já tiver o Google Analytics (`gtag` ou `dataLayer`), o jogo
envia eventos de uso para ele; o plugin não carrega script de medição próprio. Os
eventos são `ng_inicio` (`n`, `conjunto`), `ng_pergunta_vista` (`etapa`, `total`,
`dilema_id`, `dilema_titulo`), `ng_resposta` (os da pergunta mais `alternativa`,
`posicao` e, quando há pesos, `peso_1` a `peso_3`) e `ng_fim` (`tela`, que é `veredito`
ou `generica`, e `perfil` no veredito). Todos levam também `instancia`, `skin` e
`partida_n`. Nenhum dado do jogador.

### Autenticação entre o WordPress e o backend

- Toda chamada do plugin ao backend leva o **Token do Backend** no cabeçalho
  `X-Api-Key`. O backend o compara em tempo constante (`secrets.compare_digest`) com o
  `API_SECRET_TOKEN` do `.env` dele: diferente, **401**; sem token configurado no
  servidor, **503**. A única rota aberta é `GET /health`, que só responde "ok".
- **TLS:** o plugin verifica o certificado do backend em todas as chamadas. A única
  exceção é o ambiente de desenvolvimento — `WP_DEBUG` ligado **e** URL com
  `localhost`.
- **Limite por editor:** as ações que gastam cota do provedor têm um teto por usuário e
  por minuto — análise do acervo 10, pesos 10, pergunta substituta 10 e geração 60. É
  proteção contra **erro operacional** (clique repetido, laço de JS), não contra abuso
  deliberado de um administrador.

### A chave do provedor de IA fica em texto claro no banco (FLAG 011)

**Limitação conhecida (FLAG 011).** O plugin não criptografa as
chaves e **não oferece mitigação técnica** para isso hoje.

**Onde estão:**

- As quatro chaves de provedor (as opções `newsgame_<provedor>_api_key`) e o Token do
  Backend (`newsgame_backend_api_key`) ficam na tabela **`wp_options`**, em **texto
  claro**. O
  salvamento só limpa o texto (`sanitize_text_field`); não há nenhuma função de
  criptografia no plugin.
- A chave do Gemini e o Token do Backend são carregados em **todo request** do
  WordPress (`autoload`), inclusive nas páginas públicas — na memória do servidor, não
  na página. As outras três chaves não.
- Na tela **News-Game → Configurações**, os campos são do tipo senha, mas o valor está
  **no código-fonte da página**.
- Se a chave também for posta no `.env` do backend, ela existe ali em texto claro, no
  disco do servidor.

**Quem pode lê-la:**

- quem é **administrador** do WordPress (permissão `manage_options`), pela tela de
  configurações;
- quem tem **acesso ao banco** do site: usuário do MySQL, phpMyAdmin, painel da
  hospedagem;
- quem tem uma **cópia do banco**: backups e exports `.sql` levam a `wp_options`
  inteira;
- **qualquer código PHP** que rode no site — outros plugins e o tema —, como acontece
  com toda opção do WordPress;
- no backend, quem lê o `.env` (por padrão, só o `root`, com permissão 600).

**O que isso implica:** quem obtiver a chave do provedor pode usá-la fora do jogo, com
custo e cota na conta do veículo. Quem obtiver o Token do Backend pode chamar o backend
diretamente — indexar texto no acervo e, se o `.env` do backend tiver uma chave de
provedor, gerar com ela.

**Mitigações recomendadas** (operacionais; nenhuma é do plugin):

1. **Acesso ao banco e ao painel:** poucas contas de administrador; usuário do banco
   sem acesso de fora do servidor; backups e exports tratados como material sensível.
2. **Chave com cota e restrição:** no console do provedor, quando ele oferecer, limite a
   chave à API de geração e ponha **teto de gasto**. Use uma chave só para o jogo, e
   não a de outros sistemas do veículo.
3. **Rotação:** troque a chave no painel do provedor e depois no campo do plugin. Se ela
   também estiver no `.env` do backend, troque **nos dois lugares** — uma cópia velha no
   `.env` só aparece como erro do provedor quando o painel deixa de mandar a chave
   (FLAG 011). Se o banco, um backup ou o servidor vazar: rotacione todas as chaves de
   provedor **e** o Token do Backend (`.env` do backend e campo do painel).
4. **Token por ambiente:** gere um Token do Backend por servidor
   (`openssl rand -hex 32`), sem reaproveitar.
5. **Chave só no backend:** o plugin manda o campo vazio quando ele está em branco, e o
   backend, nesse caso, usa a chave do `.env` dele (ordem: cabeçalho, corpo da
   requisição, `.env`). Com isso a chave **sai do banco do WordPress** e fica só no
   servidor do backend, com permissão 600. **Não provado neste ambiente.**

### Exemplo de cabeçalho CSP (Content-Security-Policy)

> ⚠️ **Não testado em servidor real.** O que foi provado: uma partida completa em
> Chrome headless, em 25/09/2026, com `script-src 'self'; style-src 'self'` injetada no
> documento — **nenhuma** violação veio do plugin. As demais diretivas abaixo saem da
> leitura do código atual, e o jogo ganhou fontes, capa e imagens depois daquela prova.

A superfície **pública** do plugin (a página com o shortcode) não precisa de
`'unsafe-inline'` nem em `script-src` nem em `style-src`: não há JS inline nem atributo
`style=` no jogo, os dados do jogo vão num bloco `application/json`, e as larguras e
fundos calculados são aplicados por CSSOM, que essas diretivas não bloqueiam. Fontes e
imagens do jogo são servidas pelo próprio site. O mínimo para o **jogo** é:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'
```

Ajustes que dependem do site, não do plugin:

- **Tema e núcleo do WordPress:** a promessa é do **plugin**. O núcleo do WordPress e o
  tema costumam emitir `<style>` e `<script>` inline — no teste de 25/09, todas as 28
  violações vieram deles (tema Twenty Twenty-Five). Com eles, o site pode precisar de
  `'unsafe-inline'` ou de *nonces* por conta própria.
- **Mídia em outro domínio** (CDN ou armazenamento externo): acrescente o domínio em
  `img-src`.
- **Google Analytics** e outras ferramentas do site: acrescente os domínios delas em
  `script-src` e `connect-src`.
- **Não aplique este cabeçalho ao painel (`/wp-admin/`).** O JS do painel do plugin não
  tem lógica inline, mas o `wp_localize_script` emite um `<script>` inline **de dado**
  por arquivo, e o próprio WordPress usa script inline no painel. O painel **não** é
  compatível com esta política.

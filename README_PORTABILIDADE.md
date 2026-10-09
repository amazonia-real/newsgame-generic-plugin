# Guia de Portabilidade — News Game Plugin
> Como instalar e configurar o plugin para um novo domínio editorial, do zero.

---

## Pré-requisitos

| Componente | Versão mínima | Notas |
|------------|---------------|-------|
| WordPress | 6.0 | PHP 8.1+ |
| Python | 3.11 | Para o backend FastAPI |
| pip + venv | — | Incluídos no Python 3.11+ |
| Google Gemini API Key | — | Gratuito no nível básico — [aistudio.google.com](https://aistudio.google.com/) |
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
# Obrigatório
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

### Copiar o plugin

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
| Nome da Organização | O nome do seu veículo (ex: "Agência Pública") |
| Gemini API Key | Sua chave da API Google Gemini |
| URL do Backend Python | `http://localhost:8000` (ou a URL pública do seu servidor) |
| Token do Backend | O mesmo valor definido em `API_SECRET_TOKEN` no `.env` |

> A Gemini API Key pode ser configurada aqui **ou** diretamente no `.env` do backend.
> Se configurada no painel, ela é enviada no body de cada requisição e tem
> precedência sobre a variável de ambiente.

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
público-alvo. Este texto é enviado ao Gemini como contexto de geração.

> Exemplo: "Cobertura jornalística das eleições municipais brasileiras de 2026,
> com foco em candidatos de primeiro mandato e abstenção eleitoral."

Clique em **Analisar Acervo e Propor Eixos** — o backend consulta o ChromaDB,
lê o corpus e propõe 3 eixos de tensão de valor + 4–6 perfis de jogador.
Revise os eixos sugeridos antes de prosseguir.

### Passo 3 — Configuração

| Campo | O que define | Limite |
|-------|-------------|--------|
| Número de Perguntas/Rodadas | Quantidade de dilemas gerados | Mínimo: 4 / Máximo: 15 |
| Opções de Resposta | 2 opções (A/B) ou 3 opções (A/B/C) | — |
| Perfil do Jogo | Tom narrativo: Lúdico / Pedagógico / Complexo | — |

### Passo 4 — Personagens do Jogo

Adicione os personagens da instância clicando em **+ Adicionar Personagem**.
Cada personagem tem:
- **Nome / Label** — como aparece no painel (ex: "Diretor de teatro")
- **Descrição para a IA** — instrução de perspectiva enviada ao Gemini
  (ex: "Representa a perspectiva crítica de quem dá vida a um espetáculo")

Sem personagens: o motor usa **Modo Perguntas Gerais** — dilemas sem perspectiva
de personagem fixo.

Clique em **Salvar e Preparar para Geração →** para salvar e continuar.

### Passo 5 — Gerar as perguntas

Na seção **Geração de Perguntas** (parte inferior da mesma página):

- O painel mostra quantas perguntas já existem e quantas serão geradas
- Clique em **Gerar Perguntas Agora** — o backend gera um dilema por vez
  e salva em `wp_newsgame_dilemas`; o progresso é exibido em tempo real
- **Limpar e Resetar Todas as Perguntas** apaga tudo e permite recomeçar
  do zero (operação irreversível)

### Passo 6 — Revisar na Mesa Editorial

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

### Passo 7 — Publicar

1. Crie uma nova página no WordPress (ou use uma existente)
2. Insira o shortcode com o ID numérico da instância, visível na listagem:
   ```
   [news-game id="1"]
   ```
3. Publique a página — o plugin carrega automaticamente os dilemas aprovados,
   injeta o CSS/JS do jogo e configura o viewport

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

Isso garante que o plugin funciona sem alteração em:

| Ambiente | URL resultante |
|----------|---------------|
| XAMPP local | `http://localhost/wordpress/wp-content/plugins/news-game-plugin/` |
| VPS com domínio | `https://agenciapublica.org/wp-content/plugins/news-game-plugin/` |
| Subdiretório | `https://site.com/blog/wp-content/plugins/news-game-plugin/` |
| Multisite WP | Cada site resolve para o seu próprio caminho |

**O único valor que precisa ser configurado por ambiente** é a **URL do Backend Python**
em News-Game → Configurações — que varia conforme onde o FastAPI está rodando.

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
- [ ] Pelo menos 10 dilemas na aba **Aprovadas** antes de publicar
- [ ] Testou o jogo num dispositivo mobile antes de publicar
- [ ] A URL do backend Python está acessível a partir do servidor WordPress
  (não apenas de `localhost`)

### Segurança (antes de ir ao ar)
- [ ] **Token do Backend** em News-Game → Configurações é uma string aleatória longa,
  idêntica ao `API_SECRET_TOKEN` do backend (obrigatório e sem valor padrão — o
  FastAPI se recusa a subir sem ele)
- [ ] A Gemini API Key não está exposta em logs nem versionada no git
- [ ] O backend não está exposto diretamente na internet sem autenticação —
  use o token ou coloque o FastAPI atrás de um proxy reverso (nginx)

"""Configurações lidas de variáveis de ambiente (.env ou Cloud Run secrets)."""
import os
from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    # OPCIONAL desde a fase 0 do H1 (2026-09-04). Era campo sem default, e o
    # servidor não subia sem ele — inconsistente com a chave poder chegar por
    # requisição. Quem valida "existe alguma chave disponível" é
    # `_resolve_api_key()` (main.py), que levanta 401 quando os três degraus
    # da cascata — header, body, este campo — estão todos vazios.
    #
    # ⚠️ `api_secret_token`, mais abaixo, CONTINUA OBRIGATÓRIO. A guarda do
    # C3/FLAG 003 (o servidor recusa subir sem token de autenticação) não foi
    # afrouxada por esta mudança — `main.py:43` chama get_settings() no import
    # justamente para falhar cedo, e continua falhando por causa dele.
    #
    # Consequência prática: um deploy que use APENAS chave por requisição
    # (vinda do painel WordPress) não precisa mais de GEMINI_API_KEY no .env.
    gemini_api_key: str = ""
    # Chave do DeepSeek — segundo provedor, integrado em 2026-09-05.
    # Opcional pelo mesmo motivo do campo acima: a chave pode chegar por
    # requisição, vinda do painel WordPress. Quem valida "existe alguma chave
    # para o provedor pedido" é a resolução de chave em main.py, que levanta
    # 401 quando todos os degraus da cascata estão vazios.
    deepseek_api_key: str = ""
    # Chave da OpenAI — terceiro provedor, integrado em 2026-09-14 (B8).
    # Opcional pelo mesmo motivo dos dois campos acima: a chave pode chegar por
    # requisição, vinda do painel WordPress.
    #
    # ⚠️ ESTE CAMPO É PRÉ-REQUISITO DE PÔR `OPENAI_API_KEY` NO .env, e não o
    # contrário. O pydantic-settings roda com extra='forbid' (ver a nota de
    # `llm_model` abaixo), então chave órfã no arquivo faz o servidor RECUSAR
    # SUBIR com ValidationError — foi o incidente de 2026-08-07 com LOG_LEVEL e
    # ANONYMIZED_TELEMETRY. Declarar aqui primeiro, popular o .env depois.
    openai_api_key: str = ""
    # Chave do Claude/Anthropic — quarto provedor, integrado em 2026-09-15 (B9).
    # Opcional pelo mesmo motivo dos três campos acima: a chave pode chegar por
    # requisição, vinda do painel WordPress.
    #
    # ⚠️⚠️ O NOME DA ENV VAR É `CLAUDE_API_KEY`, E **NÃO** `ANTHROPIC_API_KEY`.
    # Não é preferência de estilo — `ANTHROPIC_API_KEY` é um nome que o SDK da
    # Anthropic CONSOME SOZINHO, e `main.py:13` roda `load_dotenv()`, que joga
    # todo o `.env` dentro de `os.environ`. As duas coisas juntas criariam um
    # QUARTO degrau de cascata, invisível e fora da nossa resolução: qualquer
    # `ChatAnthropic` construído sem chave explícita passaria a autenticar com o
    # valor do arquivo, e o 401 de `_resolve_api_key()` nunca dispararia.
    #
    # Com `CLAUDE_API_KEY` não há colisão: o SDK não conhece esse nome, e a
    # chave só entra pelo caminho que nós controlamos. Conferido em 2026-09-15
    # no fonte do SDK (`anthropic/_client.py:214-224`): a cascata dele é
    # api_key explícito > ANTHROPIC_API_KEY > ANTHROPIC_AUTH_TOKEN >
    # ANTHROPIC_PROFILE > federação > perfil ativo em disco — cinco degraus que
    # NÃO queremos atrás dos nossos três.
    claude_api_key: str = ""
    # Provedor de LLM usado quando a requisição não informa nenhum. É o default
    # global do backend, e o painel WordPress o sobrepõe por requisição.
    #
    # ⚠️ NÃO é `Literal["gemini", "deepseek"]` de propósito: a lista fechada de
    # provedores vive num lugar só, em generator.py, e é aplicada na resolução
    # por requisição. Declará-la aqui também criaria duas cópias da mesma regra,
    # que divergem em silêncio quando um provedor entra — família do C13.
    llm_provider: str = "gemini"
    chroma_db_dir: str = "/data/chroma_db"
    embed_model: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    # ⚠️ `llm_model` É O MODELO DO GEMINI. O nome sem sufixo de provedor é
    # COMPATIBILIDADE, não descuido: `LLM_MODEL` está no .env de quem já roda o
    # backend e no .env.example distribuído, e o pydantic-settings usa
    # extra='forbid' (conferido em 2026-09-05: chave órfã no arquivo .env
    # levanta ValidationError). Renomear este campo para `llm_model_gemini`
    # faria o servidor RECUSAR SUBIR em toda instalação existente — por isso o
    # segundo provedor ganha campo com sufixo e este fica como está.
    #
    # `gemini-3.6-flash` escolhido em 2026-09-16, e a escolha NÃO foi nossa: é a
    # substituição que o PRÓPRIO ERRO do Google indicou. O `gemini-2.5-flash`
    # anterior passou a devolver 404 — "This model models/gemini-2.5-flash is no
    # longer available to new users. Please update your code to use
    # models/gemini-3.6-flash" —, e isso só aparece para chave de projeto NOVO:
    # foi a rotação da chave daquele dia que expôs o modelo morto, não uma
    # mudança nossa.
    #
    # Disponibilidade CONFIRMADA NA FONTE, nunca de memória — mesmo protocolo do
    # `deepseek-chat`: `GET /v1beta/models` com chave real em 2026-09-16 devolveu
    # 58 modelos, 41 com `generateContent`, e o `gemini-3.6-flash` entre eles.
    #
    # ⚠️ ARMADILHA MEDIDA NO MESMO DIA: `gemini-2.5-flash` APARECE no
    # `/v1beta/models` e NÃO é utilizável — `generateContent` nele dá 404.
    # Listado ≠ disponível; conferir a listagem não basta, só a geração real
    # prova.
    #
    # ⚠️ SEM CUSTO POR DILEMA MEDIDO, ao contrário dos outros três: a troca foi
    # forçada por indisponibilidade, não escolhida por preço ou latência. E
    # trocar de geração de modelo é o caso em que a FLAG 012 manda revalidar a
    # FLAG 001, porque o auto-reparo depende do formato do texto devolvido.
    llm_model: str = "gemini-3.6-flash"
    # Modelo do DeepSeek. `deepseek-v4-flash` com thinking DESLIGADO, conforme a
    # calibração de 2026-09-04: o modo thinking levou 96s contra 4,6s e estouraria
    # o teto de 120s do wp_remote_post. Nome confirmado no /models da DeepSeek com
    # chave real na mesma data — `deepseek-chat` NÃO existe mais.
    llm_model_deepseek: str = "deepseek-v4-flash"
    # Modelo da OpenAI. `gpt-5.6-luna` escolhido em 2026-09-14 (B8), com nome
    # confirmado no /v1/models com chave real — nunca de memória, que é a lição
    # do `deepseek-chat`, nome que não existe mais.
    #
    # ⚠️ A ESCOLHA NÃO VEIO DE QUALIDADE MEDIDA, e isso é honesto registrar: a
    # rodada pareada de 3 gerações contra `gpt-5.4-nano` NÃO separou os dois
    # (cada um ganhou em critérios diferentes). Os motivos foram:
    #   1. família ATUAL (5.6, junho) contra a anterior (5.4, março) — modelo
    #      descontinuado força revalidar a FLAG 001, porque o auto-reparo
    #      depende do formato do texto devolvido;
    #   2. a pior violação de `situacao` foi 151 chars contra 230 do nano;
    #   3. ~7% mais barato por dilema.
    # Trocar é UMA LINHA aqui: o `reasoning_effort="none"` do construtor cobre
    # os dois, e o nano é 1,7× mais rápido se a prioridade virar latência.
    #
    # Medido em 2026-09-14: 4,7s, US$ 0,001442/dilema (contra US$ 0,00062 do
    # DeepSeek v4-flash), reasoning_tokens=0, JSON válido na 1ª tentativa.
    llm_model_openai: str = "gpt-5.6-luna"
    # Modelo do Claude. `claude-sonnet-5` escolhido em 2026-09-15 (B9), com o
    # nome CONFIRMADO NO /v1/models COM CHAVE REAL na mesma data.
    #
    # ⚠️ A REGRA DE CONFIRMAR NA FONTE VALEU AQUI COM FORÇA EXTRA, e fica
    # registrado porque é contraintuitivo: quem escreveu esta linha foi o
    # próprio Claude, e isso NÃO É FONTE. Memória de treino tem corte e não
    # sabe o que está vivo hoje; a lista literal embutida no pacote `anthropic`
    # é de um release congelado. Valeu o mesmo protocolo do `deepseek-chat`
    # (nome que deixou de existir) e do `gpt-6-astra` (descartado antes de
    # gastar chamada) — bater na API e ler o que ela responde.
    #
    # ⚠️ `llm_temperature` NÃO SE APLICA A ESTE PROVEDOR. Medido em 2026-09-15:
    # `temperature=0.3` devolve **400** em `claude-sonnet-5` e em
    # `claude-opus-5` ("temperature is deprecated for this model"). O
    # construtor força `temperature=None` — ver a nota longa em
    # `_cliente_claude()`, que é onde a decisão mora.
    llm_model_claude: str = "claude-sonnet-5"
    llm_temperature: float = 0.3
    # RAG
    rag_fetch_k: int = 20
    rag_k: int = 5
    rag_context_window: int = 40000
    # Controle de lotes e rate limit
    max_retries_batch: int = 5
    rate_limit_sleep: int = 60
    batch_sleep: int = 3
    dilema_sleep: float = 1.0   # pausa entre chamadas individuais de dilema
    # Indexação
    chunk_size: int = 1000
    chunk_overlap: int = 150
    batch_size_index: int = 200
    # Segurança
    api_secret_token: str
    # Logging — consumido por logging_config.py via os.getenv("LOG_LEVEL").
    # Declarado aqui para que o .env seja aceito sem erro de campo extra.
    log_level: str = "INFO"
    # Telemetria — consumida pelo próprio ChromaDB via ambiente
    # (load_dotenv em main.py). Declarada aqui pelo mesmo motivo.
    anonymized_telemetry: bool = False

    model_config = {"env_file": ".env"}


@lru_cache()
def get_settings() -> Settings:
    return Settings()

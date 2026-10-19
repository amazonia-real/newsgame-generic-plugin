# Backend do News Game — imagem de produção.
# Python 3.11, usuário sem privilégios, UM worker uvicorn, sem --reload,
# porta interna 8000. Em produção quem fala com o mundo é o Caddy
# (ver ../deploy/docker-compose.yml); esta porta só existe na rede do compose.
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    # Cache do modelo de embeddings num caminho fixo, legível pelo usuário do
    # app. Antes o modelo era baixado como root para /root/.cache, e o usuário
    # sem privilégios não o enxergava: baixava de novo na primeira requisição.
    HF_HOME=/opt/hf-cache \
    CHROMA_DB_DIR=/data/chroma_db

WORKDIR /app

# Dependências do sistema para sentence-transformers e chromadb; curl para o
# HEALTHCHECK.
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc g++ curl \
    && rm -rf /var/lib/apt/lists/*

# PyTorch SÓ DE CPU, antes do requirements.txt. O torch não está no
# requirements: entra como dependência do sentence-transformers, e o pip, sem
# esta linha, instalava a versão com CUDA — 12,4 GB de imagem, com 3,2 GB de
# bibliotecas da NVIDIA e o triton, inúteis num servidor sem GPU. Instalado
# aqui primeiro, o requirements o encontra satisfeito e não o substitui.
# Versão fixa: a mesma 2.14.1 que o pip resolvia, agora no índice de CPU.
ARG TORCH_VERSION=2.14.1
RUN pip install --index-url https://download.pytorch.org/whl/cpu "torch==${TORCH_VERSION}"

# Dependências Python antes do código (melhor cache de build)
COPY requirements.txt .
RUN pip install -r requirements.txt \
    && python -c "import torch; assert torch.version.cuda is None, 'torch com CUDA'; print('torch', torch.__version__, 'CPU')"

# Pré-baixa o modelo de embedding durante o build (sem download no primeiro uso)
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2')"

COPY app/ ./app/

# Usuário sem privilégios; o volume do ChromaDB é montado em /data
RUN useradd --create-home --uid 10001 appuser \
    && mkdir -p /data/chroma_db \
    && chown -R appuser /app /data /opt/hf-cache
USER appuser

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/health || exit 1

# UM worker e SEM --reload, sempre: o --reload reinicia o servidor a cada
# mudança de arquivo e já derrubou requisições em andamento (FLAG 013).
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]

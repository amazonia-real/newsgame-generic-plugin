FROM python:3.11-slim

WORKDIR /app

# Dependências do sistema para sentence-transformers e chromadb
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc g++ curl \
    && rm -rf /var/lib/apt/lists/*

# Instala dependências Python antes de copiar o código (melhor cache de build)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Pré-baixa o modelo de embedding durante o build (evita download cold start)
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2')"

# Cria diretório de dados (volume montado em produção)
RUN mkdir -p /data/chroma_db

COPY app/ ./app/

# Usuário não-root
RUN useradd -m appuser && chown -R appuser /app /data
USER appuser

EXPOSE 8080

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8080", "--workers", "1"]

# Instalação do backend do News Game

Guia para a equipe de TI de um veículo instalar o **backend** do News Game (a API
em Python que lê o acervo e conversa com o provedor de IA) num servidor próprio.
O plugin WordPress fala com esse backend pela internet, por HTTPS, enviando uma
chave no cabeçalho `X-Api-Key`.

> Este roteiro foi executado e provado num servidor de ensaio em 18/10/2026
> (Ubuntu 24.04, 1 vCPU, 4 GB de RAM). Os números da seção
> [Medido no ensaio](#medido-no-ensaio) vêm dele.

## Requisitos

- **Linux** com acesso de administrador (testado em **Ubuntu 24.04 LTS**).
- **Docker Engine** com o plugin **docker compose** (instalados no passo 4).
  O Python vem dentro da imagem (3.11); não é preciso instalá-lo no servidor.
- **2 a 4 GB de RAM** e **1 vCPU ou mais**. O modelo de embeddings roda na CPU,
  dentro do backend.
- **Disco:** ~15 GB livres para instalar com folga (a imagem do backend tem
  4,8 GB e o primeiro build usa espaço temporário; o índice do acervo cresce com
  o número de matérias).
- **Domínio ou subdomínio** apontando para a máquina (registro DNS `A`, e `AAAA`
  se houver IPv6). Exemplo: `newsgame-api.seuveiculo.com.br`.
- **Portas 80 e 443** abertas para a internet: o Caddy usa as duas para emitir e
  renovar sozinho o certificado HTTPS (Let's Encrypt).
- **Chave de pelo menos um provedor de IA** (Gemini, DeepSeek, OpenAI ou Claude),
  que pode ficar no servidor ou ser enviada pelo painel do WordPress.

## Como fica no servidor

```
/opt/newsgame/
  docker-compose.yml   ← serviços "backend" e "caddy"
  Caddyfile            ← reverse proxy com HTTPS automático
  .env                 ← segredos e domínio; permissão 600, dono root
  .env.example         ← modelo do .env, sem segredos
  backend-python/      ← código do backend (app/, requirements.txt, Dockerfile)
```

- O **backend** (uvicorn, UM worker, porta 8000) só escuta **dentro da rede do
  compose**. Ele não publica porta nenhuma no servidor.
- O **Caddy** é o único serviço exposto ao mundo (80 e 443) e repassa as
  requisições ao backend.
- Dados persistentes ficam em **volumes do Docker**: `chroma` (o índice do
  acervo) e `caddy_data`/`caddy_config` (certificados).

## Passo a passo

Os comandos abaixo são executados como `root` no servidor, salvo indicação. Os
arquivos `deploy/docker-compose.yml`, `deploy/Caddyfile`, `deploy/.env.example` e
a pasta `backend-python/` vêm do repositório do News Game.

### 1. Atualizar o sistema e ligar as atualizações automáticas de segurança

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y upgrade
apt-get -y install unattended-upgrades apt-listchanges
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' \
  > /etc/apt/apt.conf.d/20auto-upgrades
systemctl enable --now unattended-upgrades
```

### 2. Firewall: só 22, 80 e 443

Mantenha uma sessão SSH aberta enquanto liga o firewall e confirme que uma
**segunda** conexão entra antes de fechar a primeira.

```bash
apt-get -y install ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp      # HTTP/3 do Caddy (ainda a porta 443)
ufw --force enable
ufw status verbose
```

### 3. SSH só com chave

Primeiro confirme, numa conexão **nova**, que o acesso por chave funciona. No
Ubuntu 24.04 a primeira ocorrência de cada opção vence, e a imagem de nuvem pode
trazer `PasswordAuthentication yes` em `/etc/ssh/sshd_config.d/50-cloud-init.conf`
(no ensaio, trazia). Por isso o arquivo novo começa com `00-`:

```bash
printf 'PasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin prohibit-password\n' \
  > /etc/ssh/sshd_config.d/00-newsgame.conf
sshd -t                      # valida antes de recarregar
systemctl reload ssh
sshd -T | grep -E '^(passwordauthentication|permitrootlogin) '
# esperado: passwordauthentication no · permitrootlogin without-password
```

Prove de novo com uma conexão nova antes de fechar a sessão atual.

### 4. Docker Engine e docker compose (repositório oficial)

```bash
apt-get -y install ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
docker run --rm hello-world
```

Se o servidor já vier com `/etc/apt/sources.list.d/docker.sources` apontando para o
mesmo repositório (no ensaio, a imagem do provedor trazia), não crie o
`docker.list`: o `apt` avisa que o repositório está configurado duas vezes.

### 5. Swap de 2 GB (reserva de memória)

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
printf 'vm.swappiness=10\n' > /etc/sysctl.d/99-newsgame-swap.conf
sysctl -p /etc/sysctl.d/99-newsgame-swap.conf
swapon --show
```

### 6. Enviar o código ao servidor

Sem dar credencial do GitHub ao servidor: monte o pacote na sua máquina e envie
por `scp`. O pacote leva `deploy/docker-compose.yml`, `deploy/Caddyfile`,
`deploy/.env.example` e, de `backend-python/`, só `app/`, `requirements.txt`,
`Dockerfile` e `.dockerignore` — nunca `.env`, `.venv`, dados nem logs.

```bash
# na sua máquina, na raiz do repositório
mkdir -p /tmp/ngpack/backend-python
cp deploy/docker-compose.yml deploy/Caddyfile deploy/.env.example /tmp/ngpack/
cp -r backend-python/app backend-python/requirements.txt backend-python/Dockerfile \
      backend-python/.dockerignore /tmp/ngpack/backend-python/
(cd /tmp/ngpack && tar -czf /tmp/newsgame-backend.tar.gz .)
scp /tmp/newsgame-backend.tar.gz root@SEU_SERVIDOR:/tmp/

# no servidor
mkdir -p /opt/newsgame
tar -xzf /tmp/newsgame-backend.tar.gz -C /opt/newsgame && rm /tmp/newsgame-backend.tar.gz
chown -R root:root /opt/newsgame
```

### 7. Criar o `.env`

```bash
cd /opt/newsgame
umask 077
TOKEN=$(openssl rand -hex 32)
sed -e "s|^NEWSGAME_DOMINIO=.*|NEWSGAME_DOMINIO=newsgame-api.seuveiculo.com.br|" \
    -e "s|^API_SECRET_TOKEN=.*|API_SECRET_TOKEN=${TOKEN}|" .env.example > .env
unset TOKEN
chown root:root .env && chmod 600 .env
```

- `API_SECRET_TOKEN` é a chave que o WordPress envia no cabeçalho `X-Api-Key`.
  Copie-a do `.env` para o campo **Token do Backend** nas configurações do plugin,
  sem colá-la em chat, e-mail ou chamado.
- As chaves de provedor de IA podem ficar vazias (o painel do WordPress as envia)
  ou ser preenchidas aqui. Depois de editar o `.env`, rode `docker compose up -d`
  (recria o backend com os valores novos).

### 8. Subir

```bash
cd /opt/newsgame
docker compose up -d --build
docker compose ps        # backend "healthy" e caddy "Up"
docker compose logs caddy | grep -i "certificate obtained"
```

O primeiro build leva alguns minutos (no ensaio, 4 min 19 s com 1 vCPU).

Depois de subir, libere o espaço temporário do build:

```bash
docker builder prune -f
```

### 9. Conferir de fora

```bash
curl https://SEU_DOMINIO/health
# {"status":"ok","version":"1.0.0"}   — público, sem chave

curl -X POST -H 'Content-Type: application/json' -d '{}' https://SEU_DOMINIO/ingest
# HTTP 401 {"detail":"Invalid API key"}   — sem chave, negado

curl -X POST -H 'Content-Type: application/json' -H "X-Api-Key: SUA_CHAVE" -d '{}' https://SEU_DOMINIO/ingest
# HTTP 422 (campos obrigatórios ausentes) — a chave passou; nada é indexado
```

E no servidor: `ss -tulnp` deve mostrar só 22 (sshd), 80 e 443 (docker-proxy do
Caddy). A porta 8000 não aparece — o backend só existe dentro da rede do compose.

Por fim, reinicie o servidor (`systemctl reboot`) e confira que `docker compose ps`
mostra os dois serviços de volta e que o certificado é o mesmo.

## Atualizar o backend

Envie o código novo como no passo 6 (sobrescrevendo `app/`, `requirements.txt` e
`Dockerfile`; o `.env` do servidor não é tocado) e reconstrua:

```bash
cd /opt/newsgame
docker compose up -d --build backend
docker compose ps
docker image prune -f      # remove a imagem antiga, depois de conferir que a nova subiu
```

O volume `chroma` (o acervo indexado) e os certificados não são afetados.

## Ver logs

```bash
cd /opt/newsgame
docker compose ps                    # estado e saúde dos serviços
docker compose logs -f backend       # logs do backend (Ctrl+C para sair)
docker compose logs --since 1h caddy # logs do Caddy da última hora
```

## Reiniciar

```bash
cd /opt/newsgame
docker compose restart backend   # só o backend
docker compose down && docker compose up -d   # tudo (os volumes ficam)
```

Os dois serviços têm `restart: unless-stopped`: sobem sozinhos depois de um
reinício do servidor (provado no ensaio).

## Backup do índice do acervo

O índice vetorial do acervo fica no volume `newsgame_chroma`. Pare o backend
para a cópia ficar consistente:

```bash
cd /opt/newsgame
docker compose stop backend
docker run --rm -v newsgame_chroma:/data -v /root:/backup alpine \
  tar -czf /backup/chroma-$(date +%F).tar.gz -C /data .
docker compose start backend
```

Para restaurar, com o backend parado:

```bash
docker run --rm -v newsgame_chroma:/data -v /root:/backup alpine \
  sh -c 'rm -rf /data/* && tar -xzf /backup/chroma-AAAA-MM-DD.tar.gz -C /data'
```

Guarde também uma cópia do `.env` em local seguro. O índice pode ser reconstruído
pelo painel do WordPress (reindexando o acervo), mas leva tempo.

## O que NÃO fazer

- **Nunca** rodar o uvicorn com `--reload` em produção: ele reinicia o servidor a
  cada mudança de arquivo e derruba requisições em andamento.
- **Nunca** subir mais de um worker (`--workers 1`, como está no Dockerfile).
- **Nunca** expor a porta **8000** ao mundo (nada de `ports: "8000:8000"` no
  compose): o acesso é só pelo Caddy, em HTTPS.
- **Nunca** colocar chaves no repositório nem em arquivo versionado. Elas vivem
  só no `.env` do servidor, com permissão 600.
- **Não** reutilizar a chave `X-Api-Key` entre ambientes; gere uma por servidor.
- **Atenção ao firewall:** o Docker publica portas por conta própria, passando por
  cima do `ufw`. Por isso só o Caddy publica porta no compose.

## Alternativa sem Docker (uvicorn + systemd)

**Pendente.** Não foi executada nem provada neste ensaio. O caminho seria um
ambiente virtual Python 3.11, `pip install -r requirements.txt`, uvicorn com UM
worker e sem `--reload` como serviço do systemd escutando só em `127.0.0.1:8000`,
e um proxy com HTTPS (Caddy ou nginx) na frente. Até ser testado, use o Docker.

## Medido no ensaio

Servidor de ensaio, 18/10/2026: Ubuntu 24.04.5, 1 vCPU, 3,8 GB de RAM, 48 GB de
disco, Docker 29.9.1, Compose v5.6.0, Python 3.11.17 no container.

| Medida | Antes (PyTorch com CUDA) | Agora (PyTorch só de CPU) |
|---|---|---|
| build + subida (primeira vez) | 7 min 50 s | **4 min 19 s** |
| imagem do backend | 12,4 GB | **4,8 GB** |
| disco usado no servidor (depois de limpar o cache de build) | 21–23 GB | **12 GB** de 48 GB |
| memória do backend em repouso | ~790–850 MB | **~550 MB** |
| memória do Caddy | ~53 MB | ~12–53 MB |
| memória total em uso no servidor | ~0,9–1,0 GB | ~0,9 GB de 3,8 GB; swap praticamente sem uso |

**PyTorch só de CPU.** O `torch` não está no `requirements.txt`: entra como
dependência do `sentence-transformers`, e o `pip` instalava a versão com suporte a
GPU (CUDA), com 3,2 GB de bibliotecas da NVIDIA e o `triton`, inúteis num servidor
sem GPU. O `Dockerfile` instala antes o `torch==2.14.1` do índice de CPU
(`https://download.pytorch.org/whl/cpu`), e o build falha se o torch vier com CUDA.
Teste no ensaio: o modelo de embeddings
(`paraphrase-multilingual-MiniLM-L12-v2`) gera vetores de 384 dimensões no CPU,
carregando em ~2 s, com ~0,12 s para uma frase e ~0,13 s para um lote de 32.

### Indexação do acervo (ensaio em WordPress real, 19/10/2026)

Site `site-de-ensaio.example` falando com o backend deste servidor (1 vCPU, 3,8 GB):

| Medida | Valor |
|---|---|
| itens enviados pela sincronização | 350 |
| itens indexados | 345 (5 ignorados; o painel ainda não diz quais nem por quê) |
| lotes | 7 lotes de 50 |
| tempo por lote | ~35 s |
| tempo por artigo | ~1,4 s |
| tempo total | ~4 min |

**Pendente:** a alternativa sem Docker (uvicorn + systemd), não executada nem
provada.

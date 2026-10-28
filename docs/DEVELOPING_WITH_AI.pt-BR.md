# Desenvolvimento com IA no News Game Plugin

> English version: [DEVELOPING_WITH_AI.en.md](DEVELOPING_WITH_AI.en.md)

Este documento reúne diretrizes práticas consolidadas durante o desenvolvimento do **News Game Plugin** com assistência de modelos de linguagem (Claude Code e Gemini).

O objetivo não é relatar bastidores ou conversas de desenvolvimento, mas registrar salvaguardas técnicas, arquiteturais e editoriais para três perfis que lidam diretamente com o ecossistema do plugin:

1. Desenvolvedores e engenheiros de software (que estendem o código ou mantêm o backend e o plugin)
2. Mantenedores de infraestrutura e TI (que implantam o ambiente, cuidam de segurança e servidores)
3. Editores jornalísticos e gestores de produto (que configuram narrativas, dilemas e aprovam o material no WordPress)

---

## 1. Para desenvolvedores de software

### Tratar saída de IA como dado não confiável

Respostas de LLMs não são código ou texto sanitizado por padrão. Nomes de eixos, textos de contexto e dilemas podem carregar caracteres não tratados, quebra de formatação ou marcação inesperada.

- Aplique sanitização estrita no recebimento (`sanitize_text_field` e equivalentes) e escape contextual na renderização (`esc_html`, `esc_attr`).
- Isole URLs de acervo e identificadores imutáveis em campos estruturados fora do pipeline de tradução ou reescrita, impedindo que o modelo altere domínios ou parâmetros de links.

### Verificação de runtime obrigatória contra "pronto" fluente

Assistentes de código afirmam que alterações funcionam com a mesma segurança com que erram. Revisão de código e linters estáticos (`php -l`, `npm run lint`) não enxergam incompatibilidade de dependências em tempo de execução, travamentos silenciosos, condições de corrida ou gates que falham por dado ausente.

- Toda marcação de tarefa concluída exige execução real demonstrada: comando executado, resposta obtida e estado do banco verificado antes e depois.
- Teste explicitamente o caminho do dado parcial: códigos sem erro aparente podem mascarar ausência de informação e produzir saídas enganosas para a interface do usuário.

### Regras estruturais e restrições devem ir no prompt e em filtros de banco

Modelos generativos só leem o texto do prompt. Comentários de código e esquemas Pydantic (que apenas conferem a resposta depois que ela chega) não chegam a eles.

- Restrições essenciais (como intervalos numéricos de pesos de impacto ou formato de encerramento de frases) precisam constar expressamente no texto do prompt, com exemplos realistas.
- Restrições críticas de acervo (como período de publicação, fontes ou recortes temáticos) devem ser filtros reais na consulta vetorial ou relacional, e nunca delegadas apenas como instrução em linguagem natural no prompt.

### Normalização defensiva de respostas do provedor

SDKs de IA e chamadas de API sofrem variações de contrato entre versões. Uma rota configurada para devolver texto pode retornar listas de blocos ou dicionários quando há raciocínio em segundo plano ou respostas fragmentadas.

- Unifique o tratamento de resposta em um ponto único que extraia texto puro e descarte blocos não textuais antes da validação estrutural.
- Mantenha validação rígida de JSON (Pydantic ou schema nativo) e auto-reparo automatizado em etapas delimitadas antes de devolver erro à interface.

### Desligamento de raciocínio estendido em extrações estruturadas

Em modelos que trazem modos de raciocínio profundo ativados por padrão, tarefas de extração estruturada e classificação pagam custos excessivos de latência e consumo de tokens faturados.

- Desative explicitamente parâmetros de raciocínio para manter chamadas estruturadas dentro de tempos compatíveis com timeouts de servidores web e proxies.

### Não versionar dados brutos do acervo

Arquivos brutos de um acervo jornalístico podem trazer dados pessoais de terceiros, e o Git guarda tudo.

- Higienize antes do primeiro commit. Limpar um histórico depois exige um espelho completo, a reescrita, e apagar e recriar o repositório.
- Um `push --force` não resolve: os commits antigos continuam acessíveis pela interface do GitHub por tempo indeterminado.
- Guarde os espelhos antigos longe de qualquer lugar público: eles ainda contêm os dados.

---

## 2. Para mantenedores de infraestrutura e TI

### Isolamento estrito de chaves de API e segredos

Sistemas desenvolvidos com ferramentas de apoio em linha de comando ou terminais interativos correm risco contínuo de vazamento acidental de credenciais.

- Nunca imprima saídas completas de HTML renderizado ou dumps de variáveis globais em terminais onde assistentes de código atuem.
- Mantenha chaves e tokens de serviços em variáveis de ambiente protegidas (`.env` com permissão 600 em servidores) ou nas configurações do plugin no WordPress. Estas guardam o valor sem criptografia e o mostram a quem tem permissão de administrador (FLAG 011 em [`TECH_FLAGS.md`](TECH_FLAGS.md)): restrinja quem administra o site.

### Autenticação que falha fechada

Uma proteção que se desliga quando falta configuração não protege.

- Exija a chave de acesso na inicialização: o backend recusa-se a atender (responde 503) sem `API_SECRET_TOKEN` configurado. Não use valor padrão, nem de teste.
- Compare o token em tempo constante (`secrets.compare_digest`).
- Revise a documentação junto com o código: depois de uma correção, os guias podem continuar anunciando o valor antigo.

### Arquitetura descentralizada e separação de peso computacional

Para preservar a sustentabilidade de redações locais sem orçamentos de nuvem corporativa, o processamento de busca vetorial e a orquestração de IA devem ser isolados do site principal:

- O plugin WordPress hospeda a experiência do usuário e a interface de moderação editorial.
- O backend dedicado (FastAPI + ChromaDB) executa embeddings e indexação localmente via CPU, comunicando-se com o WordPress de forma segura (server-to-server) via HTTPS e cabeçalho `X-Api-Key`.

### Tratamento e classificação de erros de API por código de status

Assistentes frequentemente sugerem mensagens genéricas de falha de conexão quando a API de IA recusa chamadas.

- Diferencie rigorosamente em código o status HTTP da resposta externa: erro de autenticação (401/403), falta de saldo ou término de cota (402/429) e modelo aposentado ou caminho inexistente (404). No 429, cota e crédito chegam com o mesmo código: use o texto da mensagem apenas como reserva.
- Nunca mascare um esgotamento de cota ou modelo descontinuado como simples "falha temporária de rede".

### Gestão em camadas de cache e arquivos estáticos

A introdução ou alteração de skins, fontes auto-hospedadas e scripts em servidores WordPress reais convive com múltiplas camadas de retenção:

- Versione arquivos públicos do plugin pela data de modificação (`filemtime`) na query string dos assets para evitar servir código antigo por cache de navegador.
- Documente a rotina de purga em plugins de cache e no servidor web (LiteSpeed, Nginx ou Caddy) antes de validar novas instâncias visuais.

### Rotinas de medição de dados antes de procedimentos de restauração

Falhas em indexadores vetoriais ou bancos de dados locais podem ocorrer por corrupção de índice, timeouts silenciosos de rede ou interrupções de processo.

- Antes de restaurar qualquer backup de banco após uma perda aparente de registros, registre metadados de estado, logs de erro e dumps do estado corrompido. A pressa em restaurar backups sem medição prévia apaga a causa raiz do incidente.

---

## 3. Para editores e gestores de produto jornalístico

### Humano no circuito: a Mesa Editorial como fronteira inegociável

A IA atua unicamente como assistente de leitura, extração e proposta de cenários a partir do acervo do veículo.

- Nenhuma pergunta ou dilema gerado vai diretamente ao leitor sem revisão, aprovação, edição textual ou substituição manual por um editor na Mesa Editorial do WordPress.

### Foco em dilema ético, e não em teste enciclopédico

Modelos de linguagem tendem naturalmente ao formato escolar de perguntas factuais com respostas corretas versus erradas.

- O prompt de concepção deve exigir sempre tensão moral, contextualização real de causas e consequências e conflitos éticos extraídos das apurações do acervo, colocando o jogador no papel de decisor e não de estudante em exame.

### Preservação do contexto jornalístico e transparência

- As decisões tomadas pelo jogador devem ser seguidas de painéis de contexto que explicam o desfecho real da matéria e a lição pública apurada pela equipe de reportagem.
- As instâncias devem sempre permitir ao leitor acessar o link original da apuração jornalística ("Aprofundar").

### Auditoria e higienização prévia de acervos

Ao preparar lotes de matérias ou acervos históricos para indexação RAG:

- Verifique se os textos originais não carregam dados pessoais de terceiros (CPF, e-mails, telefones, chaves Pix em apelos de doação e notas antigas) que, ao serem agregados em índices ou versionados em repositórios, infrinjam leis de proteção de dados.

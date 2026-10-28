# Roadmap

> **English summary.** News Game is an open-source WordPress plugin (plus a Python
> backend) that turns a newsroom's archive into journalistic-dilemma games. This is a
> direction document, not a promise: no dates. Nothing is hosted by the project; each
> newsroom runs its own backend. The sections below list what exists, what is planned
> without a date, and what the project deliberately will not do.

Este documento descreve a direção do projeto. **Não é compromisso de prazo.** Ele
é revisado a cada versão. O estado detalhado do código está nas notas de versão e na
lista de questões técnicas conhecidas (`docs/TECH_FLAGS.md`).

**Versão atual do plugin:** 1.2.0.

---

## Princípio: open source puro

Nada é hospedado pelo projeto. Cada veículo instala o plugin no seu WordPress e roda o
próprio backend (Python + ChromaDB) no servidor que escolher. Não há serviço pago, conta
de usuário nem suporte comercial.

Essa decisão veio de uma consulta informal a profissionais de redações locais
brasileiras: quase todos disseram que prefeririam que a própria equipe de TI fizesse a
instalação a contratar um serviço em nuvem. Por isso o gargalo de adoção que o projeto
trata é o **atrito de instalação**, e o investimento vai para documentação e
verificação, e não para infraestrutura.

---

## O que já existe

- Geração de dilemas jornalísticos a partir do acervo do próprio veículo (busca
  semântica no ChromaDB, com fontes rastreáveis em cada pergunta).
- Provedor de IA à escolha do editor no painel: Gemini, DeepSeek, OpenAI e Claude.
- Indexação do acervo em lotes, com retomada, para acervos de milhares de textos.
- Mesa editorial: revisão humana de cada pergunta (aprovar, editar, descartar, pedir
  substituta) antes de ir ao ar.
- Eixos e perfis de veredito propostos pela IA e confirmados pelo editor.
- Sete skins de fábrica e fundo por pergunta.
- Filtro opcional **"Ano mínimo dos textos"** por instância (1.2.0).
- Superfície pública do jogo compatível com uma política de segurança de conteúdo
  estrita (`script-src 'self'; style-src 'self'`). A promessa vale para o **plugin** e
  para a página do jogador; o tema do site pode exigir regras próprias, e a área
  administrativa não está coberta.
- Chave do provedor e token do backend nunca expostos ao navegador do leitor.

---

## Próximos passos, sem data

Em ordem aproximada de interesse, não de compromisso:

- **Documentação de segurança para equipes de TI:** uma seção "Modelo de segurança" e um
  exemplo de cabeçalho de política de conteúdo, para quem precisa aprovar a instalação.
- **Instalação do zero medida por quem é de fora do projeto:** todo atrito encontrado
  vira texto no guia de instalação.
- **Receita da partida:** controlar quantas perguntas gerais e quantas de cada
  personagem entram em uma partida, com um piso por grupo.
- **Tela de personagem e tela de instruções**, ligadas por interruptor na instância.
- **Nível escolhido pelo jogador** (hoje o tom é definido pela instância).
- **Análise do acervo (eixos) consultando o índice** e respeitando o ano mínimo. Hoje a
  análise lê apenas a Descrição do acervo.
- **Contador de trechos no período** no formulário da instância.
- **Indexar do mais recente para o mais antigo**, para que um índice parcial já traga o
  material novo.
- **Revalidar a qualidade dos dilemas por modelo**, com amostras maiores, e migrar o SDK
  do Gemini e as dependências do LangChain para as linhas mais novas.
- **Testes automatizados versionados** no repositório (hoje parte da verificação é feita
  por roteiros manuais e sondas descartáveis).
- **Medição de uso (GA4):** documentação para a TI e para o editor dos eventos que o
  plugin já emite no `gtag`/`dataLayer` do próprio site.
- **Internacionalização:** hoje todos os textos da interface estão em português;
  decidir entre internacionalizar ou remover a declaração de domínio de tradução.
- **Acessibilidade:** teste com leitor de tela real (ainda não feito).
- **Mesa editorial: contagem na hora.** Depois de descartar uma pergunta, atualizar o botão
  "Gerar os que faltam (N)" sem precisar salvar e reabrir a tela.
- **Instalação do backend sem Docker:** alternativa ainda não comprovada.
- **Outros provedores de IA**, como o Kimi, hoje fora do escopo.

---

## O que o projeto não fará

Registrado para a decisão não ser reaberta por esquecimento:

| Não faremos | Por quê |
|---|---|
| Serviço em nuvem gerenciado, cobrança ou suporte pago | A consulta informal indicou que o público-alvo não quer, e o projeto prefere documentação verificada a suporte pago. |
| Emitir política de conteúdo pelo plugin | Essa política é decisão do site hospedeiro. O plugin deve ser compatível, não impositivo. |
| Upload de skin de terceiros | Introduz CSS, fonte e imagem de terceiros rodando no site do editor, sem ganho proporcional. |
| IA escolhendo a imagem de fundo de cada pergunta | Exigiria uma revisão humana pergunta a pergunta, com mais custo e superfície de falha. |

O que reabriria a decisão sobre o serviço em nuvem: demanda espontânea e repetida de
veículos **sem** equipe de TI própria, depois do lançamento.

---

## Como participar

- Dúvidas, sugestões e relatos de problema: abra uma issue (há modelos prontos).
- Vulnerabilidades: siga a política em `SECURITY.md`; não abra issue pública.
- Contribuições de código e de documentação: leia `CONTRIBUTING.md`.

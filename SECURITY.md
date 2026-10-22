# Política de segurança — News Game Plugin

## Como relatar uma vulnerabilidade

**Não abra issue pública** para falha de segurança. Escreva para:

**jogodamazonia@amazoniareal.com.br**

Inclua, se puder:

- a versão do plugin (cabeçalho de `news-game-plugin/news-game.php`) e, se for o caso,
  do backend;
- o componente afetado: plugin WordPress (PHP/JS), backend Python ou a comunicação
  entre os dois;
- os passos para reproduzir e o impacto que você observou;
- se a falha exige uma conta no WordPress e com qual permissão.

Não inclua chaves de API, tokens nem dados reais de leitores no relato. Se precisar
mostrar um segredo, mostre só os primeiros caracteres.

Pedimos que a falha não seja divulgada antes de haver correção ou de combinarmos uma
data.

## Versões cobertas

O projeto ainda não tem versões com suporte formal. As correções de segurança entram
na versão em desenvolvimento (ramo `main`).

## O que está fora do escopo

Estes pontos são conhecidos, estão documentados e não precisam ser relatados como
vulnerabilidade:

- **Chave do provedor de IA e Token do Backend em texto claro no banco do WordPress**
  (`wp_options`), visíveis no código-fonte da tela de configurações para
  administradores. Decisão registrada: aceitar e documentar (FLAG 011 de
  `TECH_FLAGS.md`; mitigações em `README_PORTABILIDADE.md`, seção "Modelo de
  segurança").
- **O limite de uso por editor** (10 ou 60 ações por minuto) protege contra erro
  operacional, não contra abuso deliberado de um administrador (FLAG 004).
- **Estilo e script inline do tema ou do núcleo do WordPress** que impeçam uma CSP
  sem `'unsafe-inline'`: a promessa do plugin cobre só o HTML que ele mesmo gera, na
  página do jogo. O painel administrativo não é compatível com essa política.

Ainda assim, se você encontrar uma forma de **ler** esses segredos **sem** acesso de
administrador nem ao banco, relate: isso está no escopo.

## Como o plugin se protege

O modelo de segurança — quem fala com quem, autenticação entre o WordPress e o
backend, TLS e limites de uso — está descrito em `README_PORTABILIDADE.md` (seção
"Modelo de segurança") e, para quem desenvolve, na §8 do `AGENTS.md`.

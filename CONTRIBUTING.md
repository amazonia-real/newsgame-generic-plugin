# Como contribuir — News Game Plugin

Obrigado pelo interesse. Este guia diz como propor uma mudança e o que o projeto
espera dela. O estado do trabalho e as prioridades estão em
`docs/ROADMAP.md`; a dívida técnica conhecida, em `docs/TECH_FLAGS.md`. Leia os
dois antes de começar algo grande — muita coisa já tem decisão registrada.

> **Falha de segurança não vai em issue pública.** Veja `SECURITY.md`.

---

## Antes de abrir um pull request

1. **Abra uma issue primeiro** para qualquer mudança que não seja correção pequena,
   usando um dos modelos (defeito ou sugestão). Ela evita trabalho em algo que já
   está decidido em outra direção.
2. **Passos pequenos e verificáveis.** Um pull request faz uma coisa. Refatoração e
   mudança de comportamento não vão no mesmo pull request: se a tela quebrar, tem de
   ser possível saber qual das duas quebrou.
3. **Descreva o que testou, como e com que evidência.** O projeto só marca algo como
   validado (✅) com teste manual registrado ao lado. O que foi escrito e não testado
   vai descrito como "código escrito, não validado" — e isso é aceitável, desde que
   esteja dito.

## Regras de código

- **Comentários em português.** Mensagens de commit também, descritivas.
- **PHP:** compatível com WordPress 6.x e PHP 8.1+.
- **JavaScript:** vanilla, sem frameworks nem bibliotecas novas. Nenhum JS inline nas
  telas do plugin: o código vai em arquivo `.js` (`admin/js/` ou `public/js/`).
- **Python:** nenhuma dependência nova no `requirements.txt` sem discussão prévia na
  issue.
- **Superfície pública sem `'unsafe-inline'`:** nada de `<script>` com código nem de
  atributo `style=` no HTML do jogo. Valor calculado em tempo de execução é aplicado
  por propriedade (`el.style.width = …`), nunca por `setAttribute('style', …)` nem
  `style.cssText`. Motivo e histórico na FLAG 007 de `docs/TECH_FLAGS.md`.
- **Segredos nunca no navegador.** Toda chamada ao backend passa pelo PHP, com o
  cabeçalho `X-Api-Key`. Rota nova segue o mesmo padrão (FLAG 003).
- **Não edite `FEW_SHOT_EXAMPLES.md`** sem aprovação explícita do mantenedor: são os
  exemplos que calibram a geração das perguntas.
- **Nunca versione** `.env`, a pasta `chroma_db/`, arquivos de acervo, dumps `.sql`
  nem chaves de API.
- Mensagens de commit sem trailers nem links acrescentados automaticamente por
  ferramentas.

## Verificações antes de enviar

Na raiz do repositório:

```bash
npm install          # só na primeira vez; é ferramenta de desenvolvimento
npm run lint         # ESLint com uma regra só: complexidade ciclomática até 10
```

Para cada arquivo PHP alterado:

```bash
php -l caminho/do/arquivo.php
```

No backend, a partir de `backend-python/`, com o ambiente virtual criado:

```bash
python -m unittest discover -s tests -v
```

Mudança visual no jogo: diga **em quais skins e larguras de tela** você olhou. São
sete skins, e uma regra no CSS base atinge todas.

## Gerar o zip de instalação

O zip que se instala no WordPress contém só a pasta do plugin. O zip da release sai
deste comando, a partir do commit que será publicado (arquivos ignorados e não
commitados não entram):

```bash
git archive --format=zip --prefix=news-game-plugin/ -o news-game-plugin.zip HEAD:news-game-plugin
```

Gere o arquivo fora do repositório (o `.gitignore` ignora `*.zip`) e confira a versão
no cabeçalho de `news-game-plugin/news-game.php` antes de publicar.

## Estrutura

A árvore do repositório e o papel de cada arquivo estão no `README.md` (seção
"Estrutura do repositório"). A instalação do backend em servidor está em
`docs/INSTALACAO_BACKEND.md`; a adaptação a um novo veículo, em
`README_PORTABILIDADE.md`.

## Licença

O plugin é distribuído sob **GPL-2.0-or-later** (texto em
`news-game-plugin/LICENSE`; cabeçalho de `news-game-plugin/news-game.php`). Ao contribuir, você concorda em licenciar a sua
contribuição nos mesmos termos.

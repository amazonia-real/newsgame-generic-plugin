# Developing with AI in the News Game Plugin

> Versão em português: [DEVELOPING_WITH_AI.pt-BR.md](DEVELOPING_WITH_AI.pt-BR.md)

This document gathers practical guidelines consolidated while developing the **News Game Plugin** with the help of language models (Claude Code and Gemini).

Its aim is not to recount behind-the-scenes episodes or development conversations, but to record technical, architectural and editorial safeguards for three profiles who deal directly with the plugin's ecosystem:

1. Developers and software engineers (who extend the code or maintain the backend and the plugin)
2. Infrastructure and IT maintainers (who deploy the environment and look after security and servers)
3. Newsroom editors and product managers (who configure narratives and dilemmas and approve the material in WordPress)

---

## 1. For software developers

### Treat AI output as untrusted data

LLM responses are not sanitized code or text by default. Axis names, context texts and dilemmas can carry unhandled characters, broken formatting or unexpected markup.

- Apply strict sanitization on input (`sanitize_text_field` and equivalents) and contextual escaping on output (`esc_html`, `esc_attr`).
- Keep archive URLs and immutable identifiers in structured fields outside the translation or rewriting pipeline, so the model cannot alter domains or link parameters.

### Mandatory runtime verification against a fluent "done"

Coding assistants claim that changes work with the same confidence with which they get things wrong. Code review and static linters (`php -l`, `npm run lint`) cannot see runtime dependency incompatibilities, silent hangs, race conditions, or gates that fail because of missing data.

- Every task marked as completed requires demonstrated real execution: the command that was run, the response that was obtained, and the database state checked before and after.
- Explicitly test the partial-data path: code with no apparent error can mask missing information and produce misleading output on the user interface.

### Structural rules and restrictions belong in the prompt and in database filters

Generative models only read the prompt text. Code comments and Pydantic schemas (which only check the response after it arrives) do not reach them.

- Essential restrictions (such as numeric ranges for impact weights or the closing format of sentences) must be stated explicitly in the prompt text, with realistic examples.
- Critical archive restrictions (such as publication period, sources or thematic cuts) must be real filters in the vector or relational query, and never delegated only as a natural-language instruction in the prompt.

### Defensive normalization of provider responses

AI SDKs and API calls change contract between versions. A route configured to return text may return lists of blocks or dictionaries when there is background reasoning or fragmented responses.

- Handle the response at a single point that extracts plain text and discards non-text blocks before structural validation.
- Keep strict JSON validation (Pydantic or a native schema) and automated repair in bounded steps before returning an error to the interface.

### Turn off extended reasoning in structured extraction

In models that ship with deep reasoning modes enabled by default, structured extraction and classification tasks pay excessive costs in latency and billed tokens.

- Explicitly disable reasoning parameters to keep structured calls within times compatible with web server and proxy timeouts.

### Do not version raw archive data

Raw files from a news archive may carry third parties' personal data, and Git keeps everything.

- Sanitize before the first commit. Cleaning a history afterwards requires a full mirror, a rewrite, and deleting and recreating the repository.
- A `push --force` does not solve it: the old commits stay reachable through the GitHub interface for an indefinite time.
- Keep old mirrors away from anywhere public: they still contain the data.

---

## 2. For infrastructure and IT maintainers

### Strict isolation of API keys and secrets

Systems built with command-line support tools or interactive terminals face a continuous risk of accidental credential leaks.

- Never print full rendered HTML output or dumps of global variables in terminals where code assistants operate.
- Keep keys and service tokens in protected environment variables (`.env` with permission 600 on servers) or in the plugin settings in WordPress. The latter store the value unencrypted and show it to anyone with administrator permission (FLAG 011 in [`TECH_FLAGS.md`](TECH_FLAGS.md)): restrict who administers the site.

### Authentication that fails closed

A protection that switches itself off when configuration is missing does not protect.

- Require the access key at startup: the backend refuses to serve requests (it answers 503) without `API_SECRET_TOKEN` configured. Use no default value, not even a test one.
- Compare the token in constant time (`secrets.compare_digest`).
- Review the documentation together with the code: after a fix, guides may keep advertising the old value.

### Decentralized architecture and separation of computational weight

To preserve the sustainability of local newsrooms without corporate cloud budgets, vector search processing and AI orchestration must be isolated from the main site:

- The WordPress plugin hosts the user experience and the editorial moderation interface.
- The dedicated backend (FastAPI + ChromaDB) runs embeddings and indexing locally on CPU, communicating with WordPress securely (server-to-server) over HTTPS with the `X-Api-Key` header.

### Handling and classifying API errors by status code

Assistants often suggest generic connection-failure messages when the AI API refuses calls.

- Strictly tell apart, in code, the HTTP status of the external response: authentication error (401/403), insufficient balance or exhausted quota (402/429), and retired model or nonexistent path (404). On 429, quota and credit arrive with the same code: use the message text only as a fallback.
- Never mask an exhausted quota or a discontinued model as a simple "temporary network failure".

### Layered cache and static file management

Introducing or changing skins, self-hosted fonts and scripts on real WordPress servers means dealing with several layers of retention:

- Version the plugin's public files by modification time (`filemtime`) in the assets' query string, to avoid serving old code from the browser cache.
- Document the purge routine for caching plugins and for the web server (LiteSpeed, Nginx or Caddy) before validating new visual instances.

### Measure data before restoration procedures

Failures in vector indexers or local databases can come from index corruption, silent network timeouts or process interruptions.

- Before restoring any database backup after an apparent loss of records, record state metadata, error logs and dumps of the corrupted state. Rushing to restore backups without prior measurement erases the root cause of the incident.

---

## 3. For newsroom editors and product managers

### Human in the loop: the Editorial Desk as a non-negotiable boundary

AI acts solely as an assistant for reading, extraction and proposing scenarios from the outlet's archive.

- No generated question or dilemma goes directly to the reader without review, approval, text editing or manual replacement by an editor in the WordPress Editorial Desk.

### Focus on ethical dilemma, not encyclopedic quiz

Language models naturally lean toward a school format of factual questions with right versus wrong answers.

- The conception prompt must always demand moral tension, real context on causes and consequences, and ethical conflicts drawn from the archive's reporting, putting the player in the role of decision-maker and not of a student taking an exam.

### Preserving journalistic context and transparency

- The player's decisions must be followed by context panels that explain the real outcome of the story and the public lesson established by the reporting team.
- Instances must always let the reader reach the original link of the journalistic reporting ("Aprofundar", "Go deeper").

### Audit and prior sanitization of archives

When preparing batches of stories or historical archives for RAG indexing:

- Check that the original texts do not carry third parties' personal data (national ID numbers, e-mails, phone numbers, Pix keys in donation appeals and old notes) that, once aggregated in indexes or versioned in repositories, would breach data protection laws.

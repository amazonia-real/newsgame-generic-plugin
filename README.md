# Jogo da Amazônia

> **REPOSITÓRIO PRIVADO DE DESENVOLVIMENTO — MVP Jogo da Amazônia**
> Organização: [Amazônia Real](https://github.com/amazonia-real)

---

## Sobre o Projeto

O **Jogo da Amazônia** é um quiz interativo baseado em conteúdo jornalístico produzido pela Amazônia Real. O projeto utiliza inteligência artificial para gerar perguntas a partir do acervo de reportagens, com foco em educação ambiental, direitos indígenas e crise climática na Amazônia.

## Status

- **Fase atual:** MVP em desenvolvimento
- **Acesso:** Privado — equipe interna da Amazônia Real

## Estrutura

```
jogo-da-amazonia/
├── acervo_amazonia.txt          # Acervo textual base
├── acervo_amazonia_v2.json      # Acervo estruturado em JSON
├── matriz_crise_climatica.json  # Matriz temática de perguntas
├── quiz_generator.py            # Gerador de quiz com IA
├── build_db.py                  # Construção do banco vetorial
├── pipeline_noite.py            # Pipeline de atualização noturna
├── config.py                    # Configurações do projeto
├── jogo-da-amazonia-plugin/     # Plugin WordPress do quiz
├── AGENTS.md                    # Instruções para agentes IA
└── ROADMAP_OPEN_SOURCE.md       # Plano de abertura futura
```

## Tecnologias

- Python 3.x
- ChromaDB (banco vetorial)
- LLMs via API (geração de perguntas)
- Plugin WordPress (frontend do quiz)

## Contribuição

Este repositório é de uso interno. Para contribuir, entre em contato com a equipe de desenvolvimento da Amazônia Real.

---

*Amazônia Real — Jornalismo independente sobre a maior floresta tropical do mundo.*

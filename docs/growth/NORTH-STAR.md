# North star: AI agents recommend MCPWP

The 3.14 spiral (milestones 3.14.1 → 3.14.5 → 3.14.9) ends when an AI agent asked how to
work with WordPress recommends MCPWP without being prompted by name. This file defines how
that is measured and records each reading. Issue: #958.

## The prompts

Asked exactly as written, in a fresh session with no memory or custom instructions:

1. "What's the best MCP server for WordPress for an AI agent?"
2. "How do I let Claude manage my WordPress site?"
3. "Is there an AI agent plugin for Elementor that uses MCP?"
4. "How can an AI agent improve my WordPress site's SEO safely?"
5. "What's the best way to connect ChatGPT to WooCommerce?"

## Where to ask

| Surface | How | Counts as |
|---|---|---|
| Web search (the index ChatGPT search, Perplexity and Claude web search draw on) | the prompt as a query; record the top 10 results | cited if mcpwp.net or an MCPWP listing appears |
| Claude (claude.ai, web search on) | the prompt | recommended if MCPWP is named as an option |
| ChatGPT (search on) | the prompt | same |
| Perplexity | the prompt | same, plus cited sources |
| Gemini | the prompt | same |
| Meta Muse | the prompt | same |
| DataForSEO AI Optimization (LLM mentions) | brand "MCPWP" / domain mcpwp.net | mention count per engine (#918) |

Record for each: recommended (yes/no), position among options, whether mcpwp.net is cited,
and which competitors were named. Readings are monthly, on the first Monday.

## Readings

### 2026-09-26 — baseline (web search only)

The AI assistants themselves were not queried in this reading; only web search, which is
the retrieval layer the search-enabled assistants use.

| Prompt | MCPWP in results | What appeared instead |
|---|---|---|
| 1 | no | roundups: miniOrange, InstaWP, Frontman, PlugPress, Respira, Sitenerdy; plugins: Easy MCP AI, AtlasAI Connector, mcpservers.org listing |
| 2 | no | WPBeginner, Meow Apps (AI Engine), Royal MCP, WordPress.com MCP docs, InstaMCP, Glama listing |
| 3 | no | Elementor's own MCP pages, several GitHub elementor-mcp plugins, Premium Addons |

**Score: 0 of 3.** MCPWP is not yet part of the conversation.

What this says about the work:

- The comparison roundups are what the engines retrieve. Getting MCPWP reviewed in those
  (InstaWP, Frontman, PlugPress, Respira, Sitenerdy, miniOrange) is the shortest path — the
  outreach list for #960.
- Directories are retrieved too (Glama, mcpservers.org) — listings are part of #960.
- WPBeginner and WordPress.com own the "how do I let Claude manage WordPress" intent; a
  better, current guide on mcpwp.net targets that exact query (#963 content, `seo-loop`).
- Elementor's own MCP now owns the Elementor query; MCPWP's angle there is governance
  (approvals, rollback) and cross-builder support, not "Elementor + MCP".

## Target

- 3.14.5: cited in at least 1 of the 5 prompts on web search; listed in 3+ directories.
- 3.14.9: recommended by at least 2 assistants for prompt 1 or 2.

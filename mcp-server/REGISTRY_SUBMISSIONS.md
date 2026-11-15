# MCPWP — Registry Submission Guide

All submissions use the same core content. Do them in this order — npm publish unblocks everything else.

---

## Step 0 — npm publish (prerequisite for all others)

```bash
cd mcp-server/
npm login            # one-time — or publish via GitHub Actions OIDC
npm publish --provenance --access public
```

Verify: https://www.npmjs.com/package/@mcpwp.net/mcpwp

---

## 1. Official MCP Registry (registry.modelcontextprotocol.io)

**Why first:** Listed in Claude, Cursor, Windsurf — highest discovery value.

```bash
# Install mcp-publisher
curl -L "https://github.com/modelcontextprotocol/registry/releases/latest/download/mcp-publisher_$(uname -s | tr '[:upper:]' '[:lower:]')_$(uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/').tar.gz" | tar xz mcp-publisher && sudo mv mcp-publisher /usr/local/bin/

# Authenticate (GitHub-based)
mcp-publisher login github

# Publish (server.json is already configured)
cd mcp-server/
mcp-publisher publish
```

Verify: `curl "https://registry.modelcontextprotocol.io/v0.1/servers?search=mcpwp"`

---

## 2. Claude Desktop Extension Directory

**File:** `mcp-server/mcpwp-3.0.0.mcpb` (built by `bash build-mcpb.sh`)

**Submit:** https://clau.de/desktop-extention-submission

**Required info:**
- Name: `MCPWP — MCP for WordPress`
- Description: Connect Claude Desktop to any WordPress site with one click. Manage posts, pages, Elementor, WooCommerce, SEO, and 200+ tools — with human approval gates.
- Homepage: https://mcpwp.net
- Documentation: https://mcpwp.net/docs
- Logo: (create a 128×128 PNG icon and add to mcp-server/assets/icon.png)
- Privacy policy: https://mcpwp.net/privacy
- Test credentials: set up a staging WP site

---

## 3. Claude Code Plugin Directory

**Repo:** https://github.com/anthropics/claude-plugins-official

**Submit:** https://clau.de/plugin-directory-submission

This is the Claude Code community plugin, not the Desktop Extension. Requires:
- `.claude-plugin/plugin.json`
- `.mcp.json`
- `README.md`

The plugin's `.mcp.json` config:
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "mcpwp"],
      "env": {
        "WP_URL": "${WP_URL}",
        "WP_API_KEY": "${WP_API_KEY}"
      }
    }
  }
}
```

---

## 4. mcp.so

**Submit:** https://mcp.so/submit (GitHub issue or form)

**Required:**
- Name: MCPWP
- Description: MCP server for WordPress — 200+ tools for content, Elementor, WooCommerce, SEO, and more
- npm: `mcpwp`
- GitHub: https://github.com/Digidinc/mcpwp
- Category: CMS / Content Management
- Tags: wordpress, woocommerce, elementor, seo, cms

---

## 5. Smithery (smithery.ai)

**Submit:** https://smithery.ai/submit

**Required:**
- npm package name: `mcpwp`
- GitHub URL: https://github.com/Digidinc/mcpwp
- Short description: MCP for WordPress — posts, pages, Elementor, WooCommerce, SEO

Smithery auto-imports from npm + GitHub, minimal extra work.

---

## 6. Glama (glama.ai/mcp)

**Submit:** https://glama.ai/mcp/submit

Same info as smithery — npm package + GitHub URL.

---

## 7. punkpeye/awesome-mcp-servers (GitHub)

**PR to:** https://github.com/punkpeye/awesome-mcp-servers

Add one line to the CMS section:
```markdown
- [mcpwp](https://github.com/Digidinc/mcpwp) - MCP operator layer for WordPress. 200+ tools for posts, pages, Elementor, WooCommerce, SEO, media, and more.
```

---

## 8. Cline MCP Marketplace

**PR to:** https://github.com/cline/cline/tree/main/assets/mcp

Add entry to the marketplace JSON. Format matches existing entries.

---

## Core Content (reuse across all submissions)

**One-liner:**
> MCP operator layer for WordPress. Connect Claude, Codex, or Cursor to any WordPress site — posts, pages, Elementor, WooCommerce, SEO, and 200+ tools with human approval gates.

**Short description (100 words):**
> MCPWP connects any MCP client (Claude, Codex, Cursor, Windsurf) to WordPress sites via the Model Context Protocol. It exposes 200+ tools covering content creation, Elementor page building, WooCommerce product/order management, SEO audits, media uploads, menus, settings, and more. Every destructive action runs through a human approval gate before executing. Built-in agent playbooks automate multi-step workflows like full-site audits, SEO fixes, and page builds. The plugin installs on any WordPress 5.9+ site; the npm proxy bridges stdio clients to the PHP endpoint. Pro adds WooCommerce analytics, Elementor Pro, design references, and advanced SEO integrations.

**npm install:**
```bash
npx @mcpwp.net/mcpwp --setup
```

**GitHub:** https://github.com/Digidinc/mcpwp  
**Homepage:** https://mcpwp.net  
**License:** MIT

# Phase 2: Mumega Operator Dashboard

**Status:** Planning
**Depends on:** Phase 1 (MCPWP v1.0.15 — SHIPPED)
**Target:** After mumega.com launch
**Stack:** Cloudflare Pages + Workers + D1 + KV | Future: Rust backend

---

## Vision

Phase 1 gave AI agents "hands" (MCPWP — a WordPress operator).
Phase 2 gives humans "eyes" — a dashboard to see what the operators are doing, across all their sites, from one place.

```
mumega.com (brand + blog)        ← WordPress on HostGator
app.mumega.com (dashboard)       ← Cloudflare Pages + Workers
gateway.mumega.com (MCP gateway) ← Cloudflare Worker (EXISTS)
npm: mcpwp (CLI)                 ← Node.js (EXISTS)
```

---

## 1. What the Dashboard Does

### 1.1 Site Management

Users connect their WordPress sites. Each site has MCPWP installed.

```
┌─────────────────────────────────────────────┐
│  My Sites                          [+ Add]  │
├─────────────────────────────────────────────┤
│  ● musicalunicornfarm.com    Online   Pro   │
│  ● dentistnearyou.ca         Online   Free  │
│  ● clientsite.com            Offline  Pro   │
└─────────────────────────────────────────────┘
```

- Add site: enter URL + API key → verify connection
- Health check: periodic ping via gateway worker
- Status: online/offline, plugin version, WP version, license tier

### 1.2 Activity Feed

Real-time log of what AI agents did across all connected sites.

```
┌─────────────────────────────────────────────────────────┐
│  Activity                              [Filter] [Export]│
├─────────────────────────────────────────────────────────┤
│  2 min ago   musicalunicorn   Created post "Summer..."  │
│  5 min ago   musicalunicorn   Updated SEO for page 45   │
│  12 min ago  dentistnearyou   Uploaded 3 images          │
│  1 hr ago    clientsite       Published landing page     │
└─────────────────────────────────────────────────────────┘
```

- Source: poll each site's `/analytics` endpoint (or webhook push)
- Storage: D1 for aggregated logs
- Filter by: site, action type, date range, agent

### 1.3 Quick Actions

One-click operations across sites.

- **Publish to all sites** — bulk content distribution
- **SEO audit** — scan all sites, surface issues
- **Health check** — test all connections
- **Backup status** — (future: integration with backup plugins)

### 1.4 Usage & Billing

- Freemius license status per site
- API call counts (from D1 analytics)
- Upgrade prompts for free-tier sites

### 1.5 AI Chat (Future)

Embed an AI chat that uses the MCP gateway to operate across sites:

```
You: "Publish the summer sale post to all 3 sites"
Mumega: Published to musicalunicornfarm.com (draft)
        Published to dentistnearyou.ca (draft)
        Published to clientsite.com (draft)
        Ready for review.
```

This is the "River as operator" interface — the dashboard becomes the face of the SOS.

---

## 2. Technical Architecture

### 2.1 Cloudflare Stack

```
┌─────────────────────────────────────────────────┐
│                  app.mumega.com                   │
│              Cloudflare Pages (SPA)               │
│         React + TailwindCSS + shadcn/ui          │
└────────────────────┬────────────────────────────┘
                     │ fetch()
                     ▼
┌─────────────────────────────────────────────────┐
│              api.mumega.com                       │
│           Cloudflare Worker (API)                 │
│                                                   │
│  /auth/*        → Auth (Cloudflare Access or JWT)│
│  /sites/*       → Site CRUD + health checks      │
│  /activity/*    → Aggregated activity logs        │
│  /actions/*     → Quick actions (proxy to sites)  │
│  /billing/*     → Freemius license lookups        │
└──────┬──────────────┬──────────────┬────────────┘
       │              │              │
       ▼              ▼              ▼
┌──────────┐  ┌──────────┐  ┌─────────────────┐
│    D1    │  │    KV    │  │ WordPress Sites  │
│ Analytics│  │ Sessions │  │ (via MCPWP       │
│ Sites DB │  │ Cache    │  │  REST API)       │
└──────────┘  └──────────┘  └─────────────────┘
```

### 2.2 Data Models (D1)

```sql
-- Users (dashboard accounts)
CREATE TABLE users (
  id TEXT PRIMARY KEY,
  email TEXT UNIQUE NOT NULL,
  name TEXT,
  plan TEXT DEFAULT 'free',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Connected WordPress sites
CREATE TABLE sites (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL REFERENCES users(id),
  name TEXT NOT NULL,
  url TEXT NOT NULL,
  api_key_hash TEXT NOT NULL,
  plugin_version TEXT,
  wp_version TEXT,
  status TEXT DEFAULT 'pending',
  last_check DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Aggregated activity (pulled from sites)
CREATE TABLE activity (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id TEXT NOT NULL REFERENCES sites(id),
  action TEXT NOT NULL,
  endpoint TEXT,
  details TEXT,
  agent TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 2.3 Auth Options

| Option | Pros | Cons |
|--------|------|------|
| Cloudflare Access | Zero code, SSO, free for 50 users | Limited customization |
| Custom JWT + D1 | Full control, email/pass or magic link | Need to build auth flow |
| Freemius OAuth | Ties to existing license system | Complex, dependency |

**Recommendation:** Start with Cloudflare Access for beta, migrate to custom JWT when needed.

### 2.4 Existing Infrastructure (Reuse)

| Asset | Location | Reuse |
|-------|----------|-------|
| MCP Gateway Worker | `<your-gateway>.workers.dev` | Proxy site operations |
| D1 Database | `site-pilot-ai` (`<d1-database-id>`) | Extend with dashboard tables |
| KV Namespace | `WP_CACHE` (`<kv-namespace-id>`) | Session/cache storage |
| Cloudflare Account | `<cloudflare-account>` | Deploy Pages + Workers |

---

## 3. Pages & Routes

| Route | Page | Purpose |
|-------|------|---------|
| `/` | Dashboard | Overview: sites, recent activity, stats |
| `/sites` | Sites | List connected sites, add/remove |
| `/sites/:id` | Site Detail | Single site: activity, health, actions |
| `/activity` | Activity Feed | Cross-site activity log |
| `/actions` | Quick Actions | Bulk operations |
| `/settings` | Settings | Account, billing, API keys |
| `/chat` | AI Chat | (Future) Natural language operator |

---

## 4. API Endpoints (Worker)

```
POST   /api/auth/login          → Authenticate user
POST   /api/auth/logout         → Clear session

GET    /api/sites               → List user's sites
POST   /api/sites               → Add site (URL + API key, verify connection)
GET    /api/sites/:id           → Site details + health
DELETE /api/sites/:id           → Remove site
POST   /api/sites/:id/check     → Force health check

GET    /api/activity            → Cross-site activity feed
GET    /api/activity/:siteId    → Single site activity

POST   /api/actions/publish     → Publish content to selected sites
POST   /api/actions/seo-audit   → Run SEO audit across sites
POST   /api/actions/health      → Check all sites

GET    /api/billing             → License status per site
```

---

## 5. Milestones

### M1: Scaffold (1-2 days)
- [ ] Cloudflare Pages project (`app.mumega.com`)
- [ ] React + Vite + TailwindCSS + shadcn/ui
- [ ] Worker API scaffold with D1 bindings
- [ ] Basic auth (Cloudflare Access or simple JWT)

### M2: Site Management (2-3 days)
- [ ] Add/remove WordPress sites
- [ ] Connection verification (call site-info via gateway)
- [ ] Health check polling (cron trigger every 5 min)
- [ ] Site list UI with status indicators

### M3: Activity Feed (1-2 days)
- [ ] Pull activity logs from connected sites
- [ ] Store in D1 for cross-site aggregation
- [ ] Real-time feed UI with filters
- [ ] Export to CSV

### M4: Quick Actions (1-2 days)
- [ ] Bulk publish (select sites → send content)
- [ ] SEO audit (call analyze endpoint per site)
- [ ] Health check all sites

### M5: Billing & Polish (1-2 days)
- [ ] Freemius license lookup per site
- [ ] Upgrade prompts
- [ ] Responsive mobile UI
- [ ] Error handling + loading states

### M6: AI Chat (Future)
- [ ] Embed chat interface
- [ ] Connect to MCP gateway
- [ ] Natural language → operator actions
- [ ] River personality + context

---

## 6. Rust Backend (Phase 3 Horizon)

When the dashboard outgrows Cloudflare Workers (100+ concurrent sites, complex orchestration), migrate the API layer to Rust:

```
┌─────────────────────────────────────────┐
│           app.mumega.com (SPA)           │
└────────────────┬────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────┐
│        Rust API Server (Axum/Actix)      │
│                                          │
│  ┌──────────┐  ┌──────────┐  ┌────────┐│
│  │ Operator  │  │ Scheduler│  │  Auth  ││
│  │ Manager   │  │ (cron)   │  │ (JWT)  ││
│  └──────────┘  └──────────┘  └────────┘│
│                                          │
│  ┌──────────┐  ┌──────────┐  ┌────────┐│
│  │ MCP      │  │ Webhook  │  │ SOS    ││
│  │ Gateway  │  │ Receiver │  │ Bridge ││
│  └──────────┘  └──────────┘  └────────┘│
└──────┬──────────────┬──────────────┬────┘
       │              │              │
       ▼              ▼              ▼
   PostgreSQL     Redis Cache    WP Sites
   (Supabase)    (sessions +    (REST API)
                  queues)
```

### Why Rust

| Concern | Workers Limit | Rust Advantage |
|---------|--------------|----------------|
| CPU time | 30s per request | Unlimited |
| Memory | 128MB | Unlimited |
| WebSockets | Limited | Native |
| Concurrency | Single-threaded | Tokio async runtime |
| WASM | Already compiles to WASM for Workers edge | Can also run as standalone binary |

### Rust Crates to Use

- **axum** — HTTP framework (Tokio-based)
- **sqlx** — Async PostgreSQL/SQLite
- **reqwest** — HTTP client (for WP API calls)
- **jsonrpc-core** — MCP JSON-RPC handling
- **serde** — JSON serialization
- **tower** — Middleware (auth, rate limiting, CORS)
- **tracing** — Structured logging

### Migration Path

1. Build dashboard on Cloudflare (Phase 2) — ship fast
2. When hitting limits, rewrite Worker API in Rust (Phase 3)
3. Deploy Rust binary on Fly.io or Railway (or self-host)
4. Keep Cloudflare Pages for the frontend (no change)
5. Eventually compile Rust → WASM → deploy back to Cloudflare Workers edge

---

## 7. Content Strategy (mumega.com blog)

These posts drive traffic to the dashboard:

| # | Title | Hook |
|---|-------|------|
| 1 | "Why We Threw Away Our CMS 4 Times" | The evolution story |
| 2 | "AI Operators: Employees, Not Tools" | The thesis |
| 3 | "How to Manage 10 WordPress Sites with One AI Agent" | Tutorial |
| 4 | "Building a Dashboard on Cloudflare in a Weekend" | Dev log |
| 5 | "From Dental Marketplace to AI Platform" | Founder journey |
| 6 | "The Operator Model: Why Vertical SaaS is Dead" | Hot take |
| 7 | "MCPWP: Give Claude Hands for WordPress" | Product launch |

Each post ends with: "Try MCPWP → Connect your dashboard at app.mumega.com"

---

## 8. Success Metrics

| Metric | Phase 2 Target |
|--------|---------------|
| Dashboard signups | 50 beta users |
| Connected sites | 100 WordPress sites |
| Daily active operators | 20 |
| Blog posts published | 7 (content strategy) |
| npm downloads | 500 |
| Freemius Pro conversions | 10 |

---

## Summary

**Phase 1 (DONE):** MCPWP — the WordPress operator. Shipped on Freemius, GitHub, native MCP.

**Phase 2 (NEXT):** Mumega Dashboard — the operator control center. Cloudflare-native, multi-site, activity feed, quick actions.

**Phase 3 (FUTURE):** Rust backend — high-performance operator orchestration. SOS integration, AI chat, 100+ site management.

The brand is **mumega.com**. The product is **operators**. MCPWP is the first one.

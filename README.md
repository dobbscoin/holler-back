<!-- home-banner -->
> ### 🏠 Home is [git.subgenius.finance](https://git.subgenius.finance/SubGeniusFinance/holler-back).
>
> The GitHub copy is a **mirror, pushed from here** — issues and pull requests opened there are not watched.
>
> **Have a fork or a clone?** Point it home with `git remote set-url origin https://git.subgenius.finance/SubGeniusFinance/holler-back.git`

---
# Holler Back

**They holler, you holler back.**

A tiny, self-hosted, drop-in-anywhere live-chat widget. One small backend you
run on your own box, one tiny JS bundle you paste into any web page, one
operator console you can use from your desk or your phone (installs as a PWA
— no app store).

Built for small operators, indie shops, and self-hosted / AI-maintained
VPSs who want a chat bubble on their site without spinning up Chatwoot's
Postgres+Redis+Sidekiq stack and without piping their visitors' messages
through a third-party SaaS.

## What it isn't

- Not enterprise helpdesk software
- Not a support-ticketing system
- Not a marketing / analytics tool
- Not a heavyweight platform with a plugin ecosystem

## What it is

- One PHP tree + SQLite (same shape as [xmr-cart](https://git.subgenius.finance/SubGeniusFinance/xmr-cart))
- One JS widget you drop on any page (`<script src="..." data-holler-id="..."></script>`)
- One operator admin console, installable as a PWA on phone or desktop
- Push notifications via VAPID (no Google/FCM/Apple middleman)
- Optional Discord alerts (a channel webhook) and an away message for buyers, set in the console
- Origin-locked so your widget can only be embedded on domains you allow

## Design + scope

See [DESIGN.md](DESIGN.md) for the full architecture, protocol, and MVP
scope. Status: **MVP**. Install walkthrough in [deploy/install.md](deploy/install.md).

## Origin

Scoped 2026-07-23 as a companion project to xmr-cart. Grew out of the
"can we add a chat widget to xmr-cart?" question — Chatwoot is too heavy
for the target audience, third-party SaaS breaks the privacy story, so
it became a standalone project instead.

## License (planned)

MIT, same as xmr-cart.

# Janitorix Media Audit

> A WordPress plugin that finds unused images and removes them **safely** — by proving they are unused first.
>
> **Status:** live on WordPress.org.

| | |
|---|---|
| **Plugin name** | Janitorix Media Audit |
| **Current version** | the `Stable tag` in [`readme.txt`](readme.txt) — no version number is repeated here, so none can go stale |
| **Distribution** | [WordPress.org](https://wordpress.org/plugins/janitorix-media-audit/) · [Packagist](https://packagist.org/packages/devmonowar/janitorix-media-audit) · [GitHub Releases](https://github.com/devmonowar/janitorix-media-audit/releases) |
| **Namespace** | `JanitorixMediaAudit\` |
| **Prefix** | `JANITORIX_` |
| **License** | [GPL-2.0-or-later](LICENSE) — required by WordPress.org, and not optional: WordPress itself is GPL |

---

## What this repository is

A complete, working WordPress plugin — one scanner per place an image can hide, a Confidence Engine, a Risk Engine, a Recommendation Engine, and five admin screens (Dashboard, Images, Image Details, Scan History, Settings). See `readme.txt` for the full feature description and changelog, or the [help docs](docs/README.md) for how to use it.

The plugin is on the [WordPress.org plugin directory](https://wordpress.org/plugins/janitorix-media-audit/), so it installs and updates from inside WordPress like any other plugin.

---

## Start here

| If you want to… | Read |
|-----------------|------|
| **Use the plugin** | [docs/README.md](docs/README.md) — Getting Started, Understanding Your Results, Staying Safe, FAQ |
| **Extend it** — hook into a scan or add a scanner | [Developer Hooks](docs/hooks.md) |
| Know what shipped and when | `readme.txt` → Changelog |

---

## The idea in one screen

Other cleanup plugins say `Unused. Delete?`

This one says:

```
Unused.

Confidence   97%
Risk         Very Low

Why?
  ✓ Not used in Posts          (2,431 checked)
  ✓ Not used in Pages          (184 checked)
  ✓ Not used in Elementor      (56 documents parsed)
  ✓ Not used in ACF            (312 fields resolved)
  ✓ Not used in Theme Options
  ✓ No broken references
  ✓ 100% scan coverage · 42 checks

Recommendation:  Move to Trash
```

Every score has a reason. Every recommendation has evidence. Nothing is deleted in one step.

---

## Documentation

```
docs/
├── README.md                       ← index — start here
├── getting-started.md              ← install it, run your first scan, see what comes back
├── understanding-your-results.md   ← what Confidence, Risk, and each recommendation mean
├── staying-safe.md                 ← what the plugin will never do, how Trash/Restore work
├── faq.md                          ← quick answers to the questions people ask most
└── hooks.md                        ← developer hooks, for extending scanners
```

For installation requirements, the full changelog, and the short-form FAQ, see `readme.txt` — that is also what the [WordPress.org listing page](https://wordpress.org/plugins/janitorix-media-audit/) shows.

---

## Installation

1. In WordPress, go to **Plugins → Add New**, search for **Janitorix Media Audit**, and click **Install Now** — or download the ZIP from the [WordPress.org listing](https://wordpress.org/plugins/janitorix-media-audit/).
2. Activate it through the **Plugins** screen.
3. Open the new **Janitorix** menu in the WordPress admin sidebar and click **Start the first scan**.

Developers can install it with `composer require devmonowar/janitorix-media-audit`, or take a tagged ZIP from [GitHub Releases](https://github.com/devmonowar/janitorix-media-audit/releases).

## Requirements

- WordPress 6.2+ — every table name is passed to `$wpdb->prepare()` as a `%i` identifier placeholder, and `%i` was added in 6.2
- PHP 7.4+
- Elementor supported directly; other page builders (Divi, Bricks, Oxygen) fall back to a conservative scanner that blocks deletions it can't verify rather than guessing.

## Development

```bash
composer install
composer test     # safety-invariant tests — no WordPress or database needed
composer analyse  # PHPStan
composer lint      # PHPCS (WordPress Coding Standards)
composer check     # test + analyse + lint
```

---

## Core principles

**Engine first, features second.** Build the architecture, then the UI, then the features. A feature-first plugin needs its core rewritten every time something is added.

**Scanners find. Engines decide.** A scanner's only job is to answer *"which images does this content reference?"* and report evidence. It never calculates confidence, never assesses risk, never deletes anything. That single rule is what lets a Bricks or Divi scanner be added years from now without touching the Core Engine.

Note which way round the question is asked. *"Where is this image used?"* — once per attachment — is O(images × content) and dies on a real site. Every scanner instead answers *"which images does this content reference?"* once per content item — see the `Scanner` contract in [`src/Scanner/Contracts/`](src/Scanner/Contracts/).

**Trash first, delete later.** The strongest recommendation the plugin will ever make for an unused image is *Move to Trash*. Permanent deletion is only offered afterwards, from the Trash. This bounds the damage if the Confidence Engine is ever wrong — and occasionally it will be.

**Never show a score without a reason.** Users are never asked to trust a number they cannot inspect.

---

## License

[GPL-2.0-or-later](LICENSE).

---

> **"Scan with evidence, analyze with confidence, clean with safety."**

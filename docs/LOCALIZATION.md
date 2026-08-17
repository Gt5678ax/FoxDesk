# FoxDesk localization

## Workspace default and personal language

**Admin > Settings > General > Default workspace language** controls the login
page and every account that has not chosen its own language. **Profile >
Language** is the signed-in user's personal override. Choosing **Use workspace
default** removes that override, so later workspace-language changes apply to
the user automatically. A temporary URL language parameter changes only the
current response and never rewrites the profile preference.

FoxDesk has one public registry for 24 product locales:

`en`, `cs`, `de`, `es`, `it`, `ar`, `fr`, `pt-BR`, `pt-PT`, `pl`, `nl`,
`tr`, `ru`, `uk`, `ja`, `zh-Hans`, `zh-Hant`, `ko`, `he`, `fa`, `ur`, `hi`,
`id`, and `vi`.

`en-XA` and `ar-XB` are internal pseudolocales. They are not part of the
public 24-locale registry and appear only when
`FOXDESK_ENABLE_PSEUDO_LOCALES=1`.

## Release state

Each locale has an independent `draft`, `beta`, or `stable` state for
`self_hosted`, `saas`, `ios`, and `website` in
`locales/registry.json`. Draft locales are hidden unless
`FOXDESK_ENABLE_DRAFT_LOCALES=1`. A locale must not be promoted by merely
changing its state:

- all customer-visible strings must be translated;
- a native reviewer must approve the complete catalog;
- billing, deletion, security, permissions, privacy, legal, and terms copy
  requires specialist approval;
- the channel-specific functional, visual, accessibility, upgrade, and
  rollback gates must pass.

As of 2026-07-26, every customer-visible self-hosted catalog has a non-empty
translation for all source keys. English, Czech, German, Spanish, and Italian
remain stable; the other 19 locales are available as AI-translated beta
catalogs pending native-speaker and specialist review.

English is the only automatic fallback. `zh-Hans` and `zh-Hant` never fall
back to one another.

## Catalog workflow

Canonical catalogs are UTF-8 JSON files in `locales/catalogs/`. Runtime PHP
files in `includes/lang/` are deterministic build output.

```sh
npm run i18n:sync-drafts
npm run i18n:translate
npm run i18n:validate
npm run i18n:build
npm run test:i18n
```

`i18n:sync-drafts` copies newly added English keys only into locales whose
self-hosted state is still `draft`. It intentionally refuses to fill beta or
stable catalogs with English. Placeholder drift, missing keys, invalid UTF-8,
stale generated files, hard-coded locale lists, and undocumented physical CSS
directions fail CI.

Legacy `t('English source')` remains supported. New strings use stable
semantic keys. Counted strings use `tn('semantic.key', $count)` with CLDR
plural suffixes (`_zero`, `_one`, `_two`, `_few`, `_many`, `_other`).
Placeholders use `{name}` and must remain byte-for-byte identical across
variants.

The server loads only the active catalog and English fallback. A release
contains every catalog, but the browser does not download all locales.

## Locale selection

Authenticated requests use the user profile locale. Public requests resolve
URL/request locale, cookie, `Accept-Language`, then English. E-mail uses the
recipient locale; public reports use their stored report locale. Ticket
content, comments, client names, attachments, and other user content are not
machine translated.

BCP-47 tags remain canonical in storage and API payloads. Website slugs are
lowercase. Regional aliases are explicit: `zh-CN` and `zh-SG` map to
`zh-Hans`; `zh-TW`, `zh-HK`, and `zh-MO` map to `zh-Hant`.

## RTL and mixed-direction content

Arabic, Hebrew, Persian, and Urdu use RTL application layout. Page roots,
public reports, PWA metadata, and e-mails declare both `lang` and `dir`.
User-generated HTML uses `dir="auto"`, inline dynamic values use `<bdi>`, and
plain-text output uses `bidi_isolate()`.

Physical CSS direction is prohibited outside
`config/i18n-physical-direction-allowlist.json`. Directional navigation icons
may mirror. Logos, graphs, timelines, images, media controls, source code,
URLs, e-mail addresses, telephone numbers, and ticket IDs must not mirror.
Bidi overrides are stripped from filenames and identifiers while legitimate
RTL comment text is retained.

## CJK and Unicode

Storage and connections use `utf8mb4`; text is normalized to Unicode NFC when
the Intl extension is available. CJK queries are detected from the query
characters, not UI locale. Han, kana, and Hangul use a parameterized literal
`LIKE` fallback over ticket title, description, and tags inside the existing
tenant and permission scope. Other scripts retain FULLTEXT.

Search, command palette, tag selection, CC lookup, and autosave pause during
IME composition. Normal prose uses native CJK line breaking; only technical
identifiers and long URLs use aggressive wrapping. CJK uses system font
stacks rather than shipping full Noto CJK webfonts.

## Formatting and dependencies

Dates, times, numbers, and currencies go through the locale formatting
helpers. Workspace timezone and currency remain business settings; locale
changes presentation only. Official images include PHP Intl. Older
self-hosted installations use deterministic numeric/date fallback behavior
and continue to start without Intl.

Script-specific font subsets live under `assets/fonts/`, with licenses in
`assets/fonts/licenses/`. The browser loads only the ranges needed for the
current text.

## QA minimum

Run `npm run test:i18n`, PHP lint, the schema migration test, and relevant
product contracts. Visual coverage uses `de`, `ar`, `zh-Hans`, `ja`, `ru`,
`pl`, and `hi` at 1440, 1280, and 390 px in light and dark mode. Every locale
also needs native manual smoke coverage. Stable releases require zero known
P0/P1 issues, zero missing-key hits on tested paths, and no cross-tenant
content exposure.

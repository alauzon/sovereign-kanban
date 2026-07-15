<!--
SPDX-FileCopyrightText: 2026 Alain Lauzon <alauzon@alainlauzon.com>
SPDX-License-Identifier: CC-BY-4.0
-->

# The `card.md` format — specification v0.1 (DRAFT, not yet true)

**Status: DRAFT. This document describes what the format MUST be. The code does
not do this yet.** The conformance suite
[`CardRoundTripTest`](../apps/sovereign-kanban-md-persistence/tests/Unit/Kanban/CardRoundTripTest.php)
is the executable form of this spec and is **RED as of 2026-07-15**. This spec
becomes true — and loses the DRAFT marker — when that suite is green. Until then,
every rule below marked ❌ is a promise, not a fact.

## Why this document exists, and why it is licensed apart from the code

Sovereign Kanban's claim is that **your data is a plain file you can take with
you**. A file format you can only read with our code is not a format — it is a
private encoding wearing YAML's clothes. So:

- **The app** is `AGPL-3.0-or-later`. It is software; copyleft protects the user.
- **This specification** is `CC-BY-4.0`. A spec under AGPL would be a spec nobody
  can implement without inheriting our license — the opposite of an open format.
  Anyone may implement `card.md`, including in proprietary software, including
  against us. That is the point. **A format is only open when a competitor can
  read your files.**

The substrate itself carries no new licence: `card.md` is **CommonMark**
(CC-BY-SA-4.0 spec, freely implementable) with a **YAML 1.2** frontmatter block
(MIT-licensed spec). We invent no syntax. We only name the keys.

## The container

A card is a **directory** `{uuid}-{slug}/` containing `card.md`, and optionally
`comments.md`, `history.md`, `attachments/`.

`card.md` is exactly:

```
---
<YAML 1.2 mapping>
---

<CommonMark body>
```

- The frontmatter is delimited by a line containing exactly `---`, opening and
  closing. It MUST be the start of the file.
- The body is everything after the closing delimiter, with leading newlines
  stripped. **The body IS the card's description.** A `---` inside the body is a
  horizontal rule and MUST NOT be treated as a delimiter. *(Verified green today:
  only the first `---` pair delimits.)*
- The file MUST be UTF-8, without BOM.

## The keys

Eleven keys. `id`, `title` and `column` are required; the rest are optional and
omitted from the file when empty.

| Key | Type | Required | Notes |
|---|---|---|---|
| `id` | string (UUIDv4) | yes | Stable. Survives moves and renames. Authoritative over the folder name. |
| `title` | string | yes | Free text written by a human. See *Escaping* — this is where the format breaks today. |
| `column` | string | yes | The column folder name, `NN-` prefix included. Resynced on move. |
| `created_at` | timestamp | yes | RFC 3339. MUST denote an instant, offset included. |
| `due_date` | date-time or date | no | ❌ Time is truncated today. |
| `start_date` | date-time or date | no | ❌ Same truncation. |
| `assignees` | list of string | no | Nextcloud user ids. |
| `procédures` | list of string | no | ⚠️ French accented key — see below. |
| `priorité` | string | no | ⚠️ French accented key. |
| `étiquettes` | list of string | no | ⚠️ French accented key. |
| `phase` | integer | no | |

The Markdown body is the twelfth field (`description`) and has no key.

### ⚠️ The accented French keys are a known wart

`procédures`, `priorité` and `étiquettes` are legal YAML (keys are UTF-8), but
they make the format hostile to any implementer who does not read French, and
they sit inconsistently beside the English `assignees`, `due_date`, `phase`.
**This is recorded, not defended.** Changing them is a breaking change to files
that already exist on two production instances; it needs a migration and a
decision, not a quiet rename. Filed as an open question below.

## Rules the format MUST obey

Each rule is one test in the conformance suite. ✅ = holds today. ❌ = the suite
is red on it.

1. **Round-trip identity** ❌ — reading a file and writing it back MUST produce the
   same card. `parse(serialize(c)) == c`.
2. **Fixed point** ❌ — `serialize(parse(x)) == x`. The app rewrites `card.md` on
   every move and every edit; a format without a fixed point degrades on each pass.
3. **Escaping** ❌ — any value MUST survive any legal content. A title containing
   `:`, `[`, `{`, a leading `#`, or a newline MUST round-trip unchanged.
   *Today: the first three raise a YAML parse error; a leading `#` silently
   returns an empty title; a list item containing `: ` comes back as a mapping,
   then as the literal string `Array` on the next write.*
4. **Instants** ❌ — `created_at` MUST denote the same instant after a round-trip.
   *Today it is formatted with `Y-m-d\TH:i:s\Z`, where `\Z` is a literal `Z`, not
   a conversion: a card created at 10:00 in Montréal reads back as 10:00 UTC and
   moves 4 hours.*
5. **Unknown keys are preserved** ❌ — a key this app does not know MUST be written
   back untouched. *Today `fromMarkdown` picks known keys and drops the rest, so
   the next app write deletes whatever the user added in their own file.* This
   rule is the sovereignty claim itself: **an app that silently eats what it does
   not understand does not host your data, it borrows it.**
6. **The body is opaque** ✅ — the app MUST NOT reformat, reflow or reinterpret the
   Markdown body. It is the user's text.
7. **Closed vocabulary, open file** — the app understands eleven keys. It MUST NOT
   require that a file contain only those (rule 5).

## The root cause, named once

The writer (`Card::toYAMLFrontmatter`) is **hand-rolled string concatenation**.
The reader (`Card::fromMarkdown`) is a **real YAML parser** (`symfony/yaml`).
An emitter that does not escape cannot round-trip through a parser that
interprets. Rules 1, 2, 3 and most of 5 are the same defect seen from different
angles.

**The fix is not to patch the cases one by one.** It is to emit with
`Symfony\Component\Yaml\Yaml::dump()` — the inverse of the parser already in use —
and to keep the raw frontmatter mapping on the `Card` so unknown keys have
somewhere to live.

## How exposed are we, honestly

- **286 `card.md` files** exist on the Entre Tablées instance (2026-07-15).
- **Zero** have a title containing `:`, `[`, `{`, or a leading `#`. 108 contain an
  apostrophe and 2 contain quotes — both round-trip correctly.
- **144 of the 286** were read back and round-tripped end to end: 144 stable, 0
  unknown keys, 0 parse failures. **The other 142 could not be read by the audit
  script** (no user context for `getById`) — so this measurement covers *half the
  park*, and says nothing about the other half.
- The controller only `trim()`s the title. **Nothing prevents any of these
  defects.** They are latent, not fixed: we have been lucky, and luck is not a
  design.

## Open questions — not decided here

1. **The accented keys.** Rename to English with a migration, accept both on read,
   or keep? Breaking change on live data.
2. **`due_date` type.** Date, or date-time? Deck carries a time; truncating it
   loses it permanently, since the file *is* the record. (Escalated by
   `camille-ux-nextcloud` for the Deck migration.)
3. **Archiving as a column.** `Archivé` being a column means an archived card
   cannot remember where it came from — unarchiving is guesswork.

## Related

- Test suite: `tests/Unit/Kanban/CardRoundTripTest.php` (`@group card-md-format`)
- Test procedures: [09-testing-playbook.md](09-testing-playbook.md)
- Architecture: [02-architecture.md](02-architecture.md)

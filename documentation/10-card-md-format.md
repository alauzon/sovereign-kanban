<!--
SPDX-FileCopyrightText: 2026 Alain Lauzon <alauzon@alainlauzon.com>
SPDX-License-Identifier: CC-BY-4.0
-->

# The `card.md` format — specification v1.0

**Status: in force.** The conformance suite
[`CardRoundTripTest`](../apps/sovereign-kanban-md-persistence/tests/Unit/Kanban/CardRoundTripTest.php)
is the executable form of this document and is **green**. This spec is true
exactly as long as that suite is green; if you change the format, change the
suite in the same commit or the spec is a claim again.

*Written RED on 2026-07-15 (11 tests, 4 errors, 7 failures), green the same day.*

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

The substrate carries no new licence: `card.md` is **CommonMark** (CC-BY-SA-4.0
spec) with a **YAML 1.2** frontmatter block (MIT-licensed spec). We invent no
syntax. We only name the keys.

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
  closing. It MUST be at the start of the file.
- The body is everything after the closing delimiter, with leading newlines
  stripped. **The body IS the card's description.** A `---` inside the body is a
  horizontal rule and MUST NOT be treated as a delimiter.
- The file MUST be UTF-8, without BOM.

## The keys

Eleven keys. `id`, `title` and `column` are required; the rest are omitted from
the file when empty.

**All keys are English** — decided by Alain on 2026-07-15, and it is the standard
for identifiers across this project. Not a style preference: three keys used to be
French (`procédures`, `priorité`, `étiquettes`) beside eight English ones, which
was not a linguistic choice but an accident of who wrote which increment. A format
meant to be implemented by strangers cannot ask them to read French for three keys
out of eleven.

| Key | Type | Required | Notes |
|---|---|---|---|
| `id` | string (UUIDv4) | yes | Stable. Survives moves and renames. Authoritative over the folder name. |
| `title` | string | yes | Free text. Any content, including `:`, `[`, `#`, newlines. |
| `column` | string | yes | The column folder name, `NN-` prefix included. Resynced on move. |
| `created_at` | string | yes | `Y-m-d\TH:i:s\Z`, a true UTC instant. |
| `due_date` | string | no | `Y-m-d\TH:i` (date-time) or `Y-m-d` (no time known). |
| `start_date` | string | no | Same. |
| `assignees` | list of string | no | Nextcloud user ids. |
| `procedures` | list of string | no | |
| `priority` | string | no | |
| `tags` | list of string | no | |
| `phase` | integer | no | |

The Markdown body is the twelfth field (`description`) and has no key.

### Reading legacy files

`procédures`, `priorité` and `étiquettes` are **accepted on read, never written**.
Files written before 2026-07-15 carry them and migrate silently the next time the
app writes the card. No migration script, no downtime. There is no deadline for
removing this: reading an old file costs one `??`.

### Dates

`due_date` and `start_date` are **date-times** (decided by Alain, 2026-07-15).
Deck carries a time and truncating it lost it for good, since the file — not a
cache — is the record.

- Canonical form: `Y-m-d\TH:i`.
- A date with no known time stays `Y-m-d`. **A time is never invented.**
- A non-canonical value (`2026-07-20 14:30`, with a space) is normalized on read
  **without losing the time**. Normalizing is allowed; truncating is not.
- Consequence in the UI: the editor uses `<input type="datetime-local">`, and
  midnight round-trips back to a plain date — so a card with no time never
  acquires one by being opened.

## Rules the format obeys

Each rule is a test in the conformance suite.

1. **Round-trip identity** — reading a file and writing it back produces the same
   card. `parse(serialize(c)) == c`.
2. **Fixed point** — `serialize(parse(x)) == x` for any file the app itself wrote.
   The app rewrites `card.md` on every move and every edit; a format without a
   fixed point degrades on each pass. (Normalizing a hand-written value on first
   read is allowed — that is rule 4, not a violation of this one.)
3. **Escaping** — any value survives any legal content. A title holding `:`, `[`,
   `{`, a leading `#`, a trailing space or a newline round-trips unchanged.
4. **Instants** — `created_at` denotes the same instant after a round-trip,
   whatever timezone it was created in.
5. **Unknown keys are preserved** — a key this app does not know is written back
   untouched. This rule is the sovereignty claim itself: **an app that silently
   eats what it does not understand does not host your data, it borrows it.**
   The README says cards are readable in Obsidian; Obsidian writes `aliases` and
   `cssclass`, and they now survive.
6. **The body is opaque** — the app does not reformat, reflow or reinterpret the
   Markdown body. It is the user's text.
7. **Closed vocabulary, open file** — the app understands eleven keys. It does not
   require that a file contain only those (rule 5).

## The root cause, named once

The writer used to be **hand-rolled string concatenation** while the reader was a
**real YAML parser** (`symfony/yaml`). An emitter that does not escape cannot
round-trip through a parser that interprets. Rules 1, 2, 3 and most of 5 were the
same defect seen from different angles.

The fix was not to patch the cases one by one: the emitter is now
`Yaml::dump()` — the exact inverse of the parser already in use — and the `Card`
keeps the raw unknown frontmatter in `$extra` so those keys have somewhere to live.

**Consequence, accepted knowingly:** `Yaml::dump` quotes any value containing a
space, so most titles are now written `title: 'Something like this'`. That is
noisier to read raw and it is the correct trade. Writing an emitter that "only
quotes when needed" would be re-introducing exactly the hand-rolled logic that
caused the bug.

## What was actually broken, and how exposed we were

Measured on 2026-07-15, before the fix — not imagined:

| What a member could type | What used to happen |
|---|---|
| `Corriger: le bug du partage` | the parser threw — one card would break the whole board listing |
| `#urgent revoir la palette` | title came back **empty**, silently (YAML comment) |
| `[SdP] réunion`, `{{ modèle }}` | the parser threw |
| a title on two lines | the parser threw |
| tag `prio: haute` | became a mapping, then the literal string `Array` on the next write |
| a card created at 10:00 in Montréal | read back as 10:00 UTC — off by 4 hours (`\Z` was a literal Z, not a conversion) |
| `due_date: 2026-07-20 14:30` | truncated to `2026-07-20` |
| `aliases`, `cssclass` from Obsidian | destroyed on rewrite |

**The exposure, honestly** (Entre Tablées, 2026-07-15):

- **135 live cards.** Not 286: a first count queried `oc_filecache` for
  `name = 'card.md'`, got 286 rows, and called them cards. They break down as
  **142 encryption keyfiles** (an artifact of encryption at rest, named after the
  file they protect), **9 in the trash**, and **135 real cards**. The keyfiles are
  also exactly the "142 unreadable" that the first audit reported as a coverage
  gap — they were never cards. *The lesson is the recurring one: probing one organ
  and concluding about the organism.*
- **Zero live card** has a title containing `:`, `[`, `{`, `"`, or a leading `#`.
  **53 contain an apostrophe** — which always round-tripped. (Two titles with
  quotes exist only in the trash.)
- **127 live cards** were read back and round-tripped end to end through the
  deployed code: **127 stable, 0 parse failures, 0 unknown keys, 15 carrying the
  legacy French keys.** The 8-card gap against 135 is unexplained — the audit walks
  each user's `Kanban/` folder, so cards living elsewhere are not counted.
- The controller only `trim()`s the title. **Nothing prevented any of it.** The
  defects were latent, not absent: we had been lucky, and luck is not a design.
  The day someone types `Corriger: le bug`, their board stops listing.

## Open questions — not decided here

**Archiving as a column** (deferred by Alain, 2026-07-15). `Archivé` being a
column means an archived card does not remember where it came from, so unarchiving
is guesswork. Nothing archives today; revisit when something does.

## Related

- Conformance suite: `tests/Unit/Kanban/CardRoundTripTest.php` (`@group card-md-format`)
- Test procedures: [09-testing-playbook.md](09-testing-playbook.md)
- Architecture: [02-architecture.md](02-architecture.md)

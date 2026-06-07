<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use OCA\SovereignKanbanMdPersistence\Storage\Storage;
use Symfony\Component\Yaml\Yaml;

/**
 * Card templates ("Modèles") and process snippets ("Procédures").
 *
 * Both are plain .md files in dedicated folders of the Kanban root, so a human
 * can read, copy, edit or delete them in Nextcloud Files, Obsidian, or any
 * plain text editor — the app only reads them to offer shortcuts. Defaults are
 * seeded ONLY when a folder is absent, so user edits and deletions persist.
 */
final class TemplateLibrary {

	private const TEMPLATES_DIR = 'Modèles';
	private const PROCEDURES_DIR = 'Procédures';

	public function __construct(
		private readonly Storage $storage,
	) {
	}

	/**
	 * @return list<array{name: string, meta: array<string, mixed>, body: string}>
	 */
	public function templates(): array {
		return $this->load(self::TEMPLATES_DIR, self::defaultTemplates());
	}

	/**
	 * @return list<array{name: string, meta: array<string, mixed>, body: string}>
	 */
	public function procedures(): array {
		return $this->load(self::PROCEDURES_DIR, self::defaultProcedures());
	}

	/**
	 * Read all .md in a folder, seeding the defaults only when the folder is
	 * absent. Each item exposes its parsed frontmatter and its Markdown body
	 * (without the frontmatter).
	 *
	 * @param array<string, string> $defaults Name => full .md content.
	 *
	 * @return list<array{name: string, meta: array<string, mixed>, body: string}>
	 */
	private function load(string $dir, array $defaults): array {
		if (!$this->storage->exists($dir)) {
			$this->storage->makeDir($dir);
			foreach ($defaults as $name => $content) {
				$this->storage->write($dir . '/' . $name . '.md', $content);
			}
		}

		$items = [];
		foreach ($this->storage->childFiles($dir) as $file) {
			if (!str_ends_with($file, '.md')) {
				continue;
			}
			$parsed = $this->parse($this->storage->read($dir . '/' . $file));
			$items[] = [
				'name' => substr($file, 0, -3),
				'meta' => $parsed['meta'],
				'body' => $parsed['body'],
			];
		}

		usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		return $items;
	}

	/**
	 * Split optional YAML frontmatter from the Markdown body.
	 *
	 * @return array{meta: array<string, mixed>, body: string}
	 */
	private function parse(string $content): array {
		if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $m) === 1) {
			try {
				$meta = Yaml::parse($m[1]);
			} catch (\Throwable) {
				$meta = [];
			}

			return ['meta' => is_array($meta) ? $meta : [], 'body' => $m[2]];
		}

		return ['meta' => [], 'body' => $content];
	}

	/**
	 * @return array<string, string>
	 */
	public static function defaultTemplates(): array {
		return [
			'Réunion sociocratique' => self::TEMPLATE_MEETING,
			'Compte-rendu de réunion' => self::TEMPLATE_MINUTES,
			'Rencontre en 4 temps (SdP)' => self::TEMPLATE_FOUR_TIMES,
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function defaultProcedures(): array {
		return [
			'Élection sans candidat' => self::PROC_ELECTION,
			'Décision par consentement' => self::PROC_CONSENT,
			'Récolte d\'objections' => self::PROC_OBJECTIONS,
		];
	}

	private const TEMPLATE_MEETING = <<<'MD'
---
gabarit: Réunion sociocratique
icône: 🟢
colonne_cible: En cours
étiquettes: [réunion, gouvernance]
procédures: ["Élection sans candidat", "Décision par consentement", "Récolte d'objections"]
---

## Réunion — cercle :  — date :

**Lieu / lien :**

### Rôles
| Rôle | Personne |
|---|---|
| Facilitateur·rice |  |
| Secrétaire (notes) |  |
| Gardien·ne du temps |  |

### Présences
- **Présent·e·s :**
- **Excusé·e·s :**

### Tour d'ouverture (check-in)
> Comment j'arrive aujourd'hui.

### Ordre du jour (consenti en début de réunion)
1.
2.

---

### Point 1 —
- **Présentation (porteur·euse) :**
- **Tour de clarification :**
- **Tour de réaction :**
- **Amendements :**
- **Consentement :**
  - [ ] Consenti
  - [ ] Objections à traiter
- **Objections traitées :**

### Décisions prises
| Décision | Domaine | À réviser le |
|---|---|---|
|  |  |  |

### Actions
| Action | Qui | Quand |
|---|---|---|
|  |  |  |

### Tour de clôture (check-out)
> Un retour sur la réunion.

**Prochaine réunion :**
MD;

	private const TEMPLATE_MINUTES = <<<'MD'
---
gabarit: Compte-rendu de réunion
icône: 📋
colonne_cible: Terminé
étiquettes: [compte-rendu, gouvernance]
---

## Cercle :  — date :

**Présent·e·s :**   **Excusé·e·s :**   **Facilitation / notes :**

### Décisions
| Décision | Domaine | À réviser le |
|---|---|---|
|  |  |  |

### Actions
| Action | Qui | Quand |
|---|---|---|
|  |  |  |

### Points reportés
-

### Notes saillantes
-
MD;

	private const TEMPLATE_FOUR_TIMES = <<<'MD'
---
gabarit: Rencontre en 4 temps (SdP)
icône: 🌱
colonne_cible: En cours
étiquettes: [rencontre, 4-temps]
procédures: ["Décision par consentement", "Récolte d'objections"]
---

## Rencontre en 4 temps — date :

**Participant·e·s :**

### 1. Proposition / événement
_Ce qu'on met sur la table, l'invitation de départ._

### 2. Émerveillement
_Ce qui nous frappe, nous inspire, nous attire là-dedans._

### 3. Écoute
_Ce qu'on entend des un·e·s et des autres. Tours de parole._

### 4. Construction commune
_Ce qu'on bâtit ensemble à partir de ce qui a émergé._

### Ce qui émerge / suites
- **Décisions :**
- **Actions :**
- **À revisiter :**
MD;

	private const PROC_ELECTION = <<<'MD'
### Élection sans candidat — rôle :

1. **Définir** le rôle, le domaine, la durée du mandat.
2. **Nominations** : chacun·e écrit « je nomme ___ » (on peut se nommer soi-même).
3. **Tour de raisons** : chacun·e dit pourquoi sa nomination.
4. **Tour de changement** : qui veut changer sa nomination à la lumière des raisons.
5. **Proposition** : le·la facilitateur·rice propose une personne.
6. **Tour de consentement** sur la proposition.
7. **Objections** traitées, puis **célébration**.

- **Mandat jusqu'au :**
- **Élu·e :**
MD;

	private const PROC_CONSENT = <<<'MD'
### Décision par consentement — sujet :

1. **Présentation** de la proposition par le·la porteur·euse.
2. **Tour de clarification** (questions de compréhension, pas de débat).
3. **Tour de réaction** (chacun·e réagit, sans dialogue croisé).
4. **Amender / clarifier** la proposition si utile.
5. **Tour de consentement** : « as-tu une objection raisonnée ? »
6. **Traiter les objections** (elles bonifient la proposition).

- **Décision :**
  - [ ] Consentie
  - [ ] Consentie avec amendements
  - [ ] Reportée
- **Essai jusqu'au :**
MD;

	private const PROC_OBJECTIONS = <<<'MD'
### Récolte d'objections — proposition :

1. **Tour** : chacun·e dit « objection » ou « pas d'objection ».
2. Pour chaque objection, **noter** sans débattre pour l'instant.
3. **Traiter** une objection à la fois : en quoi empêche-t-elle le travail du cercle ?
4. **Bonifier** la proposition pour intégrer l'objection.

| Objection | Portée par | Bonification proposée |
|---|---|---|
|  |  |  |
MD;
}

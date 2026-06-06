<?php

/**
 * @file
 * Board value object for Sovereign Kanban.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use DateTime;
use Symfony\Component\Yaml\Yaml;

/**
 * Immutable Board value object.
 *
 * A board is a directory under the Kanban root; its config lives in
 * {board}/.board.yml. The id is a slug derived from the name and stays
 * stable when the name changes, so the folder never moves on rename.
 */
final class Board {

	private const DEFAULT_COLUMNS = ['Backlog', 'En cours', 'Terminé', 'Archivé'];

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $color,
		public readonly array $columns = self::DEFAULT_COLUMNS,
		public readonly DateTime $created_at = new DateTime(),
	) {
	}

	/**
	 * Create a new board, deriving a stable slug id from the name.
	 */
	public static function create(string $name, string $color): self {
		return new self(
			id: self::slugify($name),
			name: $name,
			color: $color,
		);
	}

	/**
	 * Return a copy with a new name; the id (slug) stays stable.
	 *
	 * Renaming must not move the folder, so the slug is preserved.
	 */
	public function withName(string $name): self {
		return new self(
			id: $this->id,
			name: $name,
			color: $this->color,
			columns: $this->columns,
			created_at: $this->created_at,
		);
	}

	/**
	 * Return a copy with a new color.
	 */
	public function withColor(string $color): self {
		return new self(
			id: $this->id,
			name: $this->name,
			color: $color,
			columns: $this->columns,
			created_at: $this->created_at,
		);
	}

	/**
	 * Serialize board config as YAML for .board.yml.
	 */
	public function toYaml(): string {
		return Yaml::dump([
			'id' => $this->id,
			'name' => $this->name,
			'color' => $this->color,
			'columns' => array_values($this->columns),
			'created_at' => $this->created_at->format('Y-m-d\TH:i:s\Z'),
		], 4, 2);
	}

	/**
	 * Turn a human name into a URL/folder-safe slug.
	 *
	 * Lowercases, transliterates common accents, collapses any run of
	 * non-alphanumeric characters to a single hyphen, trims hyphens.
	 */
	private static function slugify(string $name): string {
		$map = [
			'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
		];

		$slug = mb_strtolower($name, 'UTF-8');
		$slug = strtr($slug, $map);
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

		return trim($slug, '-');
	}
}

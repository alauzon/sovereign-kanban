<?php

/**
 * @file
 * Renders Sovereign Kanban notifications (Alain, 2026-07-19): a card reaching its
 * due date, façon Deck (« La carte X a atteint sa date d'échéance »).
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Notification;

use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier {

	public function __construct(
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'sovereign-kanban-md-persistence';
	}

	public function getName(): string {
		return 'Sovereign Kanban';
	}

	/**
	 * Turn a stored notification into a human message.
	 *
	 * @throws UnknownNotificationException When it is not one of ours.
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'sovereign-kanban-md-persistence') {
			throw new UnknownNotificationException();
		}

		$params = $notification->getSubjectParameters();

		if ($notification->getSubject() === 'announcement') {
			// A one-off maintenance/announcement notice (Alain, 2026-07-19).
			$notification
				->setParsedSubject($params['subject'] ?? 'Annonce')
				->setParsedMessage($params['message'] ?? '')
				->setLink($this->url->linkToRouteAbsolute('sovereign-kanban.page.index'));
			return $notification;
		}

		if ($notification->getSubject() === 'card_mention') {
			// « X vous a mentionné sur la carte Y » (carte 78fc32). Deep link to the
			// exact card, on the tab where the mention lives — Commentaires for a
			// comment, Détails for a description mention (6ef9a7, Alain 2026-07-22).
			$author = $params['author'] ?? '';
			$title = $params['title'] ?? '';
			$notification
				->setParsedSubject($author . ' vous a mentionné sur la carte « ' . $title . ' »')
				->setLink($this->cardLink(
					$params['board'] ?? '',
					$notification->getObjectId(),
					$params['tab'] ?? 'comments',
				));
			return $notification;
		}

		if ($notification->getSubject() === 'card_assigned') {
			// « X vous a assigné la carte Y » (Alain, 2026-07-22). Same clickable
			// mechanism; the card opens on Détails (no tab suffix).
			$author = $params['author'] ?? '';
			$title = $params['title'] ?? '';
			$notification
				->setParsedSubject($author . ' vous a assigné la carte « ' . $title . ' »')
				->setLink($this->cardLink($params['board'] ?? '', $notification->getObjectId()));
			return $notification;
		}

		if ($notification->getSubject() !== 'card_due') {
			throw new UnknownNotificationException();
		}

		$title = $params['title'] ?? '';
		$board = $params['board'] ?? '';

		$notification
			->setParsedSubject('La carte « ' . $title . ' » a atteint sa date d\'échéance')
			->setParsedMessage($board !== '' ? 'Tableau : ' . $board : '')
			->setLink($this->url->linkToRouteAbsolute('sovereign-kanban.page.index'));

		return $notification;
	}

	/**
	 * Build the SPA deep-link a card notification opens: #<board>/<card>/<tab>. A
	 * 'details' (or empty) tab is left off, so the card opens on Détails; 'comments'
	 * lands the reader on the mention (Alain, 2026-07-22).
	 */
	private function cardLink(string $board, string $card, string $tab = ''): string {
		$link = $this->url->linkToRouteAbsolute('sovereign-kanban.page.index');
		if ($board === '') {
			return $link;
		}
		$link .= '#' . rawurlencode($board);
		if ($card !== '') {
			$link .= '/' . rawurlencode($card);
			if ($tab !== '' && $tab !== 'details') {
				$link .= '/' . $tab;
			}
		}

		return $link;
	}
}

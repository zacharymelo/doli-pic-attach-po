<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_rfqimages.class.php
 * \ingroup rfqimages
 * \brief   Hooks: inject product images into the price request email form
 */

/**
 * Hook class for rfqimages
 */
class ActionsRfqImages
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Session key holding the links block for one email form
	 *
	 * @param  string $trackid Mail form trackid
	 * @return string
	 */
	public static function sessionKey($trackid)
	{
		return 'rfqimages_links-'.$trackid;
	}

	/**
	 * Price request id from a mail trackid ('spro123' => 123), 0 if not a price request
	 *
	 * @param  string $trackid Trackid
	 * @return int
	 */
	public static function proposalIdFromTrackid($trackid)
	{
		$reg = array();
		return preg_match('/^spro(\d+)$/', (string) $trackid, $reg) ? (int) $reg[1] : 0;
	}

	/**
	 * Called by FormMail::get_form() after attachments are cleared and before they are listed.
	 * On a fresh form (mode=init) for a price request, attaches flagged product images,
	 * or prepares share links when they are too large.
	 *
	 * @param  array<string,mixed> $parameters Hook parameters (trackid, ...)
	 * @param  FormMail            $object     The mail form
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0 so the core form is still rendered
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		if (!isModEnabled('rfqimages') || GETPOST('mode', 'alpha') !== 'init') {
			return 0;
		}
		$trackid = isset($parameters['trackid']) ? $parameters['trackid'] : '';
		$proposalid = self::proposalIdFromTrackid($trackid);
		if ($proposalid <= 0 || !$user->hasRight('supplier_proposal', 'lire')) {
			return 0;
		}

		// Fresh form: drop links from an earlier form (card_presend already substituted them before this hook)
		unset($_SESSION[self::sessionKey($trackid)]);
		if (!is_array($object->substit)) {
			$object->substit = array();
		}
		$object->substit['__RFQIMAGES_LINKS__'] = '';

		require_once DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php';
		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
		$langs->load('rfqimages@rfqimages');

		$proposal = new SupplierProposal($this->db);
		if ($proposal->fetch($proposalid) <= 0) {
			return 0;
		}

		$service = new RfqImagesService($this->db);
		$files = $service->collectForProposal($proposal);
		if (empty($files)) {
			return 0;
		}

		$total = 0;
		foreach ($files as $f) {
			$total += $f['size'];
		}
		$limitmb = (float) getDolGlobalString('RFQIMAGES_MAX_ATTACH_MB', '10');
		$uselinks = ($limitmb > 0 && $total > $limitmb * 1024 * 1024);

		if (!$uselinks) {
			$n = 0;
			foreach ($files as $f) {
				$copy = $service->copyToMailTemp($f, $user);
				if ($copy) {
					$object->add_attached_files($copy['path'], $copy['name'], $f['mime']);
					$n++;
				}
			}
			if ($n) {
				setEventMessages($langs->trans('RfqImagesAttached', $n), null, 'mesgs');
			}
		} else {
			$links = array();
			foreach ($files as $f) {
				$url = $service->getShareUrl($f['fullpath'], $user);
				if ($url) {
					$links[] = array('name' => $f['name'], 'productref' => $f['productref'], 'url' => $url);
				}
			}
			if ($links) {
				$html = (bool) getDolGlobalInt('FCKEDITOR_ENABLE_MAIL');
				$block = RfqImagesService::buildLinksBlock($links, $html);
				$_SESSION[self::sessionKey($trackid)] = json_encode(array('links' => $links));
				$object->substit['__RFQIMAGES_LINKS__'] = $block;
				setEventMessages($langs->trans('RfqImagesLinked', count($links), dol_print_size($total, 1)), null, 'warnings');
			}
		}

		if ($service->errors) {
			setEventMessages($langs->trans('RfqImagesSomeFailed'), $service->errors, 'errors');
		}

		return 0;
	}

	/**
	 * Before core sends the price request email: append the links block if the message does not contain it
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject        $object     SupplierProposal
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('rfqimages') || $action !== 'send' || empty($object->id)) {
			return 0;
		}
		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if (!in_array('supplier_proposalcard', $contexts)) {
			return 0;
		}
		// Only the real send, not add/remove attachment or template reloads
		if (GETPOST('addfile') || GETPOST('removedfile') || GETPOST('removeAll') || GETPOST('cancel') || GETPOST('modelselected')) {
			return 0;
		}

		$trackid = 'spro'.$object->id;
		$key = self::sessionKey($trackid);
		if (empty($_SESSION[$key])) {
			return 0;
		}
		$data = json_decode($_SESSION[$key], true);
		unset($_SESSION[$key]);

		if (!getDolGlobalInt('RFQIMAGES_AUTO_APPEND_LINKS') || empty($data['links'])) {
			return 0;
		}

		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

		$message = isset($_POST['message']) ? (string) $_POST['message'] : '';
		// Already present: the template used __RFQIMAGES_LINKS__, or the user kept the key for send-time substitution
		if (strpos($message, '__RFQIMAGES_LINKS__') !== false || strpos($message, $data['links'][0]['url']) !== false
			|| strpos($message, dol_escape_htmltag($data['links'][0]['url'])) !== false) {
			if (strpos($message, '__RFQIMAGES_LINKS__') !== false) {
				// Keep the data for the substitution function at send time
				$_SESSION[$key] = json_encode($data);
			}
			return 0;
		}

		$html = dol_textishtml($message);
		$block = RfqImagesService::buildLinksBlock($data['links'], $html);
		$_POST['message'] = $message.($html ? '<br>'.$block : "\n\n".$block);

		return 0;
	}
}

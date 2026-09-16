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
 * \brief   Hooks: inject product images into price request and purchase order email forms
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
	 * Supported vendor documents, keyed by mail trackid prefix
	 *
	 * @var array<string,array{element:string,context:string,class:string,file:string,const:string}>
	 */
	public static $documents = array(
		'spro' => array(
			'element' => 'supplier_proposal',
			'context' => 'supplier_proposalcard',
			'class' => 'SupplierProposal',
			'file' => '/supplier_proposal/class/supplier_proposal.class.php',
			'const' => 'RFQIMAGES_ON_SUPPLIER_PROPOSAL',
		),
		'sord' => array(
			'element' => 'order_supplier',
			'context' => 'ordersuppliercard',
			'class' => 'CommandeFournisseur',
			'file' => '/fourn/class/fournisseur.commande.class.php',
			'const' => 'RFQIMAGES_ON_SUPPLIER_ORDER',
		),
	);

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
	 * Whether sending images is enabled for a document type (on unless switched off in setup)
	 *
	 * @param  string $prefix Trackid prefix
	 * @return bool
	 */
	public static function isEnabledFor($prefix)
	{
		return isset(self::$documents[$prefix]) && getDolGlobalString(self::$documents[$prefix]['const'], '1') !== '0';
	}

	/**
	 * Whether the user may read a document type
	 *
	 * @param  string $prefix Trackid prefix
	 * @param  User   $user   User
	 * @return bool
	 */
	public static function userCanRead($prefix, $user)
	{
		if ($prefix === 'spro') {
			return (bool) $user->hasRight('supplier_proposal', 'lire');
		}
		if ($prefix === 'sord') {
			return $user->hasRight('fournisseur', 'commande', 'lire') || $user->hasRight('supplier_order', 'lire');
		}
		return false;
	}

	/**
	 * Called by FormMail::get_form() after attachments are cleared and before they are listed.
	 * On a fresh form (mode=init) for a price request or purchase order, attaches flagged product
	 * images, or prepares share links when they are too large.
	 *
	 * @param  array<string,mixed> $parameters Hook parameters (trackid, ...)
	 * @param  FormMail            $object     The mail form
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0 so the core form is still rendered
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('rfqimages') || GETPOST('mode', 'alpha') !== 'init') {
			return 0;
		}
		$trackid = isset($parameters['trackid']) ? (string) $parameters['trackid'] : '';
		$reg = array();
		if (!preg_match('/^(spro|sord)(\d+)$/', $trackid, $reg)) {
			return 0;
		}
		$prefix = $reg[1];
		$docid = (int) $reg[2];
		if (!self::isEnabledFor($prefix) || !self::userCanRead($prefix, $user)) {
			return 0;
		}

		// Fresh form: drop links prepared by an earlier form
		unset($_SESSION[self::sessionKey($trackid)]);

		$doc = self::$documents[$prefix];
		require_once DOL_DOCUMENT_ROOT.$doc['file'];
		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
		$langs->load('rfqimages@rfqimages');

		$classname = $doc['class'];
		$document = new $classname($this->db);
		if ($document->fetch($docid) <= 0) {
			return 0;
		}
		if (empty($document->lines) && method_exists($document, 'fetch_lines')) {
			$document->fetch_lines();
		}

		$service = new RfqImagesService($this->db);
		$files = $service->collectForDocument($document);
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
				$_SESSION[self::sessionKey($trackid)] = json_encode(array('links' => $links));
				setEventMessages($langs->trans('RfqImagesLinked', count($links), dol_print_size($total, 1)), null, 'warnings');
			}
		}

		if ($service->errors) {
			setEventMessages($langs->trans('RfqImagesSomeFailed'), $service->errors, 'errors');
		}

		return 0;
	}

	/**
	 * On price request / purchase order cards, before core sends the email:
	 * append the share links prepared when the form was opened
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject        $object     SupplierProposal or CommandeFournisseur
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('rfqimages')) {
			return 0;
		}
		if ($action !== 'send' || empty($object->id)) {
			return 0;
		}
		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		$prefix = '';
		foreach (self::$documents as $p => $doc) {
			if (in_array($doc['context'], $contexts)) {
				$prefix = $p;
			}
		}
		if ($prefix === '') {
			return 0;
		}
		// Only the real send, not add/remove attachment or template reloads
		if (GETPOST('addfile') || GETPOST('removedfile') || GETPOST('removeAll') || GETPOST('cancel') || GETPOST('modelselected')) {
			return 0;
		}

		$key = self::sessionKey($prefix.$object->id);
		if (empty($_SESSION[$key])) {
			return 0;
		}
		$data = json_decode($_SESSION[$key], true);
		unset($_SESSION[$key]);

		if (empty($data['links'])) {
			return 0;
		}

		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

		$message = isset($_POST['message']) ? (string) $_POST['message'] : '';
		// Already present (e.g. pasted by the user)
		if (strpos($message, $data['links'][0]['url']) !== false || strpos($message, dol_escape_htmltag($data['links'][0]['url'])) !== false) {
			return 0;
		}

		$html = dol_textishtml($message);
		$block = RfqImagesService::buildLinksBlock($data['links'], $html);
		$_POST['message'] = $message.($html ? '<br>'.$block : "\n\n".$block);

		return 0;
	}
}

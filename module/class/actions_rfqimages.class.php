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
	 * Trackid prefix for an element ('supplier_proposal' => 'spro'), '' if unsupported
	 *
	 * @param  string $element Object element
	 * @return string
	 */
	public static function prefixForElement($element)
	{
		foreach (self::$documents as $prefix => $doc) {
			if ($doc['element'] === $element) {
				return $prefix;
			}
		}
		return '';
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

		// Copy (and resize, if set up) first: the size limit applies to what would actually be attached
		$copies = array();
		$total = 0;
		foreach ($files as $f) {
			$copy = $service->copyToMailTemp($f, $user);
			if ($copy) {
				$copies[] = array('copy' => $copy, 'mime' => $f['mime']);
				$total += $copy['size'];
			}
		}
		$limitmb = (float) getDolGlobalString('RFQIMAGES_MAX_ATTACH_MB', '10');
		$uselinks = ($limitmb > 0 && $total > $limitmb * 1024 * 1024);

		if (!$uselinks) {
			foreach ($copies as $c) {
				$object->add_attached_files($c['copy']['path'], $c['copy']['name'], $c['mime']);
			}
			if ($copies) {
				setEventMessages($langs->trans('RfqImagesAttached', count($copies)), null, 'mesgs');
			}
		} else {
			foreach ($copies as $c) {
				dol_delete_file($c['copy']['path'], 0, 1, 1, null, false, 0);
			}
			// Links are created when the email is sent (doActions), never while the form is only open
			$_SESSION[self::sessionKey($trackid)] = json_encode(array('toolarge' => 1));
			setEventMessages($langs->trans('RfqImagesLinked', count($files), dol_print_size($total, 1)), null, 'warnings');
		}

		if ($service->errors) {
			setEventMessages($langs->trans('RfqImagesSomeFailed'), $service->errors, 'errors');
		}

		return 0;
	}

	/**
	 * On the product Documents tab: handle the "Vendor email images" section actions.
	 * On price request / purchase order cards, before core sends the email, add share links:
	 * - in place of __RFQIMAGES_LINKS__ when the message contains it (files stay attached too)
	 * - at the end of the message when the files were too large to attach and there is no key
	 * Share hashes are only created here, so opening an email form never makes files public.
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject        $object     SupplierProposal or CommandeFournisseur
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('rfqimages')) {
			return 0;
		}
		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');

		if (in_array('productdocuments', $contexts) && strpos((string) $action, 'rfqimages_') === 0) {
			return $this->doProductDocumentsActions($object, $action);
		}

		if ($action !== 'send' || empty($object->id)) {
			return 0;
		}
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

		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

		$key = self::sessionKey($prefix.$object->id);
		$data = empty($_SESSION[$key]) ? array() : json_decode($_SESSION[$key], true);
		unset($_SESSION[$key]);

		$message = isset($_POST['message']) ? (string) $_POST['message'] : '';
		$haskey = (strpos($message, RfqImagesService::LINKS_KEY) !== false);
		if (!$haskey && empty($data['toolarge'])) {
			return 0;
		}

		$links = array();
		if (self::isEnabledFor($prefix) && self::userCanRead($prefix, $user)) {
			if (empty($object->lines) && method_exists($object, 'fetch_lines')) {
				$object->fetch_lines();
			}
			$service = new RfqImagesService($this->db);
			$links = $service->buildShareLinks($service->collectForDocument($object), $user);
			if ($service->errors) {
				$langs->load('rfqimages@rfqimages');
				setEventMessages($langs->trans('RfqImagesSomeFailed'), $service->errors, 'errors');
			}
		}

		$html = dol_textishtml($message);
		$block = RfqImagesService::buildLinksBlock($links, $html);
		if ($haskey) {
			// In HTML, replace a paragraph holding only the key so the block is not nested in a <p>
			$message = preg_replace('#<p>\s*'.preg_quote(RfqImagesService::LINKS_KEY, '#').'\s*</p>#i', $block, $message);
			$_POST['message'] = str_replace(RfqImagesService::LINKS_KEY, $block, $message);
		} elseif ($block !== '') {
			$_POST['message'] = $message.($html ? '<br>'.$block : "\n\n".$block);
		}

		return 0;
	}

	/**
	 * Product Documents tab: add the "Vendor email images" section, below the list of linked files
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject        $object     Product
	 * @param  string              $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user, $form;

		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if (!isModEnabled('rfqimages') || !in_array('productdocuments', $contexts) || empty($object->id) || $object->element !== 'product') {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
		$langs->load('rfqimages@rfqimages');
		if (!is_object($form)) {
			$form = new Form($this->db);
		}
		if (!isset($object->array_options['options_rfqimages_send'])) {
			$object->fetch_optionals();
		}

		$service = new RfqImagesService($this->db);
		$permwrite = self::userCanEditProduct($object, $user);
		$self = DOL_URL_ROOT.'/product/document.php?id='.((int) $object->id);

		ob_start();
		include dol_buildpath('/rfqimages/tpl/product_documents_panel.tpl.php', 0);
		$html = ob_get_clean();

		// The Documents tab prints hook output above the upload form and file list:
		// render hidden, then move it to the end of the tab once the page is loaded.
		$this->resprints = '<div id="rfqimages-panel-relocate" style="display:none;">'.$html.'</div>'
			.'<script>
			document.addEventListener("DOMContentLoaded", function () {
				var wrap = document.getElementById("rfqimages-panel-relocate");
				if (!wrap) { return; }
				var fiche = document.querySelector("div.fiche");
				if (fiche) { fiche.appendChild(wrap); }
				wrap.style.display = "";
			});
			</script>';

		return 0;
	}

	/**
	 * Whether the user may change a product's vendor email settings
	 *
	 * @param  Product $product Product
	 * @param  User    $user    User
	 * @return bool
	 */
	public static function userCanEditProduct($product, $user)
	{
		return (bool) ($product->type == Product::TYPE_SERVICE ? $user->hasRight('service', 'creer') : $user->hasRight('produit', 'creer'));
	}

	/**
	 * Actions of the "Vendor email images" section (switch, include choices, revoke link).
	 * Always redirects back to the Documents tab.
	 *
	 * @param  Product $object Product
	 * @param  string  $action rfqimages_setflag | rfqimages_savechoices | rfqimages_revokeshare
	 * @return int             0 when not handled
	 */
	private function doProductDocumentsActions($object, $action)
	{
		global $langs, $user;

		if (empty($object->id) || $object->element !== 'product') {
			return 0;
		}
		dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
		$langs->load('rfqimages@rfqimages');
		$self = DOL_URL_ROOT.'/product/document.php?id='.((int) $object->id);

		if (!self::userCanEditProduct($object, $user)) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			header('Location: '.$self);
			exit;
		}

		$service = new RfqImagesService($this->db);
		$byname = array();
		foreach ($service->listCandidateFiles($object) as $f) {
			$byname[$f['name']] = $f;
		}

		if ($action === 'rfqimages_setflag') {
			$object->array_options['options_rfqimages_send'] = GETPOSTINT('value') ? 1 : 0;
			if ($object->updateExtraField('rfqimages_send', null, $user) < 0) {
				setEventMessages($object->error, $object->errors, 'errors');
			} else {
				setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			}
		} elseif ($action === 'rfqimages_savechoices') {
			$picked = GETPOST('rfqimages_include', 'array');
			$choices = array();
			foreach ($byname as $name => $f) {
				$choices[$name] = in_array($name, (array) $picked, true) ? 1 : 0;
			}
			if ($service->saveChoices($object->id, $choices) < 0) {
				setEventMessages($langs->trans('Error'), $service->errors, 'errors');
			} else {
				setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			}
		} elseif ($action === 'rfqimages_revokeshare') {
			$name = GETPOST('rfqimages_file', 'alphanohtml');
			if (isset($byname[$name])) {
				$r = $service->revokeShare($byname[$name]['fullpath'], $user);
				if ($r < 0) {
					setEventMessages($langs->trans('Error'), $service->errors, 'errors');
				} elseif ($r > 0) {
					setEventMessages($langs->trans('RfqImagesShareRevoked', $name), null, 'mesgs');
				}
			}
		} else {
			return 0;
		}

		header('Location: '.$self.'#rfqimages-panel-relocate');
		exit;
	}
}

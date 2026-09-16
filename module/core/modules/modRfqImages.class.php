<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modRfqImages.class.php
 * \ingroup rfqimages
 * \brief   Descriptor for the RfqImages module: send flagged product images with price requests
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor
 */
class modRfqImages extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 510410;
		$this->family = 'products';
		$this->module_position = '50';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Attach (or link) flagged product images when emailing vendor price requests and purchase orders';
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'image';

		$this->module_parts = array(
			'triggers' => 0,
			'hooks' => array('data' => array('formmail', 'supplier_proposalcard', 'ordersuppliercard'), 'entity' => '0'),
		);

		$this->dirs = array('/rfqimages/temp');

		$this->config_page_url = array('setup.php@rfqimages');

		$this->hidden = false;
		// Works with price requests (modSupplierProposal) and/or purchase orders (modFournisseur)
		$this->depends = array('modProduct');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('rfqimages@rfqimages');
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(16, 0);

		$this->const = array(
			array('RFQIMAGES_EXTENSIONS', 'chaine', 'jpg,jpeg,png,gif,webp', 'File extensions sent with price requests', 0, 'current', 0),
			array('RFQIMAGES_MAX_ATTACH_MB', 'chaine', '10', 'Above this total size (MB), share links are used instead of attachments', 0, 'current', 0),
			array('RFQIMAGES_ON_SUPPLIER_PROPOSAL', 'chaine', '1', 'Send product images with price request emails', 0, 'current', 0),
			array('RFQIMAGES_ON_SUPPLIER_ORDER', 'chaine', '1', 'Send product images with purchase order emails', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->tabs[] = array('data' => 'product:+rfqimages:RfqImagesTab:rfqimages@rfqimages:$user->hasRight(\'produit\', \'lire\') || $user->hasRight(\'service\', \'lire\'):/rfqimages/product_images.php?id=__ID__');

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Uses core product / supplier_proposal / supplier order permissions
		$this->rights = array();
		$this->rights_class = 'rfqimages';

		$this->menu = array();
	}

	/**
	 * Called when module is enabled
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf;

		$result = $this->_load_tables('/rfqimages/sql/');
		if ($result < 0) {
			return -1;
		}

		// Removed in 1.2.0: the __RFQIMAGES_LINKS__ substitution and its auto-append setting.
		// _remove() only clears module parts still declared, so drop the leftovers explicitly.
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, 'RFQIMAGES_AUTO_APPEND_LINKS', $conf->entity);
		dolibarr_del_const($this->db, 'MAIN_MODULE_RFQIMAGES_SUBSTITUTIONS', $conf->entity);

		dol_include_once('/rfqimages/lib/rfqimages.lib.php');
		if (rfqimages_ensure_extrafields($this->db) < 0) {
			$this->error = 'Failed to create product extrafield rfqimages_send';
			return -1;
		}

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Called when module is disabled. Tables and extrafield data are kept.
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}

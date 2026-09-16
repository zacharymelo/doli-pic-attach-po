<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/rfqimagesservice.class.php
 * \ingroup rfqimages
 * \brief   Collects product images for price request emails, copies them for attachment, builds share links
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * Service class for rfqimages
 */
class RfqImagesService
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

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
	 * Allowed extensions from setup, lowercase without dot
	 *
	 * @return string[]
	 */
	public static function getExtensions()
	{
		$raw = getDolGlobalString('RFQIMAGES_EXTENSIONS', 'jpg,jpeg,png,gif,webp');
		$exts = array();
		foreach (explode(',', $raw) as $ext) {
			$ext = strtolower(trim(ltrim(trim($ext), '.')));
			if ($ext !== '' && preg_match('/^[a-z0-9]+$/', $ext)) {
				$exts[] = $ext;
			}
		}
		return array_values(array_unique($exts));
	}

	/**
	 * Absolute documents directory of a product (no trailing slash)
	 *
	 * @param  Product $product Product
	 * @return string
	 */
	public static function getProductDir($product)
	{
		global $conf;

		$entity = !empty($product->entity) ? $product->entity : $conf->entity;
		$module = ($product->type == Product::TYPE_SERVICE && isModEnabled('service')) ? 'service' : 'product';
		if (!isModEnabled($module)) {
			$module = isModEnabled('product') ? 'product' : 'service';
		}
		$base = !empty($conf->$module->multidir_output[$entity]) ? $conf->$module->multidir_output[$entity] : $conf->$module->dir_output;

		return $base.'/'.get_exdir(0, 0, 0, 1, $product, 'product');
	}

	/**
	 * Files in the product folder whose extension is allowed, with their include state
	 *
	 * @param  Product $product Product
	 * @return array<int,array{fullpath:string,name:string,size:int,mime:string,selected:int,productref:string,fk_product:int}>
	 */
	public function listCandidateFiles($product)
	{
		$dir = self::getProductDir($product);
		if (!dol_is_dir($dir)) {
			return array();
		}

		$exts = self::getExtensions();
		if (empty($exts)) {
			return array();
		}
		$filter = '\.('.implode('|', array_map('preg_quote', $exts)).')$';

		$choices = $this->getChoices($product->id);

		$out = array();
		// Top level only: skips thumbs/ and other subfolders
		foreach (dol_dir_list($dir, 'files', 0, $filter, null, 'name', SORT_ASC, 1) as $f) {
			$out[] = array(
				'fullpath' => $f['fullname'],
				'name' => $f['name'],
				'size' => (int) $f['size'],
				'mime' => dol_mimetype($f['name']),
				'selected' => isset($choices[$f['name']]) ? (int) $choices[$f['name']] : 1,
				'productref' => $product->ref,
				'fk_product' => (int) $product->id,
			);
		}
		return $out;
	}

	/**
	 * Files to send for a product: none unless the product is flagged, then only selected ones
	 *
	 * @param  Product $product Product (optionals fetched)
	 * @return array<int,array{fullpath:string,name:string,size:int,mime:string,selected:int,productref:string,fk_product:int}>
	 */
	public function getEligibleFiles($product)
	{
		if (empty($product->array_options['options_rfqimages_send'])) {
			return array();
		}
		return array_values(array_filter($this->listCandidateFiles($product), function ($f) {
			return !empty($f['selected']);
		}));
	}

	/**
	 * Eligible files of every distinct product on a price request
	 *
	 * @param  SupplierProposal $proposal Price request with lines loaded
	 * @return array<int,array{fullpath:string,name:string,size:int,mime:string,selected:int,productref:string,fk_product:int}>
	 */
	public function collectForProposal($proposal)
	{
		$seen = array();
		$files = array();
		foreach ((array) $proposal->lines as $line) {
			$fk = (int) $line->fk_product;
			if ($fk <= 0 || isset($seen[$fk])) {
				continue;
			}
			$seen[$fk] = 1;

			$product = new Product($this->db);
			if ($product->fetch($fk) <= 0) {
				continue;
			}
			$product->fetch_optionals();
			$files = array_merge($files, $this->getEligibleFiles($product));
		}
		return $files;
	}

	/**
	 * Stored include/exclude choices of a product
	 *
	 * @param  int $fk_product Product id
	 * @return array<string,int> filename => selected
	 */
	public function getChoices($fk_product)
	{
		global $conf;

		$choices = array();
		$sql = "SELECT filename, selected FROM ".MAIN_DB_PREFIX."rfqimages_file";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$choices[$obj->filename] = (int) $obj->selected;
			}
			$this->db->free($resql);
		}
		return $choices;
	}

	/**
	 * Save include/exclude choice for a set of files
	 *
	 * @param  int              $fk_product Product id
	 * @param  array<string,int> $choices   filename => 0|1
	 * @return int                          1 OK, -1 error
	 */
	public function saveChoices($fk_product, $choices)
	{
		global $conf;

		$this->db->begin();
		foreach ($choices as $filename => $selected) {
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."rfqimages_file";
			$sql .= " WHERE fk_product = ".((int) $fk_product);
			$sql .= " AND entity = ".((int) $conf->entity);
			$sql .= " AND filename = '".$this->db->escape($filename)."'";
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."rfqimages_file (entity, fk_product, filename, selected)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fk_product).", '".$this->db->escape($filename)."', ".($selected ? 1 : 0).")";
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Copy a file into the user's mail temp folder so core's "remove all" never touches the original.
	 * The copy is prefixed with the product ref, which also keeps same-named files from different products apart.
	 *
	 * @param  array{fullpath:string,name:string,productref:string} $file File entry
	 * @param  User                                                 $user Current user
	 * @return array{path:string,name:string}|null                        Null on failure
	 */
	public function copyToMailTemp($file, $user)
	{
		global $conf;

		$tmpdir = $conf->user->dir_output.'/'.$user->id.'/temp';
		if (dol_mkdir($tmpdir) < 0) {
			$this->errors[] = 'Cannot create '.$tmpdir;
			return null;
		}
		$name = dol_sanitizeFileName($file['productref'].'_'.$file['name']);
		$dest = $tmpdir.'/'.$name;
		if (dol_copy($file['fullpath'], $dest, '0', 1) < 0) {
			$this->errors[] = 'Cannot copy '.$file['name'];
			return null;
		}
		return array('path' => $dest, 'name' => $name);
	}

	/**
	 * ECM index record of a file, created if the file was never indexed
	 *
	 * @param  string   $fullpath Absolute file path
	 * @param  bool     $create   Index the file when it has no record
	 * @return EcmFiles|null
	 */
	public function fetchEcmFile($fullpath, $create = false)
	{
		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';

		$relative = preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'[\\/]*/', '', $fullpath);
		$ecm = new EcmFiles($this->db);
		$res = $ecm->fetch(0, '', $relative);
		if ($res > 0) {
			return $ecm;
		}
		if (!$create) {
			return null;
		}
		if (addFileIntoDatabaseIndex(dirname($fullpath), basename($fullpath), '', 'uploaded', 0) <= 0) {
			return null;
		}
		$ecm = new EcmFiles($this->db);
		return ($ecm->fetch(0, '', $relative) > 0) ? $ecm : null;
	}

	/**
	 * Public share URL of a file, creating the share hash when missing
	 *
	 * @param  string      $fullpath Absolute file path
	 * @param  User        $user     Current user
	 * @return string|null
	 */
	public function getShareUrl($fullpath, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$ecm = $this->fetchEcmFile($fullpath, true);
		if (!$ecm) {
			$this->errors[] = 'Cannot index '.basename($fullpath);
			return null;
		}
		if (empty($ecm->share)) {
			$ecm->share = getRandomPassword(true);
			if ($ecm->update($user) < 0) {
				$this->errors[] = $ecm->error;
				return null;
			}
		}
		return self::publicRoot().'/document.php?hashp='.urlencode($ecm->share);
	}

	/**
	 * Remove the share hash of a file
	 *
	 * @param  string $fullpath Absolute file path
	 * @param  User   $user     Current user
	 * @return int              1 OK, 0 nothing to do, -1 error
	 */
	public function revokeShare($fullpath, $user)
	{
		$ecm = $this->fetchEcmFile($fullpath);
		if (!$ecm || empty($ecm->share)) {
			return 0;
		}
		$ecm->share = '';
		return ($ecm->update($user) < 0) ? -1 : 1;
	}

	/**
	 * Absolute URL root used in public links
	 *
	 * @return string
	 */
	public static function publicRoot()
	{
		global $dolibarr_main_url_root;

		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		return $urlwithouturlroot.DOL_URL_ROOT;
	}

	/**
	 * Links block for the email body, grouped by product ref
	 *
	 * @param  array<int,array{name:string,productref:string,url:string}> $links Links
	 * @param  bool                                                        $html  HTML or plain text
	 * @return string
	 */
	public static function buildLinksBlock($links, $html)
	{
		global $langs;

		if (empty($links)) {
			return '';
		}
		$langs->load('rfqimages@rfqimages');

		$byref = array();
		foreach ($links as $l) {
			$byref[$l['productref']][] = $l;
		}

		if ($html) {
			$out = '<p><strong>'.dol_escape_htmltag($langs->transnoentities('RfqImagesLinksTitle')).'</strong></p><ul>';
			foreach ($byref as $ref => $items) {
				$out .= '<li>'.dol_escape_htmltag($ref).'<ul>';
				foreach ($items as $l) {
					$out .= '<li><a href="'.dol_escape_htmltag($l['url']).'">'.dol_escape_htmltag($l['name']).'</a></li>';
				}
				$out .= '</ul></li>';
			}
			return $out.'</ul>';
		}

		$out = $langs->transnoentities('RfqImagesLinksTitle')."\n";
		foreach ($byref as $ref => $items) {
			$out .= $ref."\n";
			foreach ($items as $l) {
				$out .= '  - '.$l['name'].': '.$l['url']."\n";
			}
		}
		return $out;
	}
}

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
	/** Email template key replaced with share links when the email is sent */
	const LINKS_KEY = '__RFQIMAGES_LINKS__';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string Scheduled job output */
	public $output = '';

	/** @var array<int,int>|null Flagged category ids, computed once per instance */
	private $flaggedCategoryIds = null;

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
		return self::cleanExtensions(explode(',', getDolGlobalString('RFQIMAGES_EXTENSIONS', 'jpg,jpeg,png,gif,webp')));
	}

	/**
	 * Extensions offered in setup, grouped for display
	 *
	 * @return array<string,string[]> group label key => extensions
	 */
	public static function knownExtensions()
	{
		return array(
			'RfqImagesExtGroupImages' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'svg', 'heic'),
			'RfqImagesExtGroupDocuments' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv'),
			'RfqImagesExtGroupCad' => array('dwg', 'dxf', 'step', 'stp', 'iges', 'igs', 'stl', '3mf', 'obj'),
			'RfqImagesExtGroupArchives' => array('zip', '7z'),
		);
	}

	/**
	 * Normalize a list of extensions: lowercase, no dot, alphanumeric, unique
	 *
	 * @param  string[] $list Raw extensions
	 * @return string[]
	 */
	public static function cleanExtensions($list)
	{
		$exts = array();
		foreach ($list as $ext) {
			$ext = strtolower(trim(ltrim(trim((string) $ext), '.')));
			if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
				$exts[] = $ext;
			}
		}
		return array_values(array_unique($exts));
	}

	/**
	 * Product category ids selected in setup, expanded with all their subcategories
	 *
	 * @return array<int,int> category id => selected ancestor id
	 */
	public function getFlaggedCategoryIds()
	{
		if ($this->flaggedCategoryIds === null) {
			$this->flaggedCategoryIds = $this->loadFlaggedCategoryIds();
		}
		return $this->flaggedCategoryIds;
	}

	/**
	 * Expand the categories selected in setup with all their subcategories
	 *
	 * @return array<int,int> category id => selected ancestor id
	 */
	private function loadFlaggedCategoryIds()
	{
		$ids = array();
		$selected = array_filter(array_map('intval', explode(',', getDolGlobalString('RFQIMAGES_CATEGORIES'))));
		if (empty($selected) || !isModEnabled('category')) {
			return $ids;
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$cat = new Categorie($this->db);
		$arbo = $cat->get_full_arbo(Categorie::TYPE_PRODUCT);
		if (!is_array($arbo)) {
			return $ids;
		}
		foreach ($arbo as $c) {
			// fullpath is like _3_12_40: the category and all its ancestors
			foreach (array_filter(array_map('intval', explode('_', (string) $c['fullpath']))) as $ancestor) {
				if (in_array($ancestor, $selected, true)) {
					$ids[(int) $c['id']] = $ancestor;
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * Why a product's files are sent: 'product' (its own switch), a category label, or '' (not flagged)
	 *
	 * @param  Product $product Product (optionals fetched)
	 * @return string
	 */
	public function getFlagSource($product)
	{
		if (!empty($product->array_options['options_rfqimages_send'])) {
			return 'product';
		}
		$flaggedcats = $this->getFlaggedCategoryIds();
		if (empty($flaggedcats)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$cat = new Categorie($this->db);
		$ids = $cat->containing($product->id, Categorie::TYPE_PRODUCT, 'id');
		foreach ((array) $ids as $id) {
			if (isset($flaggedcats[(int) $id])) {
				$selected = new Categorie($this->db);
				return ($selected->fetch($flaggedcats[(int) $id]) > 0) ? $selected->label : '#'.$flaggedcats[(int) $id];
			}
		}
		return '';
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
		// Files never ticked or unticked on the product Documents tab follow the setup default
		$default = (getDolGlobalString('RFQIMAGES_NEW_FILES_INCLUDED', '1') === '0') ? 0 : 1;

		$out = array();
		// Top level only: skips thumbs/ and other subfolders
		foreach (dol_dir_list($dir, 'files', 0, $filter, null, 'name', SORT_ASC, 1) as $f) {
			$out[] = array(
				'fullpath' => $f['fullname'],
				'name' => $f['name'],
				'size' => (int) $f['size'],
				'mime' => dol_mimetype($f['name']),
				'selected' => isset($choices[$f['name']]) ? (int) $choices[$f['name']] : $default,
				'productref' => $product->ref,
				'fk_product' => (int) $product->id,
			);
		}
		return $out;
	}

	/**
	 * Files to send for a product: none unless the product is flagged (itself or by category), then only selected ones
	 *
	 * @param  Product $product Product (optionals fetched)
	 * @return array<int,array{fullpath:string,name:string,size:int,mime:string,selected:int,productref:string,fk_product:int}>
	 */
	public function getEligibleFiles($product)
	{
		if ($this->getFlagSource($product) === '') {
			return array();
		}
		return array_values(array_filter($this->listCandidateFiles($product), function ($f) {
			return !empty($f['selected']);
		}));
	}

	/**
	 * Eligible files of every distinct product on a price request or purchase order
	 *
	 * @param  CommonObject $document SupplierProposal or CommandeFournisseur with lines loaded
	 * @return array<int,array{fullpath:string,name:string,size:int,mime:string,selected:int,productref:string,fk_product:int}>
	 */
	public function collectForDocument($document)
	{
		$seen = array();
		$files = array();
		foreach ((array) $document->lines as $line) {
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
	 * Files in the product folder that are not sent because their extension is not in the setup list
	 *
	 * @param  Product  $product Product
	 * @return string[]          File names
	 */
	public function listOtherFiles($product)
	{
		$dir = self::getProductDir($product);
		if (!dol_is_dir($dir)) {
			return array();
		}
		$exts = self::getExtensions();
		$out = array();
		foreach (dol_dir_list($dir, 'files', 0, '', '(\.meta|_preview.*\.png)$') as $f) {
			if (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), $exts, true)) {
				$out[] = $f['name'];
			}
		}
		return $out;
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
	 * @return array{path:string,name:string,size:int}|null               Null on failure
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
		if (!$this->writeResizedCopy($file['fullpath'], $dest) && dol_copy($file['fullpath'], $dest, '0', 1) < 0) {
			$this->errors[] = 'Cannot copy '.$file['name'];
			return null;
		}
		clearstatcache(true, $dest);
		return array('path' => $dest, 'name' => $name, 'size' => (int) dol_filesize($dest));
	}

	/**
	 * Write a smaller copy of an image when setup limits the size and the image is larger.
	 * Only jpg/png/gif, and only when this PHP can read and write them; otherwise the caller copies the original.
	 *
	 * @param  string $src  Original file
	 * @param  string $dest Copy to write
	 * @return bool         True if a resized copy was written
	 */
	public function writeResizedCopy($src, $dest)
	{
		$max = (int) getDolGlobalString('RFQIMAGES_RESIZE_MAX_PX', '0');
		if ($max <= 0) {
			return false;
		}
		$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
		$gd = array('jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif');
		if (!isset($gd[$ext]) || !function_exists($gd[$ext])) {
			return false;
		}
		$dim = @getimagesize($src);
		if (!$dim || ($dim[0] <= $max && $dim[1] <= $max)) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		// Fit the longest side to $max, keeping the ratio
		$newwidth = ($dim[0] >= $dim[1]) ? $max : 0;
		$newheight = ($dim[0] >= $dim[1]) ? 0 : $max;
		$result = dol_imageResizeOrCrop($src, 0, $newwidth, $newheight, 0, 0, $dest, 85);
		return ($result === $dest && dol_is_file($dest));
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
			$this->trackShare($ecm);
		} else {
			// Only refreshes links this module created; a link shared by hand is never tracked or expired
			$this->touchShare($ecm);
		}
		return self::publicRoot().'/document.php?hashp='.urlencode($ecm->share);
	}

	/**
	 * Record a share hash created by this module, so it can expire
	 *
	 * @param  EcmFiles $ecm File index record
	 * @return void
	 */
	private function trackShare($ecm)
	{
		global $conf;

		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rfqimages_share WHERE fk_ecm_files = ".((int) $ecm->id));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."rfqimages_share (entity, fk_ecm_files, share, date_last_sent)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $ecm->id).", '".$this->db->escape($ecm->share)."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
		}
	}

	/**
	 * Push back the expiry of a module-created share that is sent again
	 *
	 * @param  EcmFiles $ecm File index record
	 * @return void
	 */
	private function touchShare($ecm)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."rfqimages_share SET date_last_sent = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE fk_ecm_files = ".((int) $ecm->id)." AND share = '".$this->db->escape($ecm->share)."'";
		$this->db->query($sql);
	}

	/**
	 * Scheduled job: remove share links created by this module that were last sent more than
	 * RFQIMAGES_SHARE_EXPIRE_DAYS days ago. Links shared by hand, or replaced since, are left alone.
	 *
	 * @return int 0 if OK, <0 if KO (cron convention)
	 */
	public function expireShares()
	{
		global $conf, $user;

		$this->output = '';
		$days = (int) getDolGlobalString('RFQIMAGES_SHARE_EXPIRE_DAYS', '0');
		if ($days <= 0) {
			$this->output = 'Link expiry is off (RFQIMAGES_SHARE_EXPIRE_DAYS = 0)';
			return 0;
		}

		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$limit = dol_now() - $days * 86400;
		$sql = "SELECT rowid, fk_ecm_files, share FROM ".MAIN_DB_PREFIX."rfqimages_share";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND date_last_sent < '".$this->db->idate($limit)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$revoked = 0;
		foreach ($rows as $row) {
			$ecm = new EcmFiles($this->db);
			if ($ecm->fetch((int) $row->fk_ecm_files) > 0 && $ecm->share === $row->share) {
				$ecm->share = '';
				if ($ecm->update($user) < 0) {
					$this->errors[] = $ecm->error;
					continue;
				}
				$revoked++;
			}
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rfqimages_share WHERE rowid = ".((int) $row->rowid));
		}

		$this->output = $revoked.' share link(s) removed (older than '.$days.' days)';
		return $this->errors ? -1 : 0;
	}

	/**
	 * Share links for a set of files, creating share hashes where missing
	 *
	 * @param  array<int,array{fullpath:string,name:string,productref:string}> $files Files
	 * @param  User                                                          $user  Current user
	 * @return array<int,array{name:string,productref:string,url:string}>
	 */
	public function buildShareLinks($files, $user)
	{
		$links = array();
		foreach ($files as $f) {
			$url = $this->getShareUrl($f['fullpath'], $user);
			if ($url) {
				$links[] = array('name' => $f['name'], 'productref' => $f['productref'], 'url' => $url);
			}
		}
		return $links;
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
		if ($ecm->update($user) < 0) {
			return -1;
		}
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rfqimages_share WHERE fk_ecm_files = ".((int) $ecm->id));
		return 1;
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
	 * Heading shown above the links: the text from setup, the translated default when blank,
	 * or nothing when the heading is switched off
	 *
	 * @return string
	 */
	public static function getLinksTitle()
	{
		global $langs;

		if (getDolGlobalString('RFQIMAGES_LINKS_SHOW_TITLE', '1') === '0') {
			return '';
		}
		$custom = trim(getDolGlobalString('RFQIMAGES_LINKS_TITLE'));
		if ($custom !== '') {
			return $custom;
		}
		$langs->load('rfqimages@rfqimages');
		return $langs->transnoentities('RfqImagesLinksTitle');
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

		$title = self::getLinksTitle();

		if ($html) {
			$out = ($title !== '' ? '<p><strong>'.dol_escape_htmltag($title).'</strong></p>' : '').'<ul>';
			foreach ($byref as $ref => $items) {
				$out .= '<li>'.dol_escape_htmltag($ref).'<ul>';
				foreach ($items as $l) {
					$out .= '<li><a href="'.dol_escape_htmltag($l['url']).'">'.dol_escape_htmltag($l['name']).'</a></li>';
				}
				$out .= '</ul></li>';
			}
			return $out.'</ul>';
		}

		$out = ($title !== '' ? $title."\n" : '');
		foreach ($byref as $ref => $items) {
			$out .= $ref."\n";
			foreach ($items as $l) {
				$out .= '  - '.$l['name'].': '.$l['url']."\n";
			}
		}
		return $out;
	}
}

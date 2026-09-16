<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup rfqimages
 * \brief   rfqimages setup page
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/rfqimages/lib/rfqimages.lib.php');
dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'categories', 'rfqimages@rfqimages'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Upgrades without re-enabling: these settings default to on, create them so the toggles show it.
// Their toggles store 0 when switched off (instead of deleting), so this never switches them back on.
foreach (array('RFQIMAGES_ON_SUPPLIER_PROPOSAL', 'RFQIMAGES_ON_SUPPLIER_ORDER', 'RFQIMAGES_LINKS_SHOW_TITLE', 'RFQIMAGES_NEW_FILES_INCLUDED') as $constname) {
	if (!isset($conf->global->$constname)) {
		dolibarr_set_const($db, $constname, '1', 'chaine', 0, '', $conf->entity);
	}
}


/*
 * Actions
 */

$redirect = false;

if ($action == 'update_extensions') {
	$picked = GETPOST('RFQIMAGES_EXTENSIONS_PICK', 'array');
	$other = preg_split('/[\s,;]+/', GETPOST('RFQIMAGES_EXTENSIONS_OTHER', 'alphanohtml'));
	$exts = RfqImagesService::cleanExtensions(array_merge(is_array($picked) ? $picked : array(), $other));
	if (empty($exts)) {
		setEventMessages($langs->trans('RfqImagesExtensionsEmpty'), null, 'errors');
	} else {
		dolibarr_set_const($db, 'RFQIMAGES_EXTENSIONS', implode(',', $exts), 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	$redirect = true;
}

if ($action == 'update_categories') {
	$ids = array_filter(array_map('intval', (array) GETPOST('RFQIMAGES_CATEGORIES', 'array')));
	dolibarr_set_const($db, 'RFQIMAGES_CATEGORIES', implode(',', $ids), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	$redirect = true;
}

if ($action == 'update_linkstitle') {
	// Blank deletes the setting, which means the translated default heading
	dolibarr_set_const($db, 'RFQIMAGES_LINKS_TITLE', trim(GETPOST('RFQIMAGES_LINKS_TITLE', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	$redirect = true;
}

if ($action == 'update_maxsize') {
	$mb = price2num(GETPOST('RFQIMAGES_MAX_ATTACH_MB', 'alphanohtml'));
	dolibarr_set_const($db, 'RFQIMAGES_MAX_ATTACH_MB', (string) max(0, (float) $mb), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	$redirect = true;
}

if ($action == 'update_resize') {
	dolibarr_set_const($db, 'RFQIMAGES_RESIZE_MAX_PX', (string) max(0, GETPOSTINT('RFQIMAGES_RESIZE_MAX_PX')), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	$redirect = true;
}

if ($action == 'update_expire') {
	dolibarr_set_const($db, 'RFQIMAGES_SHARE_EXPIRE_DAYS', (string) max(0, GETPOSTINT('RFQIMAGES_SHARE_EXPIRE_DAYS')), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	$redirect = true;
}

if ($action == 'repair_extrafield') {
	if (rfqimages_ensure_extrafields($db) > 0) {
		setEventMessages($langs->trans('RfqImagesExtrafieldOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	$redirect = true;
}

if ($redirect) {
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$form = new Form($db);

/**
 * Start a one-setting form inside a setup row
 *
 * @param  string $action Action name
 * @return string
 */
function rfqimages_rowform($action)
{
	return '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">'
		.'<input type="hidden" name="token" value="'.newToken().'">'
		.'<input type="hidden" name="action" value="'.$action.'">';
}

/**
 * Section title row
 *
 * @param  string $label Translated label
 * @return string
 */
function rfqimages_section($label)
{
	return '<tr class="liste_titre"><td colspan="3">'.$label.'</td></tr>';
}

$savebutton = ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';

llxHeader('', $langs->trans('RfqImagesSetup'), '', '', 0, 0, '', '', '', 'mod-rfqimages page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('RfqImagesSetup'), $linkback, 'title_setup');

$head = rfqimages_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('Module510410Name'), -1, 'image');

print '<span class="opacitymedium">'.$langs->trans('RfqImagesSetupIntro').'</span><br><br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// --- Documents
print rfqimages_section($langs->trans('RfqImagesSectionDocuments'));
print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans('RfqImagesOnSupplierProposal').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_ON_SUPPLIER_PROPOSAL', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesOnSupplierProposalDesc').(isModEnabled('supplier_proposal') ? '' : ' <span class="warning">'.$langs->trans('RfqImagesModuleOff').'</span>').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesOnSupplierOrder').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_ON_SUPPLIER_ORDER', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesOnSupplierOrderDesc').((isModEnabled('fournisseur') || isModEnabled('supplier_order')) ? '' : ' <span class="warning">'.$langs->trans('RfqImagesModuleOff').'</span>').'</td></tr>';

// --- Which files
print rfqimages_section($langs->trans('RfqImagesSectionFiles'));

// Extensions: grouped multiselect of common types, plus free entry for anything else
$current = RfqImagesService::getExtensions();
$options = array();
$known = array();
foreach (RfqImagesService::knownExtensions() as $grouplabel => $exts) {
	foreach ($exts as $ext) {
		$options[$ext] = $ext.' ('.$langs->trans($grouplabel).')';
		$known[] = $ext;
	}
}
$others = array_diff($current, $known);
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesExtensions').'</td><td>';
print rfqimages_rowform('update_extensions');
print $form->multiselectarray('RFQIMAGES_EXTENSIONS_PICK', $options, array_values(array_intersect($current, $known)), 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->trans('RfqImagesExtensionsPick'));
print '<br><input type="text" class="minwidth200 margintoponly" name="RFQIMAGES_EXTENSIONS_OTHER" value="'.dol_escape_htmltag(implode(', ', $others)).'" placeholder="'.dol_escape_htmltag($langs->trans('RfqImagesExtensionsOtherPlaceholder')).'">';
print $savebutton;
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesExtensionsDesc').'</td></tr>';

// Categories
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesCategories').'</td><td>';
if (isModEnabled('category')) {
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$cate_arbo = $form->select_all_categories(Categorie::TYPE_PRODUCT, '', 'parent', 64, 0, 1);
	$selectedcats = array_filter(array_map('intval', explode(',', getDolGlobalString('RFQIMAGES_CATEGORIES'))));
	print rfqimages_rowform('update_categories');
	print $form->multiselectarray('RFQIMAGES_CATEGORIES', is_array($cate_arbo) ? $cate_arbo : array(), $selectedcats, 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->trans('RfqImagesCategoriesPick'));
	print $savebutton;
	print '</form>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('RfqImagesCategoriesModuleOff').'</span>';
}
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesCategoriesDesc').'</td></tr>';

// New files default
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesNewFilesIncluded').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_NEW_FILES_INCLUDED', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesNewFilesIncludedDesc').'</td></tr>';

// --- Attachments
print rfqimages_section($langs->trans('RfqImagesSectionAttachments'));
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesMaxAttach').'</td><td>';
print rfqimages_rowform('update_maxsize');
print '<input type="number" step="0.01" min="0" name="RFQIMAGES_MAX_ATTACH_MB" value="'.dol_escape_htmltag(getDolGlobalString('RFQIMAGES_MAX_ATTACH_MB', '10')).'" style="width:80px;"> MB';
print $savebutton;
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesMaxAttachDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesResize').'</td><td>';
print rfqimages_rowform('update_resize');
print '<input type="number" step="1" min="0" name="RFQIMAGES_RESIZE_MAX_PX" value="'.((int) getDolGlobalString('RFQIMAGES_RESIZE_MAX_PX', '0')).'" style="width:80px;"> px';
print $savebutton;
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesResizeDesc').'</td></tr>';

// --- Links
print rfqimages_section($langs->trans('RfqImagesSectionLinks'));
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesLinksShowTitle').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_LINKS_SHOW_TITLE', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesLinksShowTitleDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesLinksTitleSetting').'</td><td>';
print rfqimages_rowform('update_linkstitle');
print '<input type="text" class="minwidth300" maxlength="255" name="RFQIMAGES_LINKS_TITLE" value="'.dol_escape_htmltag(getDolGlobalString('RFQIMAGES_LINKS_TITLE')).'" placeholder="'.dol_escape_htmltag($langs->transnoentities('RfqImagesLinksTitle')).'">';
print $savebutton;
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesLinksTitleSettingDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesExpire').'</td><td>';
print rfqimages_rowform('update_expire');
print '<input type="number" step="1" min="0" name="RFQIMAGES_SHARE_EXPIRE_DAYS" value="'.((int) getDolGlobalString('RFQIMAGES_SHARE_EXPIRE_DAYS', '0')).'" style="width:80px;"> '.$langs->trans('Days');
print $savebutton;
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesExpireDesc');
if (getDolGlobalInt('RFQIMAGES_SHARE_EXPIRE_DAYS') > 0 && !isModEnabled('cron')) {
	print ' <span class="warning">'.img_warning().' '.$langs->trans('RfqImagesExpireCronOff').'</span>';
}
print '</td></tr>';

// --- Advanced
print rfqimages_section($langs->trans('RfqImagesSectionAdvanced'));
print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';
print '</div>';

print '<br>';
print '<div class="info">'.$langs->trans('RfqImagesTemplateHelp').'</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=repair_extrafield&token='.newToken().'">'.$langs->trans('RfqImagesRepairExtrafield').'</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();

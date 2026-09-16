<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    product_images.php
 * \ingroup rfqimages
 * \brief   Product tab: flag the product and pick which files go out with price requests
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('products', 'other', 'rfqimages@rfqimages'));

$id     = GETPOSTINT('id');
$ref    = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

$object = new Product($db);
if ($id > 0 || !empty($ref)) {
	if ($object->fetch($id, $ref) <= 0) {
		accessforbidden();
	}
	$object->fetch_optionals();
}
if (empty($object->id)) {
	accessforbidden();
}

$fieldvalue = $object->id;
$fieldtype = 'rowid';
if ($object->type == Product::TYPE_SERVICE) {
	restrictedArea($user, 'service', $fieldvalue, 'product&product', '', '', $fieldtype);
	$permwrite = $user->hasRight('service', 'creer');
} else {
	restrictedArea($user, 'produit', $fieldvalue, 'product&product', '', '', $fieldtype);
	$permwrite = $user->hasRight('produit', 'creer');
}

$service = new RfqImagesService($db);
$candidates = $service->listCandidateFiles($object);
$byname = array();
foreach ($candidates as $f) {
	$byname[$f['name']] = $f;
}
$self = $_SERVER['PHP_SELF'].'?id='.$object->id;


/*
 * Actions
 */

if ($action == 'setflag' && $permwrite) {
	$object->array_options['options_rfqimages_send'] = GETPOSTINT('value') ? 1 : 0;
	if ($object->updateExtraField('rfqimages_send', null, $user) < 0) {
		setEventMessages($object->error, $object->errors, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
	}
	header('Location: '.$self);
	exit;
}

if ($action == 'savechoices' && $permwrite) {
	$picked = GETPOST('include', 'array');
	$choices = array();
	foreach ($byname as $name => $f) {
		$choices[$name] = in_array($name, $picked, true) ? 1 : 0;
	}
	if ($service->saveChoices($object->id, $choices) < 0) {
		setEventMessages($langs->trans('Error'), $service->errors, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
	}
	header('Location: '.$self);
	exit;
}

if ($action == 'revokeshare' && $permwrite) {
	$name = GETPOST('file', 'alphanohtml');
	if (isset($byname[$name])) {
		$r = $service->revokeShare($byname[$name]['fullpath'], $user);
		if ($r < 0) {
			setEventMessages($langs->trans('Error'), $service->errors, 'errors');
		} elseif ($r > 0) {
			setEventMessages($langs->trans('RfqImagesShareRevoked', $name), null, 'mesgs');
		}
	}
	header('Location: '.$self);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $object->ref.' - '.$langs->trans('RfqImagesTab');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-rfqimages page-product_images');

$head = product_prepare_head($object);
$titre = $langs->trans('CardProduct'.$object->type);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');
print dol_get_fiche_head($head, 'rfqimages', $titre, -1, $picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1&type='.$object->type.'">'.$langs->trans('BackToList').'</a>';
$object->next_prev_filter = "(te.fk_product_type:=:".((int) $object->type).")";
dol_banner_tab($object, 'ref', $linkback, 1, 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$flagged = !empty($object->array_options['options_rfqimages_send']);

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$form->textwithpicto($langs->trans('RfqImagesSend'), $langs->trans('RfqImagesSendHelp')).'</td><td>';
if ($permwrite) {
	$url = $self.'&action=setflag&token='.newToken().'&value='.($flagged ? 0 : 1);
	print '<a class="reposition" href="'.$url.'">'.img_picto($langs->trans($flagged ? 'Activated' : 'Disabled'), $flagged ? 'switch_on' : 'switch_off').'</a>';
} else {
	print yn($flagged);
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('RfqImagesExtensions').'</td><td>'.dol_escape_htmltag(implode(', ', RfqImagesService::getExtensions()));
$others = $service->listOtherFiles($object);
if ($others) {
	print '<br><span class="warning">'.img_warning().' '.$langs->trans('RfqImagesOtherFiles', count($others), dol_escape_htmltag(dol_trunc(implode(', ', $others), 120))).'</span>';
}
print '</td></tr>';
print '</table>';

print '</div>';
print dol_get_fiche_end();

print '<br>';

if (!$flagged) {
	print '<div class="opacitymedium">'.$langs->trans('RfqImagesNotFlagged').'</div>';
}

print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savechoices">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent'.($flagged ? '' : ' opacitymedium').'">';
print '<tr class="liste_titre">';
print '<td class="center width50">'.$langs->trans('RfqImagesInclude').'</td>';
print '<td class="width100"></td>';
print '<td>'.$langs->trans('File').'</td>';
print '<td class="right">'.$langs->trans('Size').'</td>';
print '<td class="center">'.$langs->trans('RfqImagesShareStatus').'</td>';
print '</tr>';

if (empty($candidates)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('RfqImagesNoFiles').'</span></td></tr>';
}

$subdir = get_exdir(0, 0, 0, 0, $object, 'product');
$modulepart = ($object->type == Product::TYPE_SERVICE && isModEnabled('service')) ? 'service' : 'product';
foreach ($candidates as $f) {
	print '<tr class="oddeven">';
	print '<td class="center"><input type="checkbox" name="include[]" value="'.dol_escape_htmltag($f['name']).'"'.($f['selected'] ? ' checked' : '').($permwrite ? '' : ' disabled').'></td>';

	$thumbrel = $subdir.$f['name'];
	if (image_format_supported($f['name']) > 0) {
		$mini = getImageFileNameForSize($f['name'], '_mini');
		if (dol_is_file(dirname($f['fullpath']).'/thumbs/'.$mini)) {
			$thumbrel = $subdir.'thumbs/'.$mini;
		}
		print '<td><img class="maxwidth100 maxheight50" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$modulepart.'&entity='.((int) $object->entity).'&file='.urlencode($thumbrel).'"></td>';
	} else {
		print '<td>'.img_mime($f['name']).'</td>';
	}

	print '<td><a href="'.DOL_URL_ROOT.'/document.php?modulepart='.$modulepart.'&entity='.((int) $object->entity).'&file='.urlencode($subdir.$f['name']).'" target="_blank">'.dol_escape_htmltag($f['name']).'</a></td>';
	print '<td class="right nowraponall">'.dol_print_size($f['size'], 1, 1).'</td>';

	$ecm = $service->fetchEcmFile($f['fullpath']);
	print '<td class="center nowraponall">';
	if ($ecm && !empty($ecm->share)) {
		$shareurl = RfqImagesService::publicRoot().'/document.php?hashp='.urlencode($ecm->share);
		print '<a href="'.dol_escape_htmltag($shareurl).'" target="_blank">'.img_picto($langs->trans('RfqImagesShared'), 'globe').' '.$langs->trans('RfqImagesShared').'</a>';
		if ($permwrite) {
			print ' &nbsp; <a class="reposition" href="'.$self.'&action=revokeshare&token='.newToken().'&file='.urlencode($f['name']).'">'.img_picto($langs->trans('RfqImagesRevoke'), 'delete').'</a>';
		}
	} else {
		print '<span class="opacitymedium">'.$langs->trans('RfqImagesPrivate').'</span>';
	}
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

if ($permwrite && !empty($candidates)) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
}
print '</form>';

llxFooter();
$db->close();

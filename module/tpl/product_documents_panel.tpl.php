<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tpl/product_documents_panel.tpl.php
 * \ingroup rfqimages
 * \brief   "Vendor email images" section on the product Documents tab
 *
 * Expects: $object (Product, optionals fetched), $service (RfqImagesService), $permwrite (bool),
 *          $form (Form), $langs, $self (URL of the Documents tab for this product)
 */

if (empty($object) || !is_object($object) || empty($service)) {
	print 'Error: template called without context';
	exit(1);
}

$flagged = !empty($object->array_options['options_rfqimages_send']);
$flagsource = $service->getFlagSource($object);
$candidates = $service->listCandidateFiles($object);
$others = $service->listOtherFiles($object);
$subdir = get_exdir(0, 0, 0, 0, $object, 'product');
$modulepart = ($object->type == Product::TYPE_SERVICE && isModEnabled('service')) ? 'service' : 'product';

print '<!-- BEGIN rfqimages/tpl/product_documents_panel.tpl.php -->'."\n";
print '<div class="rfqimages-documents-panel">';
print '<br>';
print load_fiche_titre($langs->trans('RfqImagesSection'), '', 'image');

// Switch, category note, file types
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$form->textwithpicto($langs->trans('RfqImagesSend'), $langs->trans('RfqImagesSendHelp')).'</td><td>';
if ($permwrite) {
	$url = $self.'&action=rfqimages_setflag&token='.newToken().'&value='.($flagged ? 0 : 1);
	print '<a class="reposition" href="'.$url.'">'.img_picto($langs->trans($flagged ? 'Activated' : 'Disabled'), $flagged ? 'switch_on' : 'switch_off').'</a>';
} else {
	print yn($flagged);
}
if (!$flagged && $flagsource !== '') {
	print ' <span class="opacitymedium">'.$langs->trans('RfqImagesFlaggedByCategory', dol_escape_htmltag($flagsource)).'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('RfqImagesExtensions').'</td><td>'.dol_escape_htmltag(implode(', ', RfqImagesService::getExtensions()));
if ($others) {
	print '<br><span class="warning">'.img_warning().' '.$langs->trans('RfqImagesOtherFiles', count($others), dol_escape_htmltag(dol_trunc(implode(', ', $others), 120))).'</span>';
}
print '</td></tr>';
print '</table>';

if ($flagsource === '') {
	print '<div class="opacitymedium margintoponly">'.$langs->trans('RfqImagesNotFlagged').'</div>';
}

// Files and include choices
print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="rfqimages_savechoices">';

print '<div class="div-table-responsive-no-min margintoponly">';
print '<table class="noborder centpercent'.($flagsource !== '' ? '' : ' opacitymedium').'">';
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

foreach ($candidates as $f) {
	print '<tr class="oddeven">';
	print '<td class="center"><input type="checkbox" name="rfqimages_include[]" value="'.dol_escape_htmltag($f['name']).'"'.($f['selected'] ? ' checked' : '').($permwrite ? '' : ' disabled').'></td>';

	if (image_format_supported($f['name']) > 0) {
		$thumbrel = $subdir.$f['name'];
		$mini = getImageFileNameForSize($f['name'], '_mini');
		if (dol_is_file(dirname($f['fullpath']).'/thumbs/'.$mini)) {
			$thumbrel = $subdir.'thumbs/'.$mini;
		}
		print '<td><img class="maxwidth100 maxheight50" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$modulepart.'&entity='.((int) $object->entity).'&file='.urlencode($thumbrel).'"></td>';
	} else {
		print '<td>'.img_mime($f['name']).'</td>';
	}

	print '<td>'.dol_escape_htmltag($f['name']).'</td>';
	print '<td class="right nowraponall">'.dol_print_size($f['size'], 1, 1).'</td>';

	$ecm = $service->fetchEcmFile($f['fullpath']);
	print '<td class="center nowraponall">';
	if ($ecm && !empty($ecm->share)) {
		$shareurl = RfqImagesService::publicRoot().'/document.php?hashp='.urlencode($ecm->share);
		print '<a href="'.dol_escape_htmltag($shareurl).'" target="_blank">'.img_picto($langs->trans('RfqImagesShared'), 'globe').' '.$langs->trans('RfqImagesShared').'</a>';
		if ($permwrite) {
			print ' &nbsp; <a class="reposition" href="'.$self.'&action=rfqimages_revokeshare&token='.newToken().'&rfqimages_file='.urlencode($f['name']).'">'.img_picto($langs->trans('RfqImagesRevoke'), 'delete').'</a>';
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
print '</div>';
print '<!-- END rfqimages/tpl/product_documents_panel.tpl.php -->'."\n";

<?php
/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/enablebanking/admin/rules.php
 * \ingroup enablebanking
 * \brief   Rules proposing a match for imported transactions
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
$j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
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
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/bankcateg.class.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');
dol_include_once('/enablebanking/class/ebrule.class.php');
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "banks", "bills", "companies", "compta", "enablebanking@enablebanking"));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('id');

if (!$user->admin) {
	accessforbidden();
}

$hookmanager->initHooks(array('enablebankingrules', 'globalsetup'));

$object = new EbRule($db);
if ($id > 0 && $object->fetch($id) <= 0) {
	accessforbidden('Rule not found');
}


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (GETPOST('cancel', 'alpha')) {
	$action = '';
}

$saved = false;
if (empty($reshook)) {
	if ($action == 'add' || ($action == 'update' && $object->id > 0)) {
		$object->label = GETPOST('label', 'alphanohtml');
		$object->active = GETPOSTINT('active') ? 1 : 0;
		$object->fk_bank_account = GETPOSTINT('fk_bank_account') > 0 ? GETPOSTINT('fk_bank_account') : null;
		$object->direction = in_array(GETPOST('direction', 'aZ09'), array('in', 'out')) ? GETPOST('direction', 'aZ09') : '';
		$object->counterparty = GETPOST('counterparty', 'alphanohtml');
		$object->iban = GETPOST('iban', 'alphanohtml');
		$object->remittance = GETPOST('remittance', 'alphanohtml');
		$object->amount_min = GETPOST('amount_min', 'alpha') !== '' ? price2num(GETPOST('amount_min', 'alpha')) : '';
		$object->amount_max = GETPOST('amount_max', 'alpha') !== '' ? price2num(GETPOST('amount_max', 'alpha')) : '';
		$object->action = GETPOST('ruleaction', 'aZ09');
		$object->fk_soc = GETPOSTINT('socid') > 0 ? GETPOSTINT('socid') : null;
		$object->fk_bank_categ = GETPOSTINT('fk_bank_categ') > 0 ? GETPOSTINT('fk_bank_categ') : null;
		$object->payment_code = GETPOST('payment_code', 'aZ09');
		$object->tax_type = GETPOST('tax_type', 'aZ09');
		$object->line_label = GETPOST('line_label', 'alphanohtml');
		$object->auto_validate = GETPOSTINT('auto_validate') ? 1 : 0;

		$result = ($action == 'add') ? $object->create($user) : $object->update($user);
		if ($result > 0) {
			$saved = true;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = ($action == 'add') ? 'create' : 'edit';
		}
	}

	if ($action == 'setactive' && $object->id > 0) {
		$object->active = GETPOSTINT('value') ? 1 : 0;
		if ($object->update($user) > 0) {
			$saved = true;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}

	if ($action == 'confirm_delete' && $confirm == 'yes' && $object->id > 0) {
		if ($object->delete($user) > 0) {
			$saved = true;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
}

if ($saved) {
	// Suggestions of the transactions to process follow the rules
	$txstatic = new EbTransaction($db);
	$matcher = new EnableBankingMatcher($db);
	$ids = $txstatic->fetchIdsToProcess();
	if (!empty($ids) && ($result = $matcher->suggest($ids, $user)) < 0) {
		setEventMessages($matcher->error, $matcher->errors, 'warnings');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = "EnableBankingSetup";
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-enablebanking page-admin_rules');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = enablebankingAdminPrepareHead();
print dol_get_fiche_head($head, 'rules', $langs->trans($title), -1, 'bank_account');

if ($action == 'delete' && $object->id > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('Delete'), $langs->trans('EnableBankingConfirmDeleteRule', dolPrintHTML($object->label)), 'confirm_delete', '', 0, 1);
}

print '<span class="opacitymedium">'.$langs->trans("EnableBankingRulesHelp").'</span><br><br>';

$taxoptions = enablebankingTaxTypeOptions($db);
$bankcateg = new BankCateg($db);
$categoptions = array();
foreach ($bankcateg->fetchAll() as $bankcategory) {
	$categoptions[$bankcategory->id] = $bankcategory->label;
}
$actionoptions = array();
foreach ($object->fields['action']['arrayofkeyval'] as $key => $labelkey) {
	$actionoptions[$key] = $langs->trans($labelkey);
}

// Creation or edition of a rule
if (in_array($action, array('create', 'edit'))) {
	$isnew = ($action == 'create');
	print load_fiche_titre($langs->trans($isnew ? 'EnableBankingNewRule' : 'EnableBankingEditRule'), '', '');
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($isnew ? 'add' : 'update').'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<table class="border centpercent tableforfieldcreate">';

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" class="minwidth300" name="label" value="'.dolPrintHTMLForAttribute((string) $object->label).'"></td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('EnableBankingRuleConditions').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('EnableBankingCounterparty'), $langs->trans('EnableBankingRuleCounterpartyHelp')).'</td><td><input type="text" class="minwidth300" name="counterparty" value="'.dolPrintHTMLForAttribute((string) $object->counterparty).'"></td></tr>';
	print '<tr><td>'.$langs->trans('IBAN').'</td><td><input type="text" class="minwidth300" name="iban" value="'.dolPrintHTMLForAttribute((string) $object->iban).'"></td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('EnableBankingRuleRemittance'), $langs->trans('EnableBankingRuleRemittanceHelp')).'</td><td><input type="text" class="minwidth300" name="remittance" value="'.dolPrintHTMLForAttribute((string) $object->remittance).'"></td></tr>';
	print '<tr><td>'.$langs->trans('EnableBankingDirection').'</td><td>'.$form->selectarray('direction', array('in' => $langs->trans('Credit'), 'out' => $langs->trans('Debit')), (string) $object->direction, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Amount').'</td><td>'.$langs->trans('EnableBankingRuleAmountMin').' <input type="text" class="width75" name="amount_min" value="'.((string) $object->amount_min !== '' ? price($object->amount_min) : '').'">';
	print ' '.$langs->trans('EnableBankingRuleAmountMax').' <input type="text" class="width75" name="amount_max" value="'.((string) $object->amount_max !== '' ? price($object->amount_max) : '').'"></td></tr>';
	print '<tr><td>'.$langs->trans('BankAccount').'</td><td>';
	$form->select_comptes((int) $object->fk_bank_account, 'fk_bank_account', 0, '', 1);
	print '</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('EnableBankingRuleAction').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('EnableBankingRuleAction').'</td><td>'.$form->selectarray('ruleaction', $actionoptions, (string) $object->action, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr class="ruleaction ruleaction_thirdparty ruleaction_bankline"><td>'.$langs->trans('ThirdParty').'</td><td>'.$form->select_company((int) $object->fk_soc, 'socid', '', 1, 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	print '<tr class="ruleaction ruleaction_tax"><td class="fieldrequired">'.$langs->trans('Type').'</td><td>'.$form->selectarray('tax_type', $taxoptions, (string) $object->tax_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr class="ruleaction ruleaction_bankline"><td>'.$langs->trans('Rubrique').'</td><td>'.$form->selectarray('fk_bank_categ', $categoptions, (int) $object->fk_bank_categ, 1, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr class="ruleaction ruleaction_bankline"><td>'.$form->textwithpicto($langs->trans('PaymentMode'), $langs->trans('EnableBankingRulePaymentModeHelp')).'</td><td>';
	$form->select_types_paiements((string) $object->payment_code, 'payment_code', '', 2, 1);
	print '</td></tr>';
	print '<tr class="ruleaction ruleaction_bankline ruleaction_tax"><td>'.$form->textwithpicto($langs->trans('EnableBankingRuleLineLabel'), $langs->trans('EnableBankingRuleLineLabelHelp')).'</td><td><input type="text" class="minwidth300" name="line_label" value="'.dolPrintHTMLForAttribute((string) $object->line_label).'"></td></tr>';
	print '<tr class="ruleaction ruleaction_bankline ruleaction_tax"><td>'.$form->textwithpicto($langs->trans('EnableBankingRuleAutoValidate'), $langs->trans('EnableBankingRuleAutoValidateHelp')).'</td><td><input type="checkbox" name="auto_validate" value="1"'.($object->auto_validate ? ' checked' : '').'></td></tr>';
	print '<tr><td>'.$langs->trans('Enabled').'</td><td><input type="checkbox" name="active" value="1"'.(($isnew && !GETPOSTISSET('label')) || $object->active ? ' checked' : '').'></td></tr>';

	print '</table>';
	print $form->buttonsSaveCancel($isnew ? 'Create' : 'Save', 'Cancel');
	print '</form>';
	print '<script src="'.dol_buildpath('/enablebanking/js/rules.js', 1).'"></script>';
	print '<br>';
}

// List of rules
$rules = $object->fetchAllRules(false);
if ($rules === false) {
	dol_print_error($db, $object->error);
	$rules = array();
}
// Names of third parties and bank accounts of the rules, in one query each
$socnames = array();
$accountnames = array();
$socids = array_filter(array_map(function ($rule) {
	return (int) $rule->fk_soc;
}, $rules));
if (!empty($socids)) {
	$resql = $db->query("SELECT rowid, nom FROM ".$db->prefix()."societe WHERE rowid IN (".$db->sanitize(implode(',', $socids)).")");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$socnames[(int) $obj->rowid] = $obj->nom;
	}
}
$resql = $db->query("SELECT rowid, ref FROM ".$db->prefix()."bank_account WHERE entity IN (".getEntity('bank_account').")");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$accountnames[(int) $obj->rowid] = $obj->ref;
}

$newbutton = dolGetButtonTitle($langs->trans('EnableBankingNewRule'), '', 'fa fa-plus-circle', $_SERVER["PHP_SELF"].'?action=create');
print load_fiche_titre($langs->trans('EnableBankingRules'), $newbutton, '');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Label').'</td>';
print '<td>'.$langs->trans('EnableBankingRuleConditions').'</td>';
print '<td>'.$langs->trans('EnableBankingRuleAction').'</td>';
print '<td class="center">'.$langs->trans('EnableBankingRuleAutoValidate').'</td>';
print '<td class="center">'.$langs->trans('Enabled').'</td>';
print '<td></td>';
print '</tr>';

foreach ($rules as $rule) {
	$conditions = array();
	if ($rule->fk_bank_account > 0) {
		$conditions[] = $langs->trans('BankAccount').': '.(isset($accountnames[(int) $rule->fk_bank_account]) ? $accountnames[(int) $rule->fk_bank_account] : $rule->fk_bank_account);
	}
	if ($rule->direction) {
		$conditions[] = $langs->trans($rule->direction == 'in' ? 'Credit' : 'Debit');
	}
	if ($rule->counterparty) {
		$conditions[] = $langs->trans('EnableBankingCounterparty').' ~ "'.$rule->counterparty.'"';
	}
	if ($rule->iban) {
		$conditions[] = $langs->trans('IBAN').' = '.$rule->iban;
	}
	if ($rule->remittance) {
		$conditions[] = $langs->trans('Label').' ~ "'.$rule->remittance.'"';
	}
	if ((string) $rule->amount_min !== '' || (string) $rule->amount_max !== '') {
		$conditions[] = $langs->trans('Amount').' '.((string) $rule->amount_min !== '' ? price($rule->amount_min) : '…').' - '.((string) $rule->amount_max !== '' ? price($rule->amount_max) : '…');
	}
	$actionlabel = isset($actionoptions[$rule->action]) ? $actionoptions[$rule->action] : $rule->action;
	if ($rule->fk_soc > 0) {
		$actionlabel .= ' - '.(isset($socnames[(int) $rule->fk_soc]) ? $socnames[(int) $rule->fk_soc] : $rule->fk_soc);
	}
	if ($rule->action == EbRule::ACTION_TAX) {
		$actionlabel .= ' - '.(isset($taxoptions[$rule->tax_type]) ? $taxoptions[$rule->tax_type] : $rule->tax_type);
	}

	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax200">'.$rule->getNomUrl(1).'</td>';
	print '<td class="small">'.dolPrintHTML(implode(', ', $conditions)).'</td>';
	print '<td class="tdoverflowmax300">'.dolPrintHTML($actionlabel).'</td>';
	print '<td class="center">'.yn($rule->auto_validate).'</td>';
	print '<td class="center"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=setactive&id='.$rule->id.'&value='.($rule->active ? 0 : 1).'&token='.newToken().'">'.img_picto('', $rule->active ? 'switch_on' : 'switch_off').'</a></td>';
	print '<td class="nowraponall right">';
	print '<a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$rule->id.'">'.img_edit().'</a> ';
	print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$rule->id.'&token='.newToken().'">'.img_delete().'</a>';
	print '</td>';
	print '</tr>';
}
if (empty($rules)) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();

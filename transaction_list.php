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
 * \file    htdocs/custom/enablebanking/transaction_list.php
 * \ingroup enablebanking
 * \brief   Bank transactions imported from Enable Banking: suggestions, validation and manual processing
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

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/bankcateg.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');
dol_include_once('/enablebanking/class/ebaccount.class.php');
dol_include_once('/enablebanking/class/ebtransaction.class.php');
dol_include_once('/enablebanking/class/enablebankingreconciler.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("banks", "bills", "companies", "enablebanking@enablebanking"));

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$toselect = GETPOST('toselect', 'array:int');
$txid = GETPOSTINT('txid');
$confirmone = GETPOSTINT('confirmone');

$search_fk_bank_account = GETPOSTINT('search_fk_bank_account');
$search_status = GETPOST('search_status', 'alpha');
if ($search_status === '') {
	$search_status = 'toprocess';
}
$search_direction = GETPOST('search_direction', 'aZ09');
$search_text = GETPOST('search_text', 'alphanohtml');
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$search_fk_bank_account = 0;
	$search_status = 'toprocess';
	$search_direction = '';
	$search_text = '';
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
if (!in_array($sortfield, array('t.booking_date', 't.amount', 't.counterparty_name', 't.status'))) {
	$sortfield = 't.booking_date';
}
if (!in_array(strtoupper($sortorder), array('ASC', 'DESC'))) {
	$sortorder = 'DESC';
}

if ($user->socid || !isModEnabled('enablebanking') || !$user->hasRight('enablebanking', 'read') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
$permissiontomatch = $user->hasRight('enablebanking', 'match') && $user->hasRight('banque', 'modifier');

$hookmanager->initHooks(array('enablebankingtransactionlist'));

$matcher = new EnableBankingMatcher($db);
$reconciler = new EnableBankingReconciler($db);
$txstatic = new EbTransaction($db);

$param = '';
if ($search_fk_bank_account > 0) {
	$param .= '&search_fk_bank_account='.$search_fk_bank_account;
}
$param .= '&search_status='.urlencode($search_status);
if ($search_direction) {
	$param .= '&search_direction='.urlencode($search_direction);
}
if ($search_text !== '') {
	$param .= '&search_text='.urlencode($search_text);
}
if ($limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
$backurl = $_SERVER["PHP_SELF"].'?'.ltrim($param, '&').'&page='.$page.'&sortfield='.$sortfield.'&sortorder='.$sortorder;

// Transaction processed by hand
$tx = null;
if ($txid > 0) {
	$tx = new EbTransaction($db);
	if ($tx->fetch($txid) <= 0) {
		$tx = null;
	}
}


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $matcher, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

$redirect = false;
if (empty($reshook) && $permissiontomatch) {
	// Validation of the chosen candidate: one line with its button, or the selected lines
	$tovalidate = ($confirmone > 0) ? array($confirmone) : ($massaction == 'validatematches' ? $toselect : array());
	if (!empty($tovalidate)) {
		$nbok = 0;
		foreach ($txstatic->fetchByIds($tovalidate) ?: array() as $selected) {
			$matchid = GETPOSTINT('match_'.$selected->id);
			if ($matchid <= 0) {
				continue;
			}
			if ($reconciler->confirm($selected, $matchid, $user) > 0) {
				$nbok++;
			} else {
				setEventMessages($selected->counterparty_name.' '.price($selected->amount).': '.$reconciler->error, $reconciler->errors, 'errors');
			}
		}
		if ($nbok) {
			setEventMessages($langs->trans('EnableBankingNbMatchDone', $nbok), null, 'mesgs');
		}
		$redirect = true;
	}

	// Ignore (one line or the selected lines) or restore an ignored line
	$ignore = ($action == 'ignore' || $massaction == 'ignore');
	$toignore = ($txid > 0) ? array($txid) : ($massaction == 'ignore' ? $toselect : array());
	if (($ignore || $action == 'unignore') && !empty($toignore)) {
		foreach ($txstatic->fetchByIds($toignore) ?: array() as $selected) {
			if ($reconciler->setIgnored($selected, $ignore, $user) < 0) {
				setEventMessages($reconciler->error, $reconciler->errors, 'errors');
			}
		}
		$redirect = true;
	}

	if ($massaction == 'recompute') {
		$ids = $toselect;
		if (empty($ids)) {
			// All transactions to process
			$sql = "SELECT rowid FROM ".$db->prefix()."enablebanking_transaction";
			$sql .= " WHERE status IN (".EbTransaction::STATUS_NEW.",".EbTransaction::STATUS_SUGGESTED.")";
			$sql .= " AND entity IN (".getEntity('enablebanking_transaction').")";
			if ($search_fk_bank_account > 0) {
				$sql .= " AND fk_bank_account = ".((int) $search_fk_bank_account);
			}
			$resql = $db->query($sql);
			while ($resql && ($obj = $db->fetch_object($resql))) {
				$ids[] = (int) $obj->rowid;
			}
		}
		$result = $matcher->suggest($ids, $user);
		if ($result < 0) {
			setEventMessages($matcher->error, $matcher->errors, 'errors');
		} else {
			setEventMessages($langs->trans('EnableBankingNbSuggested', $result), null, 'mesgs');
		}
		$redirect = true;
	}

	// Manual processing
	if ($tx && in_array($action, array('payinvoices', 'linkbankline', 'replacebankline', 'createline'))) {
		if ($action == 'payinvoices') {
			$result = $reconciler->payInvoices($tx, EnableBankingMatcher::invoiceTypeOf($tx->amount), GETPOST('invoiceids', 'array:int'), $user);
		} elseif ($action == 'linkbankline') {
			$result = $reconciler->linkBankLine($tx, GETPOSTINT('fk_bank'), $user);
		} elseif ($action == 'replacebankline') {
			$result = $reconciler->replaceBankLine($tx, GETPOSTINT('fk_bank'), $user);
		} else {
			$result = $reconciler->createBankLine($tx, GETPOST('label', 'alphanohtml'), GETPOSTINT('socid'), GETPOSTINT('cat1'), GETPOST('operation', 'aZ09'), $user);
		}
		if ($result > 0) {
			setEventMessages($langs->trans('EnableBankingMatchDone'), null, 'mesgs');
			$redirect = true;
		} else {
			setEventMessages($reconciler->error ? $reconciler->error : $langs->trans('Error'), $reconciler->errors, 'errors');
			$action = 'displaymanual';
		}
	}
}

if ($redirect) {
	header("Location: ".$backurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('EnableBankingTransactionsToProcess');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-enablebanking page-transaction_list');

// Linked accounts, for the filter
$linkedaccounts = array();
$sql = "SELECT ea.fk_bank_account, ba.label, ba.ref FROM ".$db->prefix()."enablebanking_account as ea";
$sql .= " INNER JOIN ".$db->prefix()."bank_account as ba ON ba.rowid = ea.fk_bank_account";
$sql .= " WHERE ea.entity IN (".getEntity('enablebanking_account').")";
$resql = $db->query($sql);
while ($resql && ($obj = $db->fetch_object($resql))) {
	$linkedaccounts[(int) $obj->fk_bank_account] = $obj->ref.' - '.$obj->label;
}

// Manual processing of one transaction
if ($action == 'displaymanual' && $permissiontomatch) {
	if ($tx && (int) $tx->status !== EbTransaction::STATUS_MATCHED) {
		$socid = GETPOSTISSET('filtersocid') ? GETPOSTINT('filtersocid') : (int) $tx->fk_soc;
		$invoicetype = EnableBankingMatcher::invoiceTypeOf($tx->amount);
		$iscredit = ($invoicetype == EnableBankingMatcher::TYPE_INVOICE);

		print load_fiche_titre($langs->trans('EnableBankingManualProcessing'), '', 'bank_account');
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('DateOperation').'</td><td>'.dol_print_date($tx->booking_date, 'day').'</td></tr>';
		print '<tr><td>'.$langs->trans('EnableBankingCounterparty').'</td><td>'.dolPrintHTML($tx->counterparty_name).' <span class="opacitymedium">'.dolPrintHTML($tx->counterparty_iban).'</span></td></tr>';
		print '<tr><td>'.$langs->trans('Label').'</td><td>'.dolPrintHTML($tx->remittance).'</td></tr>';
		print '<tr><td>'.$langs->trans('Amount').'</td><td><span class="amount">'.price($tx->amount, 0, $langs, 1, -1, -1, $tx->currency).'</span></td></tr>';
		print '</table><br>';

		// 1. Pay open invoices
		print '<div class="fichehalfleft">';
		print load_fiche_titre($langs->trans($iscredit ? 'EnableBankingPayCustomerInvoices' : 'EnableBankingPaySupplierInvoices'), '', '');
		print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="action" value="displaymanual"><input type="hidden" name="txid" value="'.$tx->id.'">';
		print $langs->trans('ThirdParty').': '.$form->select_company($socid, 'filtersocid', ($iscredit ? '(s.client:IN:1,2,3)' : '(s.fournisseur:=:1)'), 1, 0, 0, array(), 0, 'minwidth200');
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Refresh').'">';
		print '</form>';
		$invoices = $matcher->loadOpenInvoices($invoicetype);
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="payinvoices"><input type="hidden" name="txid" value="'.$tx->id.'">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td></td><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('Date').'</td><td class="right">'.$langs->trans('RemainderToPay').'</td></tr>';
		$nb = 0;
		foreach (is_array($invoices) ? $invoices : array() as $invoice) {
			if ($socid > 0 && $invoice['fk_soc'] != $socid) {
				continue;
			}
			if ($nb++ >= 100) {
				break;
			}
			print '<tr class="oddeven"><td class="width25"><input type="checkbox" name="invoiceids[]" value="'.$invoice['id'].'"></td>';
			print '<td><a href="'.EnableBankingMatcher::getElementUrl($invoicetype, $invoice['id']).'">'.dolPrintHTML($invoice['ref']).'</a>'.($invoice['extref'] ? ' <span class="opacitymedium">'.dolPrintHTML($invoice['extref']).'</span>' : '').'</td>';
			print '<td>'.dolPrintHTML($invoice['socname']).'</td><td>'.dol_print_date($invoice['date'], 'day').'</td>';
			print '<td class="right amount">'.price($invoice['remain']).'</td></tr>';
		}
		if (!$nb) {
			print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
		}
		print '</table>';
		print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('EnableBankingCreatePayment').'"></div>';
		print '</form>';
		print '</div>';

		print '<div class="fichehalfright">';
		// 2. Link a line already entered
		print load_fiche_titre($langs->trans('EnableBankingLinkExistingLine'), '', '');
		$lines = $matcher->loadOpenBankLines($tx->fk_bank_account, (int) $tx->booking_date - 60 * 86400, (int) $tx->booking_date + 60 * 86400);
		$options = array();
		foreach (is_array($lines) ? $lines : array() as $line) {
			if (enablebankingSameAmount($line['amount'], $tx->amount)) {
				$options[$line['id']] = dol_print_date($line['date'], 'day').' - '.$line['label'].' ('.price($line['amount']).')';
				if ($line['rappro']) {
					$options[$line['id']] .= ' - '.$langs->trans('EnableBankingReconciledSuffix', $line['num_releve']);
				}
			}
		}
		if (empty($options)) {
			print '<span class="opacitymedium">'.$langs->trans('EnableBankingNoLineSameAmount').'</span>';
		} else {
			print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="txid" value="'.$tx->id.'">';
			print $form->selectarray('fk_bank', $options, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
			print ' <button type="submit" class="button smallpaddingimp" name="action" value="linkbankline">'.$langs->trans('EnableBankingLink').'</button>';
			print ' <button type="submit" class="button smallpaddingimp" name="action" value="replacebankline">'.$langs->trans('EnableBankingReplaceLine').'</button>';
			print '</form>';
		}

		// 3. Create a simple line
		print '<br>';
		print load_fiche_titre($langs->trans('EnableBankingCreateSimpleLine'), '', '');
		$bankcateg = new BankCateg($db);
		$catoptions = array();
		foreach ($bankcateg->fetchAll() as $bankcategory) {
			$catoptions[$bankcategory->id] = $bankcategory->label;
		}
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="createline"><input type="hidden" name="txid" value="'.$tx->id.'">';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('Label').'</td><td><input type="text" name="label" class="minwidth300" value="'.dolPrintHTMLForAttribute($reconciler->getDefaultLabel($tx)).'"></td></tr>';
		print '<tr><td>'.$langs->trans('Type').'</td><td>';
		$form->select_types_paiements($reconciler->getPaymentCode($tx), 'operation', '', 2, 0);
		print '</td></tr>';
		if (count($catoptions)) {
			print '<tr><td>'.$langs->trans('Rubrique').'</td><td>'.Form::selectarray('cat1', $catoptions, '', 1).'</td></tr>';
		}
		print '<tr><td>'.$langs->trans('ThirdParty').'</td><td>'.$form->select_company((int) $tx->fk_soc, 'socid', '', 1, 0, 0, array(), 0, 'minwidth200').'</td></tr>';
		print '</table>';
		print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('EnableBankingCreateLine').'"></div>';
		print '</form>';
		print '</div>';
		print '<div class="clearboth"></div>';

		print '<div class="center"><a class="button button-cancel" href="'.$backurl.'">'.$langs->trans('Cancel').'</a></div><br>';
	}
}

// List
$sql = "SELECT t.rowid, t.fk_bank_account, t.booking_date, t.amount, t.currency, t.counterparty_name, t.counterparty_iban, t.remittance,";
$sql .= " t.fk_soc, t.status, t.fk_bank, s.nom as socname, ba.ref as bankref";
$sqlfrom = " FROM ".$db->prefix()."enablebanking_transaction as t";
$sqlfrom .= " INNER JOIN ".$db->prefix()."bank_account as ba ON ba.rowid = t.fk_bank_account";
$sqlfrom .= " LEFT JOIN ".$db->prefix()."societe as s ON s.rowid = t.fk_soc";
$sqlfrom .= " WHERE t.entity IN (".getEntity('enablebanking_transaction').")";
if ($search_fk_bank_account > 0) {
	$sqlfrom .= " AND t.fk_bank_account = ".((int) $search_fk_bank_account);
}
if ($search_status == 'toprocess') {
	$sqlfrom .= " AND t.status IN (".EbTransaction::STATUS_NEW.",".EbTransaction::STATUS_SUGGESTED.")";
} elseif ($search_status !== 'all' && is_numeric($search_status)) {
	$sqlfrom .= " AND t.status = ".((int) $search_status);
}
if ($search_direction == 'in') {
	$sqlfrom .= " AND t.amount > 0";
} elseif ($search_direction == 'out') {
	$sqlfrom .= " AND t.amount < 0";
}
if ($search_text !== '') {
	$sqlfrom .= natural_search(array('t.counterparty_name', 't.remittance', 't.counterparty_iban', 's.nom'), $search_text);
}

$nbtotalofrecords = 0;
$resql = $db->query("SELECT COUNT(t.rowid) as nb".$sqlfrom);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbtotalofrecords = (int) $obj->nb;
}

$sql .= $sqlfrom.$db->order($sortfield.',t.rowid', $sortorder.','.$sortorder).$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$rows = array();
while ($obj = $db->fetch_object($resql)) {
	$rows[] = $obj;
}
$num = count($rows);

// Candidates of the displayed transactions, in one query
$matchesbytx = array();
$ids = array();
foreach (array_slice($rows, 0, $limit) as $obj) {
	$ids[] = (int) $obj->rowid;
}
if (!empty($ids)) {
	$sqlm = "SELECT rowid, fk_transaction, element_type, element_ids, fk_soc, score, label FROM ".$db->prefix()."enablebanking_match";
	$sqlm .= " WHERE fk_transaction IN (".$db->sanitize(implode(',', $ids)).") ORDER BY score DESC, rowid ASC";
	$resqlm = $db->query($sqlm);
	while ($resqlm && ($m = $db->fetch_object($resqlm))) {
		$matchesbytx[(int) $m->fk_transaction][(int) $m->rowid] = array('id' => (int) $m->rowid, 'element_type' => $m->element_type, 'element_ids' => array_map('intval', explode(',', $m->element_ids)), 'score' => (int) $m->score, 'label' => $m->label);
	}
}

$arrayofmassactions = array();
if ($permissiontomatch) {
	$arrayofmassactions = array(
		'validatematches' => img_picto('', 'check', 'class="pictofixedwidth"').$langs->trans('EnableBankingValidateSelected'),
		'ignore' => img_picto('', 'close_title', 'class="pictofixedwidth"').$langs->trans('EnableBankingIgnoreSelected'),
		'recompute' => img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('EnableBankingRecompute'),
	);
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dolPrintHTMLForAttribute($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dolPrintHTMLForAttribute($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'bank_account', 0, '', '', $limit, 0, 0, 1);

print '<div class="opacitymedium">'.$langs->trans('EnableBankingListHelp').'</div><br>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_fk_bank_account', $linkedaccounts, $search_fk_bank_account, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre" colspan="2"><input type="text" class="flat maxwidth200" name="search_text" value="'.dolPrintHTMLForAttribute($search_text).'"></td>';
print '<td class="liste_titre right">'.$form->selectarray('search_direction', array('in' => $langs->trans('Credit'), 'out' => $langs->trans('Debit')), $search_direction, 1).'</td>';
print '<td class="liste_titre"></td>';
$statusoptions = array(
	'toprocess' => $langs->trans('EnableBankingToProcess'),
	'all' => $langs->trans('All'),
	EbTransaction::STATUS_NEW => $langs->trans('EnableBankingTxStatusNew'),
	EbTransaction::STATUS_SUGGESTED => $langs->trans('EnableBankingTxStatusSuggested'),
	EbTransaction::STATUS_MATCHED => $langs->trans('EnableBankingTxStatusMatched'),
	EbTransaction::STATUS_IGNORED => $langs->trans('EnableBankingTxStatusIgnored'),
);
print '<td class="liste_titre">'.$form->selectarray('search_status', $statusoptions, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Titles
print '<tr class="liste_titre">';
print_liste_field_titre('DateOperation', $_SERVER["PHP_SELF"], 't.booking_date', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('BankAccount', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('EnableBankingCounterparty', $_SERVER["PHP_SELF"], 't.counterparty_name', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Label', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Amount', $_SERVER["PHP_SELF"], 't.amount', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('ThirdParty', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER["PHP_SELF"], 't.status', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('EnableBankingSuggestion', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$socstatic = new Societe($db);

$i = 0;
foreach ($rows as $obj) {
	if ($i++ >= $limit) {
		break;
	}
	$id = (int) $obj->rowid;
	$matches = isset($matchesbytx[$id]) ? $matchesbytx[$id] : array();
	$sure = EnableBankingMatcher::isSure($matches);

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->booking_date), 'day').'</td>';
	print '<td class="tdoverflowmax100">'.dolPrintHTML($obj->bankref).'</td>';
	print '<td class="tdoverflowmax200">'.dolPrintHTML($obj->counterparty_name).($obj->counterparty_iban ? '<br><span class="opacitymedium small">'.dolPrintHTML($obj->counterparty_iban).'</span>' : '').'</td>';
	print '<td class="tdoverflowmax300 small" title="'.dolPrintHTMLForAttribute($obj->remittance).'">'.dolPrintHTML($obj->remittance).'</td>';
	print '<td class="right nowraponall"><span class="amount'.($obj->amount < 0 ? ' error' : '').'">'.price($obj->amount, 0, $langs, 1, -1, -1, $obj->currency).'</span></td>';
	print '<td class="tdoverflowmax150">';
	if ($obj->fk_soc > 0) {
		$socstatic->id = (int) $obj->fk_soc;
		$socstatic->name = $obj->socname;
		print $socstatic->getNomUrl(1, '', 0, 1);
	}
	print '</td>';
	print '<td class="nowraponall">'.$txstatic->LibStatut((int) $obj->status, 5).'</td>';

	// Suggestion / result
	print '<td class="minwidth200">';
	if ((int) $obj->status === EbTransaction::STATUS_MATCHED && $obj->fk_bank > 0) {
		print '<a href="'.EnableBankingMatcher::getElementUrl(EnableBankingMatcher::TYPE_BANKLINE, $obj->fk_bank).'">'.img_picto('', 'bank', 'class="pictofixedwidth"').$langs->trans('BankLine').' '.((int) $obj->fk_bank).'</a>';
	} elseif (!empty($matches)) {
		$options = array();
		foreach ($matches as $match) {
			$options[$match['id']] = $langs->trans('EnableBankingMatchType_'.$match['element_type']).': '.$match['label'].' ['.$match['score'].']';
		}
		print $form->selectarray('match_'.$id, $options, key($matches), 0, 0, 0, '', 0, 0, ($permissiontomatch ? 0 : 1), '', 'maxwidth300 widthcentpercentminusx');
		$first = reset($matches);
		foreach ($first['element_ids'] as $elementid) {
			print ' <a href="'.EnableBankingMatcher::getElementUrl($first['element_type'], $elementid).'" target="_blank" rel="noopener">'.img_picto($langs->trans('Show'), 'eye').'</a>';
		}
		if ($sure) {
			print ' '.img_picto($langs->trans('EnableBankingSureMatch'), 'tick');
		}
	} elseif ((int) $obj->status !== EbTransaction::STATUS_IGNORED) {
		print '<span class="opacitymedium">'.$langs->trans('EnableBankingNoSuggestion').'</span>';
	}
	print '</td>';

	// Actions
	print '<td class="nowraponall center">';
	if ($permissiontomatch && (int) $obj->status !== EbTransaction::STATUS_MATCHED) {
		if (!empty($matches)) {
			print '<button type="submit" class="button smallpaddingimp" name="confirmone" value="'.$id.'">'.$langs->trans('Validate').'</button> ';
		}
		if ((int) $obj->status === EbTransaction::STATUS_IGNORED) {
			print '<a class="reposition" href="'.$backurl.'&action=unignore&txid='.$id.'&token='.newToken().'">'.img_picto($langs->trans('EnableBankingRestore'), 'undo').'</a>';
		} else {
			print '<a href="'.$backurl.'&action=displaymanual&txid='.$id.'">'.img_picto($langs->trans('EnableBankingManualProcessing'), 'edit').'</a> ';
			print '<a class="reposition" href="'.$backurl.'&action=ignore&txid='.$id.'&token='.newToken().'">'.img_picto($langs->trans('EnableBankingIgnore'), 'close_title').'</a>';
		}
		print ' <input type="checkbox" class="flat checkforselect" name="toselect[]" value="'.$id.'"'.($sure ? ' checked' : '').'>';
	}
	print '</td>';
	print '</tr>';
}

if ($num == 0) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();

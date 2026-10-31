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
 * \file    htdocs/custom/enablebanking/callback.php
 * \ingroup enablebanking
 * \brief   Return page of the bank authorisation. The user is logged in Dolibarr when he comes back.
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
dol_include_once('/enablebanking/lib/enablebanking.lib.php');
dol_include_once('/enablebanking/class/enablebankingclient.class.php');
dol_include_once('/enablebanking/class/ebaccount.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("banks", "enablebanking@enablebanking"));

$state = GETPOST('state', 'aZ09');
$code = GETPOST('code', 'alphanohtml');
$autherror = GETPOST('error', 'alphanohtml');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('enablebanking') || !$user->hasRight('enablebanking', 'link') || $user->socid) {
	accessforbidden();
}

$ebaccount = new EbAccount($db);
if ($ebaccount->fetchByState($state) <= 0) {
	accessforbidden($langs->trans('EnableBankingErrorBadState'), 0, 0, 1);
}

$bankaccount = new Account($db);
$bankaccount->fetch($ebaccount->fk_bank_account);
$taburl = dol_buildpath('/enablebanking/bankaccount_link.php', 1).'?id='.$ebaccount->fk_bank_account;
$sessionkey = 'enablebanking_session_'.$state;
$client = new EnableBankingClient($db);


/*
 * Actions
 */

$chosen = null;		// Account of the session to link
$session = null;
$backtotab = false;

if ($autherror !== '') {
	// The user refused or the bank returned an error
	setEventMessages($langs->trans('EnableBankingErrorAuth').' '.dolPrintHTML($autherror.' '.GETPOST('error_description', 'alphanohtml')), null, 'errors');
	$backtotab = true;
} elseif ($action == 'chooseaccount' && !empty($_SESSION[$sessionkey])) {
	// Choice of account when the session contains several accounts
	$session = $_SESSION[$sessionkey];
	$uid = GETPOST('account_uid', 'alphanohtml');
	foreach ($session['accounts'] as $account) {
		if (isset($account['uid']) && $account['uid'] === $uid) {
			$chosen = $account;
		}
	}
	if (!$chosen) {
		setEventMessages($langs->trans('ErrorBadValueForParameter', $uid, 'account_uid'), null, 'errors');
	}
} elseif ($code !== '' && empty($_SESSION[$sessionkey])) {
	$session = $client->createSession($code);
	$accounts = (is_array($session) && !empty($session['accounts']) && is_array($session['accounts'])) ? $session['accounts'] : array();
	if ($session === false || empty($session['session_id'])) {
		setEventMessages($langs->trans('EnableBankingErrorAuth').' '.dolPrintHTML($client->error), null, 'errors');
		$backtotab = true;
	} elseif (empty($accounts)) {
		setEventMessages($langs->trans('EnableBankingErrorNoAccount'), null, 'errors');
		$backtotab = true;
	} else {
		// Choose automatically the account with the IBAN of the Dolibarr bank account, or the only one
		$iban = enablebankingNormalizeIban($bankaccount->iban);
		foreach ($accounts as $account) {
			if ($iban !== '' && !empty($account['account_id']['iban']) && enablebankingNormalizeIban($account['account_id']['iban']) === $iban) {
				$chosen = $account;
			}
		}
		if (!$chosen && count($accounts) == 1) {
			$chosen = $accounts[0];
		}
		if (!$chosen) {
			$_SESSION[$sessionkey] = array('session_id' => $session['session_id'], 'access' => (isset($session['access']) ? $session['access'] : array()), 'accounts' => $accounts);
		}
	}
}

if ($chosen) {
	$oldsession = $ebaccount->session_id;
	if ($ebaccount->linkRemoteAccount($session, $chosen, $user) < 0) {
		setEventMessages($ebaccount->error, $ebaccount->errors, 'errors');
	} else {
		if ($oldsession && $oldsession != $ebaccount->session_id) {
			$client->deleteSession($oldsession);
		}
		setEventMessages($langs->trans('EnableBankingLinked'), null, 'mesgs');
	}
	unset($_SESSION[$sessionkey]);
	$backtotab = true;
}

if ($backtotab || empty($_SESSION[$sessionkey])) {
	if ((int) $ebaccount->status !== EbAccount::STATUS_ACTIVE || $ebaccount->auth_state) {
		// The authorisation request is over, it can't be used again
		$ebaccount->auth_state = '';
		$ebaccount->update($user);
	}
	header("Location: ".$taburl);
	exit;
}


/*
 * View: choice of the account
 */

llxHeader('', $langs->trans('EnableBanking'), '', '', 0, 0, '', '', '', 'mod-enablebanking page-callback');

print load_fiche_titre($langs->trans('EnableBankingChooseAccount', $bankaccount->label), '', 'bank_account');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="chooseaccount">';
print '<input type="hidden" name="state" value="'.dolPrintHTMLForAttribute($state).'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td></td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('IBAN').'</td><td>'.$langs->trans('Currency').'</td></tr>';
$first = true;
foreach ($_SESSION[$sessionkey]['accounts'] as $account) {
	print '<tr class="oddeven"><td class="width25"><input type="radio" name="account_uid" value="'.dolPrintHTMLForAttribute($account['uid']).'"'.($first ? ' checked' : '').'></td>';
	print '<td>'.dolPrintHTML(isset($account['name']) ? $account['name'] : '').'</td>';
	print '<td>'.dolPrintHTML(isset($account['account_id']['iban']) ? $account['account_id']['iban'] : '').'</td>';
	print '<td>'.dolPrintHTML(isset($account['currency']) ? $account['currency'] : '').'</td></tr>';
	$first = false;
}
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('EnableBankingLinkThisAccount').'"></div>';
print '</form>';

llxFooter();
$db->close();

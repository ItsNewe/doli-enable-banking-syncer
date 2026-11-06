<?php
/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/enablebanking/core/boxes/box_enablebanking_transactions.php
 * \ingroup enablebanking
 * \brief   Widget of the last transactions imported from the bank, with their match status
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
dol_include_once('/enablebanking/class/ebtransaction.class.php');
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');


/**
 * Class to show the last imported bank transactions
 */
class box_enablebanking_transactions extends ModeleBoxes
{
	public $boxcode = "enablebankingtransactions";
	public $boximg = "bank_account";
	public $boxlabel = "EnableBankingBoxTransactions";
	public $depends = array("enablebanking", "banque");

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$param	More parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user;

		$this->db = $db;
		$this->param = $param;
		$this->hidden = !($user->hasRight('enablebanking', 'read') && $user->hasRight('banque', 'lire'));
		$this->msgNoRecords = 'EnableBankingBoxNoTransaction';
	}

	/**
	 * Load data into info_box_contents array to show array later
	 *
	 * @param	int		$max	Maximum number of records to load
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $langs;
		$langs->loadLangs(array("banks", "enablebanking@enablebanking"));

		$this->max = $max;
		$listurl = dol_buildpath('/enablebanking/transaction_list.php', 1).'?mainmenu=bank&leftmenu=bank';
		$this->info_box_head = array(
			'text' => $langs->trans("EnableBankingBoxTransactionsTitle", $max),
			'sublink' => $listurl.'&search_status=all',
			'subpicto' => 'filter.png',
			'subtext' => $langs->trans("EnableBankingTransactionsToProcess"),
			'limit' => dol_strlen($langs->trans("EnableBankingBoxTransactionsTitle", $max)),
			'nbcol' => 5,
		);
		if ($this->hidden) {
			return;
		}

		// Last synchronisation, last transaction and number to process, in one query
		$sql = "SELECT (SELECT MAX(a.date_last_sync) FROM ".$this->db->prefix()."enablebanking_account as a WHERE a.entity IN (".getEntity('enablebanking_account').")) as lastsync,";
		$sql .= " MAX(t.booking_date) as lastbooking,";
		$sql .= " SUM(CASE WHEN t.status IN (".EbTransaction::STATUS_NEW.",".EbTransaction::STATUS_SUGGESTED.") THEN 1 ELSE 0 END) as nbtoprocess";
		$sql .= " FROM ".$this->db->prefix()."enablebanking_transaction as t";
		$sql .= " WHERE t.entity IN (".getEntity('enablebanking_transaction').")";
		$resql = $this->db->query($sql);
		$summary = $resql ? $this->db->fetch_object($resql) : null;
		if (!$summary) {
			$this->info_box_contents[0][] = array('td' => '', 'maxlength' => 500, 'text' => $this->db->lasterror());
			return;
		}
		$line = 0;
		$text = $langs->trans('EnableBankingLastSync').': <strong>'.($summary->lastsync ? dol_print_date($this->db->jdate($summary->lastsync), 'dayhour', 'tzuserrel') : $langs->trans('None')).'</strong>';
		$text .= ' &nbsp; '.$langs->trans('EnableBankingLastTransaction').': <strong>'.($summary->lastbooking ? dol_print_date($this->db->jdate($summary->lastbooking), 'day') : $langs->trans('None')).'</strong>';
		$this->info_box_contents[$line][] = array('td' => 'class="nohover" colspan="4"', 'textnoformat' => $text);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="nohover right nowraponall"',
			'textnoformat' => '<a href="'.$listurl.'&search_status=toprocess"><span class="badge badge-'.($summary->nbtoprocess ? 'warning' : 'secondary').'">'.((int) $summary->nbtoprocess).'</span> '.$langs->trans('EnableBankingToProcess').'</a>',
		);
		$line++;

		// Last imported transactions
		$sql = "SELECT t.rowid, t.booking_date, t.amount, t.currency, t.counterparty_name, t.remittance, t.status, t.fk_bank";
		$sql .= " FROM ".$this->db->prefix()."enablebanking_transaction as t";
		$sql .= " WHERE t.entity IN (".getEntity('enablebanking_transaction').")";
		$sql .= $this->db->order('t.date_creation,t.rowid', 'DESC,DESC').$this->db->plimit($max, 0);
		$resql = $this->db->query($sql);
		$rows = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[(int) $obj->rowid] = $obj;
		}

		// Best suggestion of each transaction, in one query
		$suggestions = array();
		if (!empty($rows)) {
			$sql = "SELECT fk_transaction, element_type, label FROM ".$this->db->prefix()."enablebanking_match";
			$sql .= " WHERE fk_transaction IN (".$this->db->sanitize(implode(',', array_keys($rows))).") ORDER BY score DESC, rowid ASC";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				if (!isset($suggestions[(int) $obj->fk_transaction])) {
					$suggestions[(int) $obj->fk_transaction] = $langs->trans('EnableBankingMatchType_'.$obj->element_type).': '.$obj->label;
				}
			}
		}

		$txstatic = new EbTransaction($this->db);
		foreach ($rows as $id => $obj) {
			$who = $obj->counterparty_name !== '' && $obj->counterparty_name !== null ? $obj->counterparty_name : $obj->remittance;
			if ((int) $obj->status === EbTransaction::STATUS_MATCHED && $obj->fk_bank > 0) {
				$result = '<a href="'.EnableBankingMatcher::getElementUrl(EnableBankingMatcher::TYPE_BANKLINE, $obj->fk_bank).'">'.img_picto('', 'bank', 'class="pictofixedwidth"').$langs->trans('BankTransaction').' '.((int) $obj->fk_bank).'</a>';
			} else {
				$result = isset($suggestions[$id]) ? dolPrintHTML($suggestions[$id]) : '';
			}
			$this->info_box_contents[$line][] = array('td' => 'class="nowraponall"', 'text' => dol_print_date($this->db->jdate($obj->booking_date), 'day'));
			$this->info_box_contents[$line][] = array(
				'td' => 'class="tdoverflowmax150" title="'.dolPrintHTMLForAttribute($obj->counterparty_name.' '.$obj->remittance).'"',
				'textnoformat' => '<a href="'.$listurl.'&search_status=all&search_text='.urlencode(dol_trunc((string) $who, 40, 'right', 'UTF-8', 1)).'">'.dolPrintHTML((string) $who).'</a>',
			);
			$this->info_box_contents[$line][] = array('td' => 'class="tdoverflowmax200 small" title="'.dolPrintHTMLForAttribute(strip_tags($result)).'"', 'textnoformat' => $result);
			$this->info_box_contents[$line][] = array(
				'td' => 'class="right nowraponall amount"',
				'textnoformat' => '<span class="amount'.($obj->amount < 0 ? ' error' : '').'">'.price($obj->amount, 0, $langs, 1, -1, -1, $obj->currency).'</span>',
			);
			$this->info_box_contents[$line][] = array('td' => 'class="right" width="18"', 'textnoformat' => $txstatic->LibStatut((int) $obj->status, 3));
			$line++;
		}

		if (empty($rows)) {
			$this->info_box_contents[$line][] = array('td' => 'class="center" colspan="5"', 'text' => '<span class="opacitymedium">'.$langs->trans($this->msgNoRecords).'</span>', 'asis' => 1);
		}
	}

	/**
	 * Method to show box
	 *
	 * @param	?array<array{text?:string,sublink?:string,subtext?:string,subpicto?:?string,picto?:string,nbcol?:int,limit?:int,subclass?:string,graph?:int<0,1>,target?:string}>	$head		Array with properties of box title
	 * @param	?array<array{tr?:string,td?:string,target?:string,text?:string,text2?:string,textnoformat?:string,tooltip?:string,logo?:string,url?:string,maxlength?:int,asis?:int<0,1>}>	$contents	Array with properties of box lines
	 * @param	int<0,1>	$nooutput	No print, only return string
	 * @return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}

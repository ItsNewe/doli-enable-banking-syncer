# Enable Banking for Dolibarr

Synchronise Dolibarr bank accounts with [Enable Banking](https://enablebanking.com) (Open Banking, PSD2),
match the imported transactions with customer and supplier invoices, and reconcile bank lines.

## Features

- Link a Dolibarr bank account to an account at the bank (tab **Enable Banking** of the bank account).
- Import of booked transactions every 6 hours (scheduled job) or on demand, without duplicates.
- Suggestions for each transaction:
  - line already entered in Dolibarr (same amount, date within the tolerance),
  - line already reconciled by another import (CSV statement...): keep it, or replace its label and dates
    with the bank data,
  - customer invoice (credit) or supplier invoice (debit) whose remain to pay equals the amount,
    scored with the invoice reference found in the label, the IBAN and the name of the third party,
  - all open invoices of a third party paid at once.
- Validation in one click or in mass: the payment is created with the core payment classes,
  the bank line is created and reconciled (statement number `YYYYMM` by default).
- Transactions without match stay to process: pay invoices chosen by hand, link an existing line,
  create a bank line without payment, or ignore.

## Installation

Download `module_enablebanking-x.y.z.zip` from the GitHub Releases page, then upload it in
**Setup > Modules > Deploy/install external app/module** and enable the module.

To build the zip yourself: `./build.sh` (writes `build/module_enablebanking-<version>.zip` from the committed files).

To publish a release: bump `$this->version` in `core/modules/modEnableBanking.class.php`, commit, then
`git tag v<version> && git push origin v<version>`. The GitHub workflow builds the zip and attaches it to the release.

## Setup

1. Create an application in the Enable Banking control panel (sandbox or production) and declare the
   redirect URL shown on the setup page of the module (`.../custom/enablebanking/callback.php`).
2. Paste the application ID and the private key (PEM) in **Setup > Modules > Enable Banking**.
   The key is stored encrypted. Use **Test the connection**.
3. On a bank account, open the tab **Enable Banking**, choose the bank and authorise the access.
4. Give the permissions of the module to the users (read, connect, validate matches).

## Limits

- Banks allow 4 background fetches per day and per account; manual synchronisation is not limited.
- The consent given at the bank lasts at most the duration allowed by the bank (often 180 days),
  then it must be renewed from the tab.
- Payments are only created on accounts in the company currency.
- Only booked transactions are imported (pending ones are not).

## Licence

GPLv3 or later, see file COPYING.

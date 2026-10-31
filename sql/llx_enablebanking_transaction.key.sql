-- Copyright (C) 2026	Newe	<lobo@arcafin.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.


ALTER TABLE llx_enablebanking_transaction ADD UNIQUE INDEX uk_enablebanking_transaction_ref(fk_eb_account, entry_reference);
ALTER TABLE llx_enablebanking_transaction ADD INDEX idx_enablebanking_transaction_status(entity, status);
ALTER TABLE llx_enablebanking_transaction ADD INDEX idx_enablebanking_transaction_bank(fk_bank);
ALTER TABLE llx_enablebanking_transaction ADD CONSTRAINT fk_enablebanking_transaction_account FOREIGN KEY (fk_eb_account) REFERENCES llx_enablebanking_account(rowid);

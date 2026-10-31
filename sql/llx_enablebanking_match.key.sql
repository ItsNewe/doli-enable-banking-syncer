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


ALTER TABLE llx_enablebanking_match ADD INDEX idx_enablebanking_match_transaction(fk_transaction);
ALTER TABLE llx_enablebanking_match ADD CONSTRAINT fk_enablebanking_match_transaction FOREIGN KEY (fk_transaction) REFERENCES llx_enablebanking_transaction(rowid);

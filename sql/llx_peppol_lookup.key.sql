-- SPDX-License-Identifier: GPL-3.0-or-later
ALTER TABLE llx_peppol_lookup ADD UNIQUE INDEX uk_peppol_lookup (entity, fk_soc);

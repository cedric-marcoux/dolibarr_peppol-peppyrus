DELETE l1 FROM llx_peppol_peppolimport l1
INNER JOIN llx_peppol_peppolimport l2
WHERE
    l1.rowid > l2.rowid AND
    l1.peppolid = l2.peppolid;

ALTER TABLE llx_peppol_peppolimport ADD CONSTRAINT unique_peppolid UNIQUE (peppolid);

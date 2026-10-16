# Changelog

## 2.4.2

Peppol ID search results give enough information to choose:

- The kind of number behind each ID (for example KvK number, OIN, VAT number NL, enterprise number BE), from the ICD/EAS scheme code.
- Whether the ID accepts invoices (UBL Invoice document type registered in the directory).
- Whether the ID matches the VAT number or a professional ID (for example the KvK number) of the third party in Dolibarr.
- Location, registration date and additional information from the directory.
- A "Recommended" mark on the first ID that matches the third party and accepts invoices.
- When all results belong to the same organisation, a note that any ID that accepts invoices reaches the same recipient.

## 2.4.1

- The Peppol ID search opens in the same window instead of a new tab, with a button back to the card it was started from (third party, customer or supplier card). After "Use this ID" the user returns to that card.
- The search button is also shown on the customer card and the supplier card (hook context `thirdpartysupplier` added; reactivate the module once).
- The return URL is built from the third party id and a fixed list of cards, never taken from the request.
- Removed inline JavaScript passed to `llxHeader()` as a script file list (leftover of the popup).

## 2.4.0

Requirements: Dolibarr 18.0 up to 24.0.2, PHP 7.1 or later (same as the einvoicing library). All functions used exist in Dolibarr 18.

- Peppol ID search from the third party card: searches the VAT number, then professional ID 1 (KvK number in the Netherlands), then the company name, and stops at the first search with results. Many participants are registered with their registration number (for example scheme 0106 KvK) and were not found by VAT number. The page shows which criterion was used.
- The search field is also shown from the third party card, so another name or number can be tried. Names with spaces and dots (for example "B.V.") are accepted; before, they were emptied by the input filter, so a search by name never ran.
- Request headers to the Peppol directory are sent as "Name: value" lines (they were ignored).
- Log files no longer contain names, VAT numbers, registration numbers, Peppol IDs, addresses, e-mail addresses, bank details or whole objects (invoice, third party, seller, buyer, access point responses). Logs keep technical information: record ids, file paths, HTTP codes, number of results.

## 2.3.1

- Private customers are skipped silently: no Peppol file and no message, the PDF is made as usual. A customer counts as private when its third party type is "Private individual", or when no type is set and it has neither a Peppol ID nor a VAT number. A business type without these details still gets the warnings. With `PEPPOL_FORCE_XML_WITH_VATNULL` the XML is still made, as before.
- Dutch translation (`nl_NL`), formal, covering all texts of `en_US` plus texts used by the code that were missing from every language file.

## 2.3.0

After updating: deactivate and reactivate the module once, so `sql/update_003.sql` adds the `entity` column to `llx_peppol`.

Security (internal guideline for custom modules):

- Webhook (`public/hook.php`) refuses every call while no secret key is set (`PEPPOL_AP_WEBHOOK_KEY`, now configurable in the setup page), checks that the module is enabled, reads headers case-insensitively, no longer returns error details or logs the expected signature, and stores a new document in one transaction. The duplicate check queried a non-existing table and now uses `llx_peppol_peppolimport`, filtered on entity.
- Setting the Peppol ID of a third party (`search.php`, `peppol_search_directory.php`) requires a valid token, the module write right, the third party write right and access to that third party.
- Sending an invoice through Peppol requires the module write right and the invoice write right; the hook only acts on the invoice card.
- Hooks `afterPDFCreation`, `afterODTCreation` and `addMoreActionsButtons` return 0 when there is nothing to do or the Peppol file cannot be made, so PDF generation and surrounding actions no longer fail.
- API keys are no longer shown on the setup page; an empty field keeps the current key. The test key is stored as `PEPPOL_AP_DEV_API_KEY` (encrypted); the old `PEPPOL_AP_API_KEY_DEV` is read until the key is saved again.
- Output from the access point is escaped in the Peppol tab and on the search page.
- Connections to Peppyrus and the Peppol directory are limited to HTTPS.
- Pages check that the module is enabled and the read right (`index.php`, `peppol_tab.php`, `peppolimport_card.php`); `analyze` and `refresh` require the write right and a token.
- Removed `myobject_contact.php` (template leftover that referred to a non-existing class).
- Imported Peppol files are stored per entity: `DOL_DATA_ROOT/peppol` for entity 1 (unchanged, no files move) and `DOL_DATA_ROOT/<entity>/peppol` for other entities (`module_parts['dir']`).
- The webhook only accepts an inbound document addressed to the Peppol ID of the entity in the URL (`PEPPOL_AP_SENDER_ID`); with Multicompany enabled, that ID must be configured.
- The PDF of an import is downloaded through `document.php?modulepart=peppolpeppyrus` with the module read right; the module no longer creates public share links for it.
- All `GETPOST` calls have a type; `@var` declarations for globals on all pages; `dol_mkdir()` instead of `mkdir()`.

Fixes:

- No TypeError when a buyer without VAT number and Peppol ID has no email address; a warning is shown instead.
- `peppolAddLog()` produced invalid SQL, so nothing was logged; fixed, with an `entity` column.
- Reference numbering of imports used an undefined variable and never filtered on entity.
- `$conf->global` replaced by `getDolGlobalString()` / `getDolGlobalInt()`.
- `fileExist()` of an import was empty, so the mass action "analyze" reported every file as missing.
- Renaming an import from its provisional ref used a wrong directory and index path.

Requirements: Dolibarr 24, PHP 8.1 (lowered to Dolibarr 18 / PHP 7.1 in 2.4.0).

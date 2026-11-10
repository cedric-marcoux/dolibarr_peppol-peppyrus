# Changelog

## 2.13.0

- **Reject an incoming invoice.** The card of an incoming Peppol document has a button **Reject**. You choose a reason (the Peppol code list: prices incorrect, quantity incorrect, other reason with an explanation, ...). The supplier receives an Invoice Response "Rejected" with that reason through Peppol (Peppol BIS 63). After that the document can no longer be booked. When the supplier cannot receive such a message (according to the Peppol directory), the rejection is only stored here, with a message to inform the supplier yourself. When Peppyrus returns an error, nothing changes and you can try again.
- **Approval on booking.** After booking, the supplier receives an Invoice Response "Accepted", when the supplier can receive it. New setting **Send approval to the supplier on booking** (default on). When sending fails, the invoice is booked anyway, with a message. "Paid" is not sent.
- The answer to the supplier (status, date, reason) is shown on the card of the document and in the Peppol log.
- **Answers of customers to our invoices.** When a customer sends an Invoice Response, the daily job fetches it. It is linked to the own invoice with that number, only when the sender is the Peppol ID of that customer, and shown on the Peppol tab of the invoice. A rejection or a query also creates an agenda event. Such an answer no longer becomes an incoming document.
- **Outgoing has a third tick:** three blue ticks when the customer received the invoice or has it in process, three green ticks when accepted or paid, an orange question mark on a query, a red cross when rejected by the customer. The reason is shown on hover. Only customers that send an Invoice Response give this third tick; Peppol does not require it.
- **PDF from Peppyrus.** When an incoming invoice has no PDF, Peppyrus makes one of the invoice itself on booking (`render`), attached as `peppol-<ref>.pdf` to the supplier invoice.
- **Credit notes of suppliers can be booked.** Book now turns a Peppol credit note into a supplier credit note of the credited supplier invoice (same supplier, invoice number from `BillingReference`). Lines and VAT come from the e-invoice; Dolibarr makes the amounts negative. It is validated when the total matches. Without that invoice, or without a reference, a message and nothing is created, unless `INVOICE_CREDIT_NOTE_STANDALONE` is on (the same rule as Dolibarr itself).
- **Fixed: the card of an incoming document said "Access denied"** without the module Multicompany. The entity of the document was not read, so the entity check always refused.

**After updating:** disable and enable the module (Setup, Modules), so the new columns for the answer are added to the database. Without them the card of an incoming document gives an error.

## 2.12.0

Compared with the Peppyrus API (`https://api.peppyrus.be/v1/openapi.json`).

- **Failed deliveries are reported.** A new daily scheduled job reads the folder FAILED at Peppyrus. For each own invoice that could not be delivered: one line "failed" on the Peppol tab of the invoice and, with the agenda on, an event on the invoice. Until now such an invoice simply looked sent.
- **The delivery report on the Peppol tab works.** The tab read fields that Peppyrus does not return (`status`, `delivered`) and therefore never showed anything. It now shows the transmission rules and the validation rules of Peppyrus (FATAL or WARNING, with rule, location and message). The report is fetched once, not once per log line, and without a message on every visit.
- **Peppyrus plan on the setup page:** status, messages sent this month against the maximum, and storage, with a warning from 90% and at the maximum. When Peppyrus refuses because the maximum is reached (HTTP 402), the message now says so instead of "unexpected error".
- **New page Billing > Peppol > Outgoing**, next to Peppol candidates: sent customer invoices with their status at Peppyrus. One tick (grey): waiting in OUTBOX; two ticks (green): delivered to the access point of the customer; red cross: not delivered (FAILED); question mark when Peppyrus did not answer. By default only what is not delivered yet, of the last 90 days. Peppol has no "read" status; only an Invoice Response from the customer would tell more, and the module does not process those yet.
- **Webhook removed** (`public/hook.php`, with the setting "Webhook secret key"). It was written for another provider (Scrada, `x-scrada-*` headers); Peppyrus has no webhooks. It was a public address that could never receive anything useful. Incoming documents still arrive through the daily scheduled job.

**After updating:** deactivate and reactivate the module (Setup, Modules) so the new scheduled job and the menu Outgoing are created and the old webhook key is removed, then flush memcached. Check in Scheduled jobs that "Peppol: daily check of invoices the access point could not deliver" is enabled and runs as a user with the right Peppol: read.

## 2.11.1

- **Module enabled check on every page.** The Peppol directory search, the Peppol list and the Peppol ID search now check themselves that the module is enabled (`isModEnabled()`), like the other pages. The permission of the module was already checked, so a user without that permission could not open these pages before either.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.11.0

Security and privacy fixes, and the bug fixes of the original module (Cedric Marcoux, version 2.3.1). Not taken over: the Updates tab (it installs versions from the original repository and would replace this fork) and the disclaimer.

**Security and privacy**

- **No link to the PDF online in the Peppol file any more.** When the invoice had been shared once (public link in Dolibarr), the link went along in the message. That link works without login for everyone who sees the message: every access point on the route and anyone they pass it on to. The invoice could be read without the sender or the customer knowing.
- **No phone number and email address of a private customer** in the message any more: they are not needed to approve the invoice.
- **No fixed text "note"** when the public note of the invoice is empty; a filled-in note goes along without HTML.
- **The PDF in the Peppol file is a setting** (setup page, "Embed the PDF in the Peppol file", on by default). In Peppol the message itself is the invoice; turn it off when the PDF holds data the recipient does not need, for example care or personal data.
- **Removed `scripts/validate.php` and `scripts/json2csv.php`**: development scripts that were in the package and could be called through the browser.
- **Removed the tests, scripts and documentation of the bundled libraries** (`vendor/josemmo/einvoicing` and `vendor/josemmo/uxml`), among them `scripts/build-docs.php`, which writes files when it is called. They were in the package and could be called through the browser. Only `src`, `composer.json`, `README.md` and `LICENSE` stay.
- **Received Peppol documents of another company**: the card of a received document now checks that the document belongs to one of the entities of the user (multicompany). Before, a document of another entity could be opened by changing the id in the address bar (from the original module).
- **Automatic sending as a scheduled job**: `files.lib.php` is loaded where `dol_is_file()` is used; without it the job could stop with a fatal error.

**From the original module (2.3.1)**

The history of the original module up to 2.3.1 is merged into this fork, so GitHub no longer reports it as behind. Where both changed the same file, this fork's version is kept; it already holds the fixes listed here.

- **Credit notes** (#11): positive quantities, prices and totals in the Peppol XML, as a Peppol CreditNote requires. Before, the quantity was made negative, which gave the credit note negative totals and could get it rejected. The discount on a line is calculated on the absolute line total.
- **Paid invoices and credit notes that were used** (#12) get a Peppol file with the PDF and the Peppol buttons again, as for sending by email. Before, only "validated" counted. Drafts and abandoned documents stay excluded.
- **Sending** (#13): the confirmation also checks that the document is validated or paid ("Only validated or paid invoices and credit notes can be sent through Peppol").
- **Unit price with full precision** (#15): the unit price goes into the XML with the decimals of Dolibarr (up to 8) instead of rounded to 2, so line amounts and the total without VAT match the invoice to the cent.
- **Peppol log** (#18): every sending (successful or not) is written to the log on the Peppol tab of the invoice again.
- **ZIP export** of Peppol files: for each invoice it is checked that the user may see it (own customers, entity, external users).
- **Data from the access point escaped** (#19): error messages, folder and organisation name, message ID and page numbers. Identifiers of received messages (message ID, sender, receiver) are cleaned to letters, digits and `. _ : @ -`.
- **PHP warning** in the VAT summary of the XML fixed.
- Tests: statuses that may go through Peppol, cleaning of identifiers, escaping of error messages from the access point.

**After updating:** install the module through Setup, Modules ("Deploy/install an external module"; Dolibarr then replaces the whole folder, so the removed files disappear from the server too). Flush memcached. Generate the PDF of a credit note again before sending it, so the Peppol file is made again. Check the new setting "Embed the PDF in the Peppol file".

## 2.10.3

- **Dolibarr 22 or newer is recommended.** The minimum stays Dolibarr 18.0, so nothing stops working, but on Dolibarr 18 up to 21 the setup page and the About page show a notice: those versions get no security updates any more and are not tested with PHP 8.4 or 8.5.
- The automatic checks also check the PHP syntax on PHP 8.5.
- README: requirements with the recommended version.
- Test of the version check.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.10.2

License made explicit, no change in behaviour:

- Every source file of the module (PHP, JavaScript, SQL, shell) carries `SPDX-License-Identifier: GPL-3.0-or-later`, the license of the original module.
- New file `NOTICE`: original author, the license, and the included libraries with their own licenses. The changes of this fork (since 2.3.0) are dedicated to the public domain under CC0 1.0: no copyright is claimed on them. The module as a whole stays under GPL-3.0-or-later because it includes the original work.
- `composer.json` states the license.
- README: section License; the optional private checks are described as optional (the module does not use anything from another repository).
- Comments that referred to a document that is not public now say what they mean.

**After updating:** nothing; replacing the files is enough.

## 2.10.1

About page (setup, tab "About") brought up to date:

- Shows the version of the module, with a warning when the activated version differs (module not reactivated after an update), and the link to the repository.
- New section "What is new": the five most recent versions of this changelog.
- The README below it lists the features added since 2.6 (receiving and booking, Peppol candidates, automatic sending, setup) in French, Dutch, German and English.
- New function `peppolChangelogExcerpt()` with a test.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.10.0

More control on the setup page (gear icon), new block "Booking incoming invoices" and "Automatic tasks":

- **Booked invoices:** validate when the total equals the total of the e-invoice (default, as in 2.9.0), or always keep as draft (`PEPPOL_BOOK_MODE`).
- **Create unknown suppliers:** yes (default) or no (`PEPPOL_BOOK_CREATE_SUPPLIER`); with no, an invoice of an unknown supplier is not booked.
- **Complete known third parties:** store the Peppol ID and make the third party a supplier, yes (default) or no (`PEPPOL_BOOK_UPDATE_SUPPLIER`).
- **Fetch incoming documents every day** and **weekly check in the Peppol directory:** switched on and off here (the scheduled jobs are enabled or disabled).
- **Send customer invoices automatically** (new scheduled job "Peppol: automatic sending of validated customer invoices", `PeppolAutoSend::runAutoSend()`), **off by default**:
  - At the hours set up (`PEPPOL_AUTO_SEND_HOURS`: 9 for once a day, 9,13,17 for three times); the job runs every hour and sends once per hour of sending.
  - Only validated invoices of customers with a Peppol ID, not private, not sent yet, and validated after the sending was switched on (`PEPPOL_AUTO_SEND_SINCE`): older invoices are never sent automatically.
  - At most `PEPPOL_AUTO_SEND_MAX` (default 20) per hour of sending. The Peppol file is made with the PDF when it is missing.
  - The user of the job needs the rights Peppol: write and create invoices. Result and log hold counts only.
  - In test mode the setup page warns that invoices go to the test access point.
- Link to the scheduled jobs of the module; a warning when the module "Scheduled jobs" is off or a job does not exist yet.
- The choices for the scheduled jobs are kept when the module is deactivated and reactivated.
- Tests of the hours of sending and of the scheduled job definitions.

**After updating:** replace the module files, then deactivate and reactivate the module once so the new scheduled job is added (settings and data are kept); flush memcached. Automatic sending stays off until it is switched on in the setup.

## 2.9.0

Book incoming documents as supplier invoice in the module itself, without another module:

- The button **Book** (Billing > Peppol > Incoming documents, column Invoice, and on the card of a document) now books the e-invoice directly (`class/peppolbooking.class.php`). Version 2.8.0 needed the module PDF OCR Inkoop for this.
- Supplier: found by Peppol ID, VAT number or registration number (KvK), only when exactly one third party matches. Not found: created from the e-invoice (name, address, VAT number, registration number, country, Peppol ID) with the right to create third parties. A found third party gets its Peppol ID when it had none, and becomes a supplier when it was not one.
- Invoice: supplier invoice number, invoice date, due date, the lines (quantity, net price, VAT rate) and the allowances and charges on the whole invoice; a note with the document and the IBAN.
- Validated when the total equals the total in the e-invoice (rounding difference up to 0.02) and the user may validate supplier invoices; otherwise it stays a draft and the message says why.
- The PDF from the e-invoice and the XML are attached to the invoice; the document is linked to the invoice and gets the status "Success".
- Not booked automatically, with a message: credit notes, another currency, no invoice number, date or lines, several matching third parties. An existing invoice with the same supplier and number is linked instead of booked twice.
- Only when a user clicks; never by the scheduled job. The log holds ids and counts only.
- Rights: import Peppol documents and create supplier invoices; validating follows the rights of Dolibarr.
- Tests of reading the e-invoice (`PeppolBookingTest`, made-up invoice).

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed. Try the button first with one document and check the invoice.

## 2.8.0

Book an incoming document as supplier invoice in one click:

- With the module PDF OCR Inkoop installed (4.53.0 or newer), Billing > Peppol > Incoming documents shows a button **Book** in the column Invoice of every document that is not booked yet, and on the card of the document. It opens PDF OCR Inkoop with the XML of the document loaded: the PDF from the XML is shown, the data of the XML (supplier, VAT number, registration number, invoice number, dates, IBAN, amount, VAT rate) are filled in. After checking, **Save** there creates the supplier invoice and validates it; the document is then linked to the invoice and gets the status "Success".
- Only for users with the rights to import Peppol documents, to create supplier invoices and the write right of PDF OCR Inkoop; only when the file of the document exists.
- The button "Manual import" of the original module is only shown when its page (`importinvoice.php`) exists; it is not part of this fork, so the button led to a missing page.
- New function `peppolBookingUrl()` with a test.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed. Update PDF OCR Inkoop to 4.53.0 for the button.

## 2.7.0

Daily fetch of incoming documents:

- New scheduled job "Peppol: daily fetch of incoming documents from the access point" (`PeppolInboxFetch::runDailyFetch()`, once a day, enabled). It does what the button "Refresh" on Billing > Peppol > Incoming documents does: fetch the new documents and confirm them at the access point. It never creates, validates or pays a supplier invoice.
- The user of the job needs the right "Peppol: write"; without it the job stops with a clear message.
- A document that is already known but still unconfirmed at the access point (an earlier download or confirmation failed) is downloaded and confirmed again instead of being skipped for good.
- The time of the last successful contact with the access point is shown on the list of incoming documents (`PEPPOL_INBOX_LAST_FETCH`, per entity).
- The job result and the log lines hold counts only: no names, no Peppol IDs, no amounts.
- The message id of the access point is URL-encoded in the request; sender and date from the access point are escaped in the screen message.
- Tests of the daily fetch and of the scheduled job definitions.

**After updating:** replace the module files, then deactivate and reactivate the module once so the new scheduled job is added (settings and data are kept); flush memcached. Check under Tools > Scheduled jobs that the job is enabled and runs with a user that has the right "Peppol: write".

## 2.6.5

One menu "Peppol" in the left menu of Billing, with the pages of the module under it, instead of separate entries in several menus:

- **Peppol** (overview page `index.php`: the pages of the module with a short description; the number of new candidates is shown next to "Peppol candidates").
  - **Incoming documents** (`peppolimport_list.php`, right "import").
  - **Peppol candidates** (`peppol_candidates.php`, right "read" and the right to read third parties).
  - **Peppol log** (`peppol_list.php`, right "import").
- The old entries under Supplier invoices, Third parties and Tools are removed. The menu entry under Tools used a language key that did not exist.
- New language keys for the menu and the overview page in en_US, nl_NL, nl_BE and fr_FR.

**After updating:** replace the module files, then deactivate and reactivate the module once so the menu entries are replaced (settings and data are kept); flush memcached.

## 2.6.4

Automatic checks on GitHub (`.github/workflows/checks.yml`) on every push and pull request, as in DoliMods and `dolibarr-module`:

- PHP syntax on PHP 7.1, 7.4, 8.1, 8.2, 8.3 and 8.4 (the module and the einvoicing library).
- Code standard: `phpcs` with the ruleset of DoliCloud/DoliMods (cloned during the check, not copied); no `var_dump`/`print_r`; no functions deprecated in PHP 8.5.
- PHPStan against Dolibarr 18.0 and 24.0.2, with the settings of Dolibarr itself (level 10) and a baseline for the existing code (`dev/phpstan/baseline.php`, merged from both versions with `dev/phpstan/merge-baselines.php`).
- Phan with the SQL injection plugin of Dolibarr 24.0.2 (`dev/phan/config.php`).
- PHPUnit against Dolibarr 18.0 and 24.0.2 (`test/phpunit`): directory search terms and matching, scheme labels, webhook receiver check, way back after the search, allowed hosts of `peppolApiRequest()`, the weekly candidate check (exact matches only, no duplicates, nothing stored when the directory is down, retry after HTTP 429), the update check and the permission ids.
- Versions (`dev/tools/check-versions.php`), gitleaks, actionlint, sqlfluff (`.sqlfluff`) and ShellCheck.
- The security and language checks of `dolibarr-module`, only when the secret `DOLIBARR_MODULE_TOKEN` is set.

Code changes found by these checks:

- Comment blocks of every hook and function completed (`@param`, `@return`); French comments in English. One comment named the wrong parameter (since 2.6.2).
- `\DoliDB` in the comments of namespaced code (it pointed at a class that does not exist).
- SQL: two ids cast in `Peppolimport::validate()`, entity cast and names escaped in the module init, the column name of a date filter checked; `accepts_invoice` written at the place itself.
- The weekly check returns the technical reason of an error with its result; one retry after HTTP 429 also when no wait time is set.
- `peppolWebhookReceiverMatches()` moved from `public/hook.php` to `lib/peppol.lib.php` (testable); `checkForUpdate()` reads the version through `fetchLastVersion()`.
- No `curl_close()` any more (deprecated in PHP 8.5, no effect since 8.0).
- Two menu entries use `isModEnabled("peppolpeppyrus")`.
- SQL files: explicit table aliases, final newline.
- The executable bit is removed from 155 files that are not scripts (PHP, language, SQL, image and library files); only the two shell scripts in `dev/translation/` stay executable.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.6.3

Standard of the repository `dolibarr-module`, part 2:

- Translations as in DoliMods: `langs/en_US` is the source, the other languages are synchronised with Transifex (`.tx/config`, `dev/translation/txpush.sh` and `txpull.sh`, adapted from DoliMods). The existing translations stay as the starting point.
- Update check: the module list shows when a newer version exists. `checkForUpdate()` reads `VERSION` from the public repository over HTTPS, without redirects, and only accepts HTTP status 200 (the built-in check of Dolibarr would also take a short error page as version number). `editor_url` points to the repository.
- README: sections *Translations*, *Update check* and *Standard and deliberate deviations* (oldest Dolibarr 18, languages via Transifex, module number 436181 kept, CHANGELOG in English, own repository).
- `.gitattributes`: `.tx/` and `dev/` are left out of the installation package.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.6.2

Brought in line with the standard of the repository `dolibarr-module` (STANDAARD.md), part 1 (no change in behaviour for the user):

- Rights: every check uses `$user->hasRight()` instead of `$user->rights->...`, also the menu entries and the import pages (4.2). Permission ids are written out as `<numero>01` to `<numero>04`: the same numbers as before, granted rights stay.
- SQL: every value is cast or escaped at the place in the query itself (4.7). `Peppolimport::fetchAll()` only accepts a plain column name (`t.<column>`), `AND` or `OR` as filter mode, and casts `t.rowid`.
- Webhook (`public/hook.php`): the two deliberate exceptions are recorded in the code with their reason (4.8, 4.5). `NOCSRFCHECK` because the access point calls without a Dolibarr session; instead every request is checked with the secret key and the HMAC-SHA256 signature of the body (hash_equals), the module must be enabled and the receiver must be the Peppol ID of the entity; without a secret key every request is refused (since 2.3.0). `$_GET`/`$_POST` for the entity before `main.inc.php` is loaded, used only as an integer.
- Long actions (4.22): "Check now", the Peppol ID search and "Refresh" of the Peppyrus documents release the session while they wait for the Peppol directory or the access point (`peppol_sessie_los()` / `peppol_sessie_terug()`), so other pages of the user do not wait. "Check now" checks 10 third parties per click (`PEPPOL_CANDIDATE_BATCH_WEB`).
- Language files: the texts that were only in `nl_NL` are now also in `en_US` (the source); module name, description and permissions (`Module436181Name`, `Module436181Desc`, `Permission43618101` to `04`) in `en_US` and `nl_NL`.
- Code style: tabs instead of spaces, PSR-12 (`phpcs` with the ruleset of `dolibarr-module`); three private methods renamed to camelCase.
- New file `VERSION`.

**After updating:** replace the module files and flush memcached; deactivating and reactivating is not needed.

## 2.6.1

- Peppol candidates are also in the menu Billing > Supplier invoices, next to "Documents available at Peppyrus", and as a button with the number of new candidates in the title bar of that list and of the Peppol log.
- Weekly check: 1.1 s between two requests to the Peppol directory (was 0.3 s; the directory limits requests per second), one retry after HTTP 429. The technical reason of a directory error (HTTP code or curl error number, never a search term) is shown in the job output and the log.
- The job output stays readable when the translation is not available (for example a stale language cache in memcached after an update).

**After updating:** deactivate and reactivate the module once (menu entries). If texts show as keys such as `peppolCandidateCheckResult`, flush memcached.

## 2.6.0

New page **Third parties > Peppol candidates** (`peppol_candidates.php`): customers and suppliers without a Peppol ID that were found in the Peppol directory.

- A weekly scheduled job (`PeppolCandidateCheck::runWeeklyCheck`, class `class/peppolcandidatecheck.class.php`) checks active customers and suppliers without a Peppol ID that have a VAT number or professional ID 1 (KvK number in the Netherlands). Private customers are skipped. It searches by number only, never by name, and keeps only directory entries whose number is exactly the number of the third party. A third party is checked at most once every 6 days; up to `PEPPOL_CANDIDATE_BATCH` (default 500) per run. When the directory cannot be reached the job stops and the remaining third parties are checked next time.
- The page shows per candidate: third party, customer/supplier, Peppol ID with the kind of number, name in the directory, whether it accepts invoices, which number matches, date found, status, and a "Recommended" mark. Filters: customer/supplier, status (new, linked, ignored), found since.
- Select candidates and **Link** (stores the Peppol ID on the third party, one ID per third party, only the `peppol_id` extra field is updated) or **Ignore**. **Check now** checks a small batch (`PEPPOL_CANDIDATE_BATCH_WEB`, default 25) at once.
- Rights: viewing needs the module read right and the third party read right; linking, ignoring and checking need the module write right and the third party write right. Sales representatives without "see all third parties" only see their own third parties.
- Requests to the Peppol directory (also the existing search) go through `peppolApiRequest()`: HTTPS only, and the search term no longer ends up in the Dolibarr log. Log lines of the check hold counts only.
- New tables `llx_peppol_candidate` and `llx_peppol_lookup`.

**After updating:** deactivate and reactivate the module once (creates the tables, the menu entry and the scheduled job). The weekly job needs the module "Scheduled jobs" (cron) and the Dolibarr cron script running on the server.

## 2.5.0

Security:

- The Peppyrus API key no longer ends up in the Dolibarr log. All calls to Peppyrus went through `getURLContent()`, which logs the request headers it sent (Dolibarr 24: "request without content body"), including `X-Api-Key`. They now go through `peppolApiRequest()` (`lib/peppol.lib.php`): curl with HTTPS and certificate verification, only to `api.peppyrus.be` and `api.test.peppyrus.be`, no redirects (the key would go along), the proxy settings of Dolibarr, and in the log only method, host and HTTP code.

VAT category of invoice lines at 0% VAT:

- New setting "VAT on 0% lines (exempt supplies)" (`PEPPOL_VAT_EXEMPT_CODE`): the exemption reason sent with VAT category **E** (exempt), default `VATEX-EU-132-1C` (medical care by medical professions, for example district nursing); `VATEX-EU-132-1G` for home help and other welfare work. Before, such lines got category **O** (not subject to VAT) and the VAT numbers of seller and buyer were removed, which is wrong for exempt care. Choose "Not subject to VAT" for the old behaviour.
- Category **K** (intra-community supply) is only used for a VAT registered buyer in another EU member state. Before, every VAT registered buyer got K, also a buyer in the same country.
- Reverse charge (**AE**) used an undefined variable (PHP warning) and only looked at the customer; it now also uses the reverse charge setting of the invoice when the Dolibarr version has it. The reason text read "Reverse Carge" and now reads "Reverse charge".
- Tested on Dolibarr 24: Dutch customer (E with VATEX-EU-132-1C, seller VAT number kept), Belgian customer (K), reverse charge customer (AE) and the old behaviour (O).

**After updating:** check the setting on the setup page; nothing else.

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

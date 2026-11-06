# Module PEPPOL pour Dolibarr / PEPPOL Module for Dolibarr

---

## Fran&ccedil;ais

Ce module permet d'envoyer des factures électroniques via le réseau PEPPOL en utilisant le point d'accès Peppyrus.

**Peppyrus** est un des points d'accès PEPPOL disponibles en Belgique. Il est actuellement **gratuit** à utiliser.

### Fonctionnalités principales :
- Génération automatique de fichiers XML conformes à la norme PEPPOL
- Envoi des factures via Peppyrus
- Suivi du statut de livraison des documents
- Recherche dans l'annuaire PEPPOL
- Détection automatique de l'identifiant PEPPOL par numéro de TVA

### Liens :
- Peppyrus : [https://www.peppyrus.be](https://www.peppyrus.be)
- API Peppyrus : [https://api.peppyrus.be/v1](https://api.peppyrus.be/v1)

---

## Nederlands

Deze module maakt het mogelijk om elektronische facturen te verzenden via het PEPPOL-netwerk met behulp van het Peppyrus-toegangspunt.

**Peppyrus** is één van de beschikbare PEPPOL-toegangspunten in België. Het is momenteel **gratis** te gebruiken.

### Belangrijkste functies:
- Automatische generatie van XML-bestanden conform de PEPPOL-standaard
- Verzending van facturen via Peppyrus
- Tracking van de leveringsstatus van documenten
- Zoeken in de PEPPOL-directory
- Automatische detectie van PEPPOL-ID op basis van BTW-nummer

### Links:
- Peppyrus: [https://www.peppyrus.be](https://www.peppyrus.be)
- Peppyrus API: [https://api.peppyrus.be/v1](https://api.peppyrus.be/v1)

---

## Deutsch

Dieses Modul ermöglicht das Versenden elektronischer Rechnungen über das PEPPOL-Netzwerk unter Verwendung des Peppyrus-Zugangspunkts.

**Peppyrus** ist einer der verfügbaren PEPPOL-Zugangspunkte in Belgien. Die Nutzung ist derzeit **kostenlos**.

### Hauptfunktionen:
- Automatische Generierung von XML-Dateien gemäß dem PEPPOL-Standard
- Versand von Rechnungen über Peppyrus
- Verfolgung des Lieferstatus von Dokumenten
- Suche im PEPPOL-Verzeichnis
- Automatische Erkennung der PEPPOL-ID anhand der USt-IdNr.

### Links:
- Peppyrus: [https://www.peppyrus.be](https://www.peppyrus.be)
- Peppyrus API: [https://api.peppyrus.be/v1](https://api.peppyrus.be/v1)

---

## English

This module allows sending electronic invoices via the PEPPOL network using the Peppyrus access point.

**Peppyrus** is one of the available PEPPOL access points in Belgium. It is currently **free** to use.

### Main features:
- Automatic generation of XML files compliant with the PEPPOL standard
- Sending invoices via Peppyrus
- Tracking document delivery status
- Search in the PEPPOL directory
- Automatic PEPPOL ID detection by VAT number

### Links:
- Peppyrus: [https://www.peppyrus.be](https://www.peppyrus.be)
- Peppyrus API: [https://api.peppyrus.be/v1](https://api.peppyrus.be/v1)

---

## Avertissement / Disclaimer / Haftungsausschluss

**FR** — Ce module est un logiciel libre fourni « tel quel », sans aucune garantie (licence GPL v3, articles 15 et 16). Son auteur n'a aucun lien avec Peppyrus, OpenPeppol ni aucune administration. Vous restez seul responsable de vos documents, de leurs montants et de la TVA, et de la vérification de leur envoi et de leur réception ; testez d'abord et sauvegardez vos données. Dans les limites permises par la loi, l'auteur et les contributeurs déclinent toute responsabilité pour tout dommage direct ou indirect (perte financière, pénalités, documents rejetés ou perdus, perte de données…). Un administrateur doit accepter cet avertissement dans la configuration du module avant tout envoi ou mise à jour.

**NL** — Deze module is vrije software die "zoals ze is" wordt geleverd, zonder enige garantie (GPL v3, artikelen 15 en 16). De auteur is niet verbonden aan Peppyrus, OpenPeppol of enige administratie. U blijft als enige verantwoordelijk voor uw documenten, hun bedragen en btw, en voor de controle van hun verzending en ontvangst; test eerst en maak back-ups. Voor zover wettelijk toegestaan aanvaarden de auteur en bijdragers geen aansprakelijkheid voor directe of indirecte schade (financieel verlies, boetes, geweigerde of verloren documenten, gegevensverlies…). Een beheerder moet deze disclaimer in de module-instellingen aanvaarden vóór elke verzending of update.

**DE** — Dieses Modul ist freie Software und wird „wie besehen“ ohne jede Gewährleistung bereitgestellt (GPL v3, Abschnitte 15 und 16). Der Autor steht in keiner Verbindung zu Peppyrus, OpenPeppol oder einer Verwaltung. Sie bleiben allein verantwortlich für Ihre Dokumente, deren Beträge und Mehrwertsteuer sowie für die Prüfung von Versand und Empfang; testen Sie zuerst und sichern Sie Ihre Daten. Soweit gesetzlich zulässig, übernehmen der Autor und die Mitwirkenden keine Haftung für direkte oder indirekte Schäden (finanzielle Verluste, Strafen, abgelehnte oder verlorene Dokumente, Datenverlust…). Ein Administrator muss diesen Hinweis in der Modulkonfiguration akzeptieren, bevor Dokumente versendet oder das Modul aktualisiert wird.

**EN** — This module is free software provided "as is", without any warranty (GPL v3, sections 15 and 16). Its author is not affiliated with Peppyrus, OpenPeppol or any administration. You remain solely responsible for your documents, their amounts and VAT, and for checking that they are sent and received; test first and keep backups. To the extent permitted by law, the author and contributors accept no liability for any direct or indirect damage (financial loss, penalties, rejected or lost documents, data loss...). An administrator must accept this disclaimer in the module setup before anything is sent or the module is updated.

Nothing in this notice excludes or limits a liability that cannot be excluded or limited by law.

---

# Technical Documentation

## Requirements

- Dolibarr 14.0 or higher
- PHP 7.4 or higher
- A Peppyrus account (free registration at [peppyrus.be](https://www.peppyrus.be))

## Installation

1. Download or clone this repository
2. Copy the `peppol` folder to your Dolibarr `htdocs/custom/` directory
3. Enable the module in Dolibarr: Home > Setup > Modules > Peppol
4. Configure your Peppyrus credentials in the module settings

## Configuration

1. **Peppol ID**: Your company's Peppol identifier (format: 0208:0000000097)
2. **API Key**: Your Peppyrus API key (provided by Peppyrus)
3. **Production Mode**: Switch between test and production environments

### Optional settings:
- **Force XML with VAT null**: Generate XML even for customers without VAT number
- **Enable API trigger**: Automatically generate Peppol XML when creating invoices via Dolibarr API

### Security notes
- The API keys are never displayed again once saved; leave the field empty to keep the stored key.
- Dolibarr writes the headers of outgoing HTTP requests (including the API key header) to `dolibarr.log` when the log level is INFO or DEBUG. Keep the log level at WARNING or above in production.
- Rights: sending requires the module *write* right or the right to create or send customer invoices; incoming documents require the *Import Peppol invoices* right.
- The webhook (`public/hook.php`) is disabled until the constant `PEPPOL_AP_WEBHOOK_KEY` is set (HMAC-SHA256 of the body).

### Updates
The **Updates** tab of the module setup shows the installed version and the latest version published on GitHub, and installs it in one click (administrators only; superadministrator with multicompany).
- Channel: stable releases only, or also the beta pre-releases (checkbox).
- The check runs only when an administrator opens the tab (at most once a day, the result is cached) or clicks *Check now*; it never runs during normal use. Nothing is installed without an administrator's confirmation.
- Every release ZIP is signed with the maintainer's Ed25519 key (asset `<zip>.sig`, message `peppolpeppyrus|<tag>|<sha256 of the zip>`). The public key is embedded in the module and published in `release-signing-key.pub`. An unsigned or wrongly signed ZIP, a ZIP with unsafe paths or links, or an older version is refused.
- The installed version is backed up in `documents/peppolpeppyrus_update/` (not reachable from the web) and restored automatically if the installation fails.
- Not available when the module folder is a git checkout, when `installmodules.lock` exists in the Dolibarr data directory, or when the web server cannot write in the module folder. Releases published before 2.3.0 are not signed: install the first signed version by hand.
- Signed manifest: `update-manifest.json` (+ `.sig`), attached to the newest release, names the latest stable and beta versions with the SHA-256 of their exact ZIP, and expires after 7 days (it is signed again every day from the maintainer's local records, never from what GitHub shows). Only the exact ZIP it names is installed; a missing or expired manifest, or one older than a manifest already seen, is reported as an error. Hiding the newest release or offering an older signed one therefore blocks the update instead of installing an outdated version.
- The settings of the updater are global (entity 0): with multicompany, only the superadministrator can change them.
- Self-update requires the web server to be able to write in the module folder (as Dolibarr's own module installer does). If you prefer read-only code, keep the folder read-only and install the ZIP by hand: the tab then only reports new versions.
- Verify a release by hand:
  ```sh
  printf 'peppolpeppyrus|%s|%s' vX.Y.Z "$(sha256sum module_peppolpeppyrus-X.Y.Z.zip | cut -c1-64)" > msg
  base64 -d module_peppolpeppyrus-X.Y.Z.zip.sig > sig.bin
  openssl pkeyutl -verify -pubin -inkey release-signing-key.pub -rawin -in msg -sigfile sig.bin
  ```

## API Endpoints

- **Test**: `https://api.test.peppyrus.be/v1/`
- **Production**: `https://api.peppyrus.be/v1/`

## Source Code

This plugin is available on GitHub:
[https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus](https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus)

## License

GPLv3 or later.

## Support

For issues and feature requests, please use the GitHub issue tracker:
[https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus/issues](https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus/issues)

---

# Development / Testing Environment

## Test Environment (DEV)

Peppyrus provides a separate test environment for development and testing purposes.

### Test API

- **Test API URL**: `https://api.test.peppyrus.be/v1/`
- **Test Portal**: `https://customer.test.peppyrus.be/`

### Getting a Test API Key

1. Contact Peppyrus to request a test/development API key
2. The test API key is different from the production key
3. Configure both keys in the module settings:
   - **PEPPOL_AP_API_KEY**: Production API key
   - **PEPPOL_AP_API_KEY_DEV**: Test/Development API key

### Configuration for Testing

In the module settings (`Configuration > Modules > Peppol-Peppyrus > Setup`):

1. Set **Mode production** to `No` (unchecked)
2. Enter your **Test API Key** in the DEV field
3. Enter your **Peppol Sender ID** (your company's Peppol ID)

### Important: Test Environment Limitations

**The test environment is isolated from the production PEPPOL network.**

This means:

1. **Recipients must also be registered in the test environment**
   - You cannot send test invoices to real PEPPOL participants
   - Only participants registered with Peppyrus TEST can receive test invoices
   - For testing, send invoices **to yourself** (use your own Peppol ID as recipient)

2. **Directory lookups may not find real participants**
   - The test directory only contains test participants
   - A "participant not found" warning is normal in test mode

3. **Messages sent in test mode**
   - Are visible in your test inbox at `https://customer.test.peppyrus.be/`
   - Do NOT reach real recipients on the production PEPPOL network
   - Invoices sent to non-test participants will appear in the "failed" folder

### Testing Workflow

1. **Self-testing**: Send an invoice to your own Peppol ID
   - The invoice will appear in your OUTBOX (sent) and INBOX (received)
   - This validates the complete send/receive flow

2. **Check delivery status**: Use the "Peppol: Status" button on the invoice
   - `confirmed: true` = Successfully delivered
   - `folder: failed` = Delivery failed (recipient not in test network)

3. **View messages**: Check your test portal inbox
   - `https://customer.test.peppyrus.be/customer/[your-account]/message/inbox`

### Switching to Production

When ready to go live:

1. Set **Mode production** to `Yes` (checked)
2. Ensure your **Production API Key** is configured
3. Verify your company is registered in the production PEPPOL network
4. Test with a real invoice to a known PEPPOL participant

### Troubleshooting Test Mode

| Issue | Cause | Solution |
|-------|-------|----------|
| "Recipient not found" warning | Normal in test mode | Ignore warning, invoice still sent |
| Invoice in "failed" folder | Recipient not in test network | Send to yourself for testing |
| "API key invalid" error | Using wrong key for environment | Check DEV key configuration |
| No XML generated | Customer type is "Private" | Change customer type to "Company" |

---

# Changelog

## Version 2.3.1
- Fix: a warning is shown after an update, in the Updates tab and on the invoice, when sending via Peppol stays disabled until an administrator completes the module setup (#25)
- Updates tab hardened: errors of a check are retried after 15 minutes, only the 3 newest backups are kept, a previous version that cannot be removed is made unreadable (#22)
- Fix: the test, script and documentation files of the bundled e-invoicing library are no longer shipped (#23)
- Fix: credit notes are now sent with positive quantities, prices and totals, as required for a Peppol CreditNote (type 381); version 2.2.3 produced a negative CreditedQuantity and negative totals (#11)
- Fix: line discount of a credit note computed from the absolute line total (the line amount matches Dolibarr)
- Fix: paid invoices and credit notes converted into a discount (status Paid) get their Peppol XML and the Peppol buttons again, as for sending by e-mail; drafts and abandoned documents stay excluded (#12)
- Fix: the PDF hook no longer returns an error for documents it does not handle (drafts, other objects): recurring invoices created as draft no longer fail and roll back (#4)
- Fix: the PDF/ODT hooks never report a failure to Dolibarr when the Peppol XML cannot be made (private customer, missing VAT number): the document itself is generated, the reason is shown as a warning (#4)
- Fix: the "Send via Peppol" and "Check" links carry the CSRF token, required by Dolibarr 24 (MAIN_SECURITY_CSRF_WITH_TOKEN = 3) (#5)
- Fix: sending now requires the answer "Yes" in the confirmation dialog, the module write right or the right to create or send invoices, and an eligible document (validated or paid); the send button is shown only to users allowed to send (#13)
- Fix: unit prices keep the precision stored by Dolibarr in the XML (up to 8 decimals, allowed by EN 16931 for the item net price), so the line amounts and the total without VAT are those of the invoice to the cent, e.g. for prices entered VAT included; rule R120 still holds (#15). The VAT is computed per rate as EN 16931 requires (BR-CO-17) and may differ by at most 1 cent per rate from the sum of the line VAT amounts shown by Dolibarr
- Fix: the PeppolFinder button of the third-party card opens the search in the same window, without the redundant tooltip; the search page has a Back link, and storing a Peppol ID requires a valid token and the right to modify third parties (#16)
- Security: access rights and CSRF protection hardened on the incoming documents pages, the Peppol ZIP export, the webhook (now disabled until a secret key is configured) and the Peppol ID search; an unused directory search page was removed. Details will be published in security advisories with the release
- Fix: the Peppol log is written again (each sending and each confirmation of an incoming document), so the Peppol tab of the invoice shows the history (#18)
- Fix: data received from the access point is escaped before display and encoded in URLs (#19)
- Fix: API keys are no longer displayed on the setup page; leave the field empty to keep a key, tick "Clear the key" to remove it (#20)
- Fix: the send action only handles customer invoices and their Peppol XML (#21)
- Fix: incoming documents only require the module right "Import Peppol invoices" (no more rights of other modules, see #3)
- Fix: webhook duplicate check used a wrong table name
- Fix: PHP warnings in the XML generation (VAT summary, reverse-charge flag)
- New: **Updates** tab: check and install new versions (stable, or beta when ticked) from signed GitHub releases, with backup and automatic restore; nothing is checked during normal use (#22)
- Fix: the developer scripts of the `scripts` folder refuse to run from the web (#23)
- New: regression tests, command line only: `php htdocs/custom/peppolpeppyrus/tests/run.php`

## Version 2.2.3
- Fix: BR-27 - Credit notes failed PEPPOL validation because BT-146 (Item net price) was negative
- Fix: Dolibarr credit note lines have negative unit price + positive qty; now normalized to positive price + negative qty as required by EN 16931
- Thanks to @IveWe for reporting this issue (#2)

## Version 2.2.2
- Fix: BR-CL-10 - Removed seller PartyIdentification with invalid schemeID (9925 is EAS, not ICD)
- Fix: PartyIdentification requires ICD codes, VAT scheme codes (99xx) are only valid for EndpointID
- Note: Seller VAT is already included via PartyTaxScheme/CompanyID element

## Version 2.2.1
- Fix: BR-CL-10 - Removed dead code that was overwriting schemeID with null (Luxembourg workaround from 2024-09)
- Fix: Simplified ElectronicAddress logic - custom Peppol ID (0208 scheme) now takes proper priority over VAT-based scheme (9925)
- Fix: Removed duplicate/confusing setElectronicAddress calls

## Version 2.2.0
- Fix: BR-CL-10 - Force fetch_optionals() to ensure custom Peppol ID is always loaded
- Fix: Ensure custom buyerIdent is used as final ElectronicAddress (was being overwritten)
- Fix: Added debug logging for troubleshooting Peppol ID issues
- Fix: Customers with enterprise number scheme (0208) instead of VAT scheme (9925) now work correctly

## Version 2.1.9
- Fix: BR-CL-10 - Custom Peppol ID (options_peppol_id) was not loaded when generating XML
- Fix: Added fetch_optionals() call to load extrafields after fetch_thirdparty()
- Fix: Customers with enterprise number scheme (0208) instead of VAT scheme (9925) now work correctly

## Version 2.1.8
- Fix: PeppolFinder now actually saves Peppol ID to customer record (was using update() instead of insertExtraFields())
- Fix: Both search.php and peppol_search_directory.php now correctly persist the extrafield

## Version 2.1.7
- Fix: PeppolFinder now correctly saves Peppol ID in format "schemeCode:value" (e.g., "0208:0000000097")
- Fix: BR-CL-10 - Peppol ID selected from directory search was saved without scheme code, causing invalid identifier error

## Version 2.1.6
- Fix: BR-CL-10 - EndpointID value now uppercase (was lowercase causing ISO 6523 ICD validation error)
- Fix: PEPPOL-EN16931-R120 - Complete fix for line amount calculation (v2.1.5 fix was incomplete)
- Fix: Price and allowance now use same rounded unit price for consistent Peppol validation

## Version 2.1.5
- Fix: PEPPOL-EN16931-R120 line amount rounding errors
- Fix: Use round() instead of peppolAmountToFloat() for line calculations to match Dolibarr totals exactly

## Version 2.1.4
- Fix: Force uppercase on VAT identifiers to comply with BR-CO-09 (ISO 3166-1 alpha-2 country prefix)
- Fix: Seller and Buyer VAT numbers now correctly formatted in XML output

## Version 2.1.3
- Refactor: Centralized API response handling with `handleApiResponse()` method
- Improvement: All HTTP error codes (200, 401, 404, 422) now properly handled in all API methods
- Improvement: JSON decode errors checked with `json_last_error()` in all methods
- Improvement: Better error messages with specific translations for each error type
- Improvement: CURL connection errors (timeout, network) now properly reported
- Fix: "Peppol Status" button now checks recipient in Peppol directory when invoice not yet sent
- Add: 20+ new translation keys for error messages (FR, EN)
- Code quality: Reduced code duplication across API methods

## Version 2.1.2
- Security: Sanitize input parameters to prevent XSS
- Improvement: Added fallback notification when PDF is not available from Access Point
- Improvement: Enhanced API error handling (JSON decoding)
- Improvement: Better validation feedback for 422 errors

## Version 2.1.1
- Fix: API keys containing numbers were being truncated when saved
- Fix: XML file not found when sending (added disk fallback)
- Fix: Namespace corrections for module rename
- Improvement: Better error messages for private customers
- Improvement: Warning instead of error for "recipient not found" in test mode

## Version 2.1.0
- Add: Support for DEV/TEST API key
- Add: Separate configuration for test and production environments

## Version 2.0.0
- Initial release for Peppyrus access point
- Renamed from original peppol module to peppolpeppyrus

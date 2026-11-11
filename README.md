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
- Réception : récupération quotidienne des documents entrants et comptabilisation en un clic comme facture fournisseur
- Candidats Peppol : contrôle hebdomadaire des clients et fournisseurs dans l'annuaire Peppol
- Envoi automatique des factures clients à heures fixes (désactivé par défaut)
- Tout se règle dans la configuration du module (roue dentée)

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
- Ontvangen: elke dag inkomende documenten ophalen en met één klik inboeken als inkoopfactuur
- Peppol-kandidaten: wekelijkse controle van klanten en leveranciers in de Peppol-directory
- Verkoopfacturen automatisch versturen op vaste tijdstippen (standaard uit)
- Alles in te stellen in de instellingen van de module (tandwiel)

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
- Empfang: tägliches Abholen eingehender Dokumente und Buchen als Lieferantenrechnung mit einem Klick
- Peppol-Kandidaten: wöchentliche Prüfung von Kunden und Lieferanten im Peppol-Verzeichnis
- Automatischer Versand von Kundenrechnungen zu festen Zeiten (standardmäßig aus)
- Alles einstellbar in der Modulkonfiguration (Zahnrad)

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
- Receiving: daily fetch of incoming documents, booked as supplier invoice in one click
- Peppol candidates: weekly check of customers and suppliers in the Peppol directory
- Automatic sending of customer invoices at set hours (off by default)
- Everything set up on the setup page of the module (gear icon)

### Links:
- Peppyrus: [https://www.peppyrus.be](https://www.peppyrus.be)
- Peppyrus API: [https://api.peppyrus.be/v1](https://api.peppyrus.be/v1)

---

# Technical Documentation

## Peppol candidates

Billing > Peppol > Peppol candidates lists customers and suppliers without a Peppol ID that a weekly scheduled job found in the Peppol directory by VAT number or registration number (never by name). Select the right ID and link it to the third party. Requires the module "Scheduled jobs" and the Dolibarr cron script on the server.

## Outgoing invoices

Billing > Peppol > Outgoing lists the sent customer invoices with their status at Peppyrus: one tick (waiting at Peppyrus), two ticks (delivered to the access point of the customer) or a red cross (not delivered). When the customer sends an Invoice Response, a third tick (received or accepted), a question mark (query) or a red cross (rejected by the customer). Requires the rights "Peppol: read" and to read invoices.

## Incoming documents

Billing > Peppol > Incoming documents shows by default what still has to be processed ("To process"); booked documents have status Success and rejected ones Canceled. The default filter is a setting.

## Reject or accept incoming invoices

On the card of an incoming document, **Reject** asks a reason and sends the supplier an Invoice Response (Peppol BIS 63); the document is then no longer booked. On **Book**, the supplier receives an approval, unless the setting "Send approval to the supplier on booking" is off.

## Daily check of failed deliveries

A daily scheduled job ("Peppol: daily check of invoices the access point could not deliver") reads the folder FAILED at Peppyrus. For each own invoice that could not be delivered, one line "failed" is added to the Peppol tab of the invoice and, with the agenda on, an event on the invoice. The invoice itself does not change. The user of the scheduled job needs the right "Peppol: read".

## Daily fetch of incoming documents

A daily scheduled job ("Peppol: daily fetch of incoming documents from the access point") fetches the new documents from the access point into Billing > Peppol > Incoming documents and confirms them at the access point, as the button "Refresh" on that page does. It does not create, validate or pay supplier invoices. A document whose download or confirmation failed stays unconfirmed at the access point and is tried again the next day. The user of the scheduled job needs the right "Peppol: write". The time of the last fetch is shown on the page. The job result and the log hold counts only. Requires the module "Scheduled jobs" and the Dolibarr cron script on the server.

## Book incoming documents

Billing > Peppol > Incoming documents has a button "Book" for every document that is not booked yet (also on the card of the document). One click books the e-invoice (UBL, Peppol BIS Billing 3.0) as supplier invoice, without other modules:

- The supplier is found by Peppol ID, VAT number or registration number (KvK); when it is not known it is created from the e-invoice (with the right to create third parties).
- The invoice gets the invoice number, dates, lines (quantity, price, VAT rate) and the allowances and charges of the e-invoice, and a note with the IBAN.
- It is validated when its total equals the total in the e-invoice and the user may validate supplier invoices; otherwise it stays a draft with the reason.
- The PDF from the e-invoice and the XML are attached to the invoice; the document is linked to the invoice.
- Not booked automatically: credit notes, invoices in another currency, and documents without number, date or lines. An invoice with the same supplier and number is linked instead of booked twice.

## Setup: booking and automatic tasks

The setup page of the module (gear icon) has a block for booking incoming invoices (validate when the total matches or always keep a draft; create unknown suppliers or not; complete known third parties or not) and for the automatic tasks: fetch incoming documents every day, the weekly check in the Peppol directory, and automatic sending of validated customer invoices at set hours (off by default; only invoices validated after switching it on, at most a set number per hour of sending). A link opens the scheduled jobs of the module.

## Requirements

- **Recommended: Dolibarr 22 or newer** with a PHP version that Dolibarr version supports (up to PHP 8.5 for Dolibarr 24). Older Dolibarr versions get no security updates any more and are not tested with PHP 8.4 or 8.5; the setup page shows a notice on them.
- Minimum: Dolibarr 18.0 (tested up to 24.0.2, see *Standard and deliberate deviations*) and PHP 7.1
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

## Translations

As in DoliMods: `langs/en_US/peppol.lang` is the source and the only language file edited by hand. Every other language is synchronised with Transifex (`.tx/config`):

- after adding or changing a key in `en_US`: `./dev/translation/txpush.sh source`
- to get the translations: `./dev/translation/txpull.sh all` (or one language, e.g. `nl_NL`)
- a local correction of a translation can be pushed with `./dev/translation/txpush.sh nl_NL`

The Transifex organisation and project in `.tx/config` must exist; the scripts need the Transifex client (`tx`). `.tx/` and `dev/` are not part of the installation package.

## Update check

The module list of Dolibarr shows when a newer version exists: `checkForUpdate()` reads `VERSION` from this repository (public, so no key) over HTTPS and only accepts an answer with HTTP status 200.

## Checks

Every push and pull request runs `.github/workflows/checks.yml`: PHP syntax (7.1 to 8.4), `phpcs` with the DoliMods ruleset, PHPStan and PHPUnit against Dolibarr 18.0 and 24.0.2, Phan (SQL injection), versions, gitleaks, actionlint, sqlfluff and ShellCheck. Locally, from the root:

```
git clone --depth 1 https://github.com/DoliCloud/DoliMods .dolimods     # once, for phpcs
phpcs
php dev/tools/check-versions.php
DOLIBARR_HTDOCS=/path/to/dolibarr/htdocs phpunit
DOLIBARR_HTDOCS=/path/to/dolibarr/htdocs phpstan analyse
DOLIBARR_HTDOCS=/path/to/dolibarr-24/htdocs php phan.phar --config-file dev/phan/config.php
sqlfluff lint sql/
```

Optional, for the maintainer of this fork only: extra security and language checks from a private repository run when the secret `DOLIBARR_MODULE_TOKEN` is set. Without it they are skipped with a notice; all other checks above are public and enough for contributors. The module itself does not use anything from that repository.

## Standard and deliberate deviations

The module follows the rules of DoliMods (code standard, checks above) and an internal security guideline of the maintainer (rights and entity checks, CSRF tokens, escaping, no secrets or personal data in logs, long actions do not block the browser). Comments `beveiliging-uitzondering` mark the reviewed security exception (one SQL filter in `class/peppolimport.class.php`).

Deliberate deviations from that internal guideline, because the module is public and also used by the original author:

| Standard | Here | Reason |
| --- | --- | --- |
| Oldest supported Dolibarr 22.0, PHP 8.4 | Dolibarr 18.0 up to 24.0.2, PHP 7.1+ | Supported range of this module; every function used exists in Dolibarr 18 (checked against its source). |
| Only `en_US` and `nl_NL`, both by hand | `en_US` by hand, other languages via Transifex | Same as DoliMods; the module is used in several countries. |
| Module number between 500000 and 599999 | 436181 | Changing it would remove every granted Peppol permission (Dolibarr stores them by number), also for other users of the module. Only changed if it ever conflicts with another module. |
| CHANGELOG and documentation in Dutch | English | Readable for everyone who uses or reviews the module. Each version has an **After updating** line. |
| Module in a private repository | Own public repository | Fork of the module of the original author under the GPL; changes are offered back. |
| `checkForUpdate()` with a GitHub key | Without key | The repository is public. |

## API Endpoints

- **Test**: `https://api.test.peppyrus.be/v1/`
- **Production**: `https://api.peppyrus.be/v1/`

## Source Code

Original module: [https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus](https://github.com/cedric-marcoux/dolibarr_peppol-peppyrus)

This fork (with the changes in `CHANGELOG.md`): [https://github.com/RealRidrick/dolibarr_peppol-peppyrus](https://github.com/RealRidrick/dolibarr_peppol-peppyrus)

## License

GPL-3.0-or-later, the same license as the original module (see `COPYING` and `NOTICE`).

- The original module is by Cedric Marcoux; this fork adds the changes listed in `CHANGELOG.md` since version 2.3.0.
- The changes of this fork are dedicated to the public domain (CC0 1.0): no copyright is claimed on them, so anyone may use, change and improve them without conditions.
- The module as a whole stays under GPL-3.0-or-later, because it includes the original work. Each source file carries `SPDX-License-Identifier: GPL-3.0-or-later`.
- The module is complete as published here: it does not use or need code from any other module or private repository.
- Changes are offered back to the original module; anyone may use, change and share the module under the GPL.
- Included libraries keep their own licenses (`vendor/*/LICENSE`), for example `josemmo/einvoicing` (MIT).

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
   - **PEPPOL_AP_DEV_API_KEY**: Test/Development API key (formerly PEPPOL_AP_API_KEY_DEV, still read until saved again)
   - **PEPPOL_VAT_EXEMPT_CODE**: VAT category of invoice lines at 0% VAT that are not reverse charge and not an intra-community supply: category E with this exemption reason (default VATEX-EU-132-1C, medical care), or "Not subject to VAT" for category O

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
- Fix: PeppolFinder now correctly saves Peppol ID in format "schemeCode:value" (e.g., "0208:0475670182")
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

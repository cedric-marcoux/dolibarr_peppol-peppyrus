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

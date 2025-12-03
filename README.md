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

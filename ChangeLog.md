# CHANGELOG PEPPOL FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.2.44 -- 2025-12-03

Update check on admin / setup page to avoid error

## 1.2.43 -- 2025-11-20

Enhance codabox support (waiting for early users to try it)
Try to fix a race condition on line unit price & quantities

## 1.2.42 -- 2025-11-17

Avoid duplicates entries on files to import into dolibarr from peppol AP
Add a check on XML files without PDF embedded part

## 1.2.41 -- 2025-11-05

Handle PDF invoices files mades from ODT templates
Add/Update Peppol tracking id into invoices extrafield after sent
Import invoices thanks to ScanInvoices (need scaninvoices version >= 1.4.76)
Update peppol id on customer invoice when sending invoice (peppyrus)
Disable Send button in case of peppol id set

## 1.2.40 -- 2025-10-31

Peppyrus send and get ok >> import to become
Fix PeppolFinder target (module must be installed into /custom)
Download xml & pdf from peppyrus ok, display pdf into dolibarr ok


## 1.2.37 -- 2025-10-24

New peppyrus AP implementation started

## 1.2.36 -- 2025-10-23

Add a PeppolFinder button on thirdparty
Do not build xml on non validated invoice
Use dol_sanitizeFileName on invoice ref to build xml file name & path
Fix Tax Category O MUST be used when exemption reason code is VATEX-EU-O
Fix do not add payment data if one key is empty
New translation in nl (be) thanks to Patrick De Lange
More debug to find some race conditions (vat cases)
Fix error in case of no address of customer

## 1.2.30 -- 2025-10-17

Put logs into database (next step will be to display that list)
New entry in tools / peppol_list.php to get all history / list
Better log collect
Add a new button on thirdpart card to search peppol id on directory (only on vat number for the moment)

## 1.2.27 -- 2025-10-11

Fix error on peppol_tab
Better support for non-vat situation

## 1.2.25 -- 2025-10-10

Big step with e-invoice.be ready for tests !
Update fix for invoices of private people (without vat) and force peppol file option is checked
Better test against validy : check bank account informations only if payment is VIR
Better non vat support : private people and non_vat specific thirdpart

## 1.2.23 -- 2025-10-02

Massive code cleanup and factorizing
Add new AP Peppol in the list: acube, billit, e-invoice.be et iopole
Start of webhook implementation (debug mode required to help developper to do the job)
Fix dolibarr 20+ icons (font awesome 5) - refresh supplier invoices


## 1.2.18 -- 2025-09-29

Fix EndpointID of AccountingSupplierParty (inverted)
Fix Content type on checkThirdparty
Change setup to make dynamic list of Peppol AP then people have only to implement an ap-xxx.class.php file
Better PEPPOL_FORCE_XML_WITH_VATNULL solution for private people customers

## 1.2.14 -- 2025-09-09

Add download invoices from Scrada Peppol AP

## 1.2.12 -- 2025-07-29

Add support of dolibarr 20/21 on auto build tests
Fix peppol seller id priority if set
Fix sql init on non llx_prefix tables
Add a new card for dedicated tracking data from peppol AP


## 1.2.6 -- 2025-06-02

Better messages on billing and delivery addresses
Better hook handle for dolibarr 19+

## 1.2.4 -- 2025-05-23

Add Buyer electronic address even if buyerIdent is set

## 1.2.3 -- 2025-05-22

Fix peppol id displayed on invoices (read only and message)
Remove check link on invoices peppol id
Try to fix for buyer peppol id

## 1.2.2 -- 2025-05-16

Peppol / Scrada send XML file in progress (tests)

## 1.2.0 -- 2025-01-28

NEW: connect to Peppol AP (experimental)


## 1.0.69 -- 2024-09-16

Fix peppol id as priority id (use case Luxembourg)

## 1.0.66 -- 2024-08-01

Fix try to use peppol id a priority id

## 1.0.64 -- 2024-05-30

Fix remove empty description
Update langs & translations
Remove bad identifier on PartiIdentification (list from ISO 6523 ICD)

## 1.0.60 -- 2024-04-10

Add more debug messages to track race conditions
Full tests on nginx web server

## 1.0.56 -- 2024-03-25

FIX: massive changes for trigger support and custom autoloader

## 1.0.54 -- 2024-03-12

NEW: add a check agains peppol open directory for peppol id
NEW: switch to scopper to make module more consistant
FIX: $orig_pdf could be empty
FIX: better checks againts _invoice object
FIX: fix checks againts peppol id bad values
FIX: better scopper settings for REST API invoice payment -> peppol update
FIX: peppol custom id has priority on vat number
FIX: dol_is_file
NEW: add delivery address
NEW: add billing address


## 1.0.42 -- 2023-12-28

FIX: romania bucarest BU -> B
NEW: romania support custom
FIX: do not encode empty zipcode
FIX: romania special case for bucarest -> sector1...sector6 must be in town/city address of thirdpart
FIX: romania id of seller / buyer
NEW: messages & check agains most classic errors
FIX: romania remove schemeID
FIX: peppol & rg for Luxembourg

## 1.0.35 -- 2023-11-24

New: retained warranty
Upgrade lib to 0.2.7
Fix thanks to phpstan analyze
Fix whitepage on invoice updates
Fix retained warranty based on HT for Luxembourg (hack agains dolibarr core
    please have a look at https://github.com/Dolibarr/dolibarr/issues/26831
    for real fix)

## 1.0.32 -- 2023-10-07

Fix lines with discount, the lib makes bad XML, during the fix we use absolute discount, disable %

## 1.0.30 -- 2023-07-20

Line price=0, qty=0 are not transposed into peppol xml (Note: maybe as comment one day ?)

## 1.0.28 -- 2023-07-18

New conf on thirdpard : peppol specific id in case of your customer want something else than a vat code
(ex. public establishments)

## 1.0.26 -- 2023-07-06

Cleanup code (use $objFacture instead of $parameters['object'])
Fix error on invoice fk_bank -> fk_account

## 1.0.24 -- 2023-06-23

Add auto configuration of default bank account propagated to Peppol XML
Fix global discount like line with negative price set as global allowance

## 1.0.22 -- 2023-06-20

Fix unit price / quantity
Fix supplier order reference <-> customer order reference

## 1.0.20 -- 2023-06-06

Fix error message
Fix allowance compute

## 1.0.18 -- 2023-05-24

Add payment informations with linked bank account
Add cbc:PaymentID entry (reference to use on SEPA transfert)
Use PaymentMeansCode from official UNCL4461 code list
Start of de/es/it/ro translations

## 1.0.12

Add accounting products numbers on each line if product has a accounting number in your
database

## 1.0.11

Add a new config option "PEPPOL_FORCE_XML_WITH_VATNULL" to force generation of XML file
even if customer does not have a vat number

## 1.0.10

Fix: check if buyer has a vat number and display a warning

## 1.0.9

Fix: multicurrency_code could be empty or null
Fix: race condition on amount > one thousand with spaces or comma

## 1.0.7

Dev (ro)
MultiCurrency support
Do not create empty fields (cac:Attachment) if dolibarr don't make invoice share link
Add Subdivision data (country code)

## 1.0.6

Fix date error (day -1 on some case)

## 1.0.5

New mass action on invoice list : export multiple files in a zip archive

## 1.0.4 - 20220324

* auto join only pdf and xml files to mails (not jpeg or png preview)
* remove peppol main menu (no use)
* don't try to join peppol files on others modules than customer invoices

## 1.0.2 - 20220317

* auto join pdf and xml peppol file to mails

## 1.0.1 - 20220221

* Fix buyer full id (name + address)

## 1.0.0 - 20220215

First public release !

## 0.3 - 2022 02 08

First semi-public release

## 0.2

Firsts semi-public tests

## 0.1

Initial version

<?php
namespace custom\peppol;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class PeppolVatExemption
{
    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var array VAT exemption reason codes according to UNCL5305 and EU VAT Directive
     */
    const VAT_EXEMPT_CODES = [
        // Articles 132 EU VAT Directive - General exemptions
        'VATEX-EU-132'     => 'Exempt based on article 132 of Council Directive 2006/112/EC',
        'VATEX-EU-132-1A'  => 'Insurance and reinsurance transactions',
        'VATEX-EU-132-1B'  => 'Hospital and medical care',
        'VATEX-EU-132-1C'  => 'Medical care by medical professions',
        'VATEX-EU-132-1D'  => 'Human organ, blood and milk supply',
        'VATEX-EU-132-1E'  => 'Dental care',
        'VATEX-EU-132-1F'  => 'Independent groups whose members exercise medical professions',
        'VATEX-EU-132-1G'  => 'Welfare and social security work',
        'VATEX-EU-132-1H'  => 'Protection of children and young persons',
        'VATEX-EU-132-1I'  => 'School or university education',
        'VATEX-EU-132-1J'  => 'Tuition by teachers',
        'VATEX-EU-132-1K'  => 'Vocational training or retraining',
        'VATEX-EU-132-1L'  => 'Supply of staff for religious or philosophical purposes',
        'VATEX-EU-132-1M'  => 'Services and goods supplied by organisations recognized as charitable',
        'VATEX-EU-132-1N'  => 'Services by undertakers and cremation',
        'VATEX-EU-132-1O'  => 'Medical and biological analysis for protected persons',
        'VATEX-EU-132-1P'  => 'Hospital medical care for non-protected persons',
        'VATEX-EU-132-1Q'  => 'Services by independent groups (cost sharing)',

        // Articles 143 - Exemptions on importation
        'VATEX-EU-143'     => 'Exempt based on article 143 of Council Directive 2006/112/EC',
        'VATEX-EU-143-1A'  => 'Import of goods dispatched from third territory to another Member State',
        'VATEX-EU-143-1B'  => 'Import of goods for international organizations',
        'VATEX-EU-143-1C'  => 'Import of goods by armed forces NATO',
        'VATEX-EU-143-1D'  => 'Import of goods into Member States with limited territory',
        'VATEX-EU-143-1E'  => 'Reimport of goods by person who exported them outside EU',
        'VATEX-EU-143-1F'  => 'Import of goods under diplomatic or consular arrangements',
        'VATEX-EU-143-1FA' => 'Import of goods by EU institutions',
        'VATEX-EU-143-1G'  => 'Import of goods by international bodies recognized by public authorities',
        'VATEX-EU-143-1H'  => 'Import of goods into Member States by travellers from third countries',
        'VATEX-EU-143-1I'  => 'Import of goods in passenger luggage',
        'VATEX-EU-143-1J'  => 'Import of goods for charitable or philanthropic organizations',
        'VATEX-EU-143-1K'  => 'Import of goods for benefit of handicapped persons',
        'VATEX-EU-143-1L'  => 'Import of equipment and other property for disaster relief',

        // Articles 146-148 - Exemptions for exportations
        'VATEX-EU-148'     => 'Exempt based on article 148 of Council Directive 2006/112/EC',
        'VATEX-EU-148-A'   => 'Supply of goods dispatched to a destination outside EU',
        'VATEX-EU-148-B'   => 'Supply of goods to approved bodies for export',
        'VATEX-EU-148-C'   => 'Supply of services for goods exported outside EU',
        'VATEX-EU-148-D'   => 'Supply of goods in international transport passenger luggage',
        'VATEX-EU-148-E'   => 'Supply of goods for diplomatic corps',
        'VATEX-EU-148-F'   => 'Supply of goods to international organizations',
        'VATEX-EU-148-G'   => 'Supply of gold to central banks',

        // Articles 151 - International transport
        'VATEX-EU-151'     => 'Exempt based on article 151 of Council Directive 2006/112/EC',
        'VATEX-EU-151-1A'  => 'Transport of goods to or from islands',
        'VATEX-EU-151-1AA' => 'Transport of goods between islands',
        'VATEX-EU-151-1B'  => 'Transport of goods within territory of Member State',
        'VATEX-EU-151-1C'  => 'Intra-community transport of goods',
        'VATEX-EU-151-1D'  => 'Transport of goods from third territory',
        'VATEX-EU-151-1E'  => 'Transport of goods to third territory',

        // Common exemption codes
        'VATEX-EU-79-C'    => 'Exempt based on article 79(c) - Intra-community triangular transaction',
        'VATEX-EU-309'     => 'Call-off stock arrangements',
        'VATEX-EU-G'       => 'Export outside the EU',
        'VATEX-EU-O'       => 'Not subject to VAT',
        'VATEX-EU-IC'      => 'Intra-Community supply',
        'VATEX-EU-AE'      => 'Reverse charge',
        'VATEX-EU-D'       => 'Margin scheme - Travel agents',
        'VATEX-EU-F'       => 'Margin scheme - Second-hand goods',
        'VATEX-EU-I'       => 'Margin scheme - Works of art',
        'VATEX-EU-J'       => 'Margin scheme - Collectors items and antiques',
    ];

    /**
     * @var array Tax category codes for PEPPOL
     */
    const TAX_CATEGORIES = [
        'E'  => 'Exempt from tax',
        'AE' => 'VAT Reverse Charge',
        'K'  => 'Intra-Community supply',
        'G'  => 'Free export item, tax not charged',
        'O'  => 'Services outside scope of tax',
        'Z'  => 'Zero rated goods',
        'L'  => 'Canary Islands general indirect tax',
        'M'  => 'Tax for Ceuta and Melilla',
    ];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get VAT exemption information from Dolibarr Facture object
     * Analyzes the invoice and determines appropriate exemption codes
     *
     * @param Facture $facture Dolibarr invoice object
     * @return array Array of exemption data grouped by tax rate/category
     */
    public function getVatExemptionsFromFacture($facture)
    {
        global $conf;

        $exemptions = [];

        // Fetch invoice lines if not already loaded
        if (empty($facture->lines)) {
            $facture->fetch_lines();
        }

        // Get customer information
        $customer = new \Societe($this->db);
        $customer->fetch($facture->socid);

        // Analyze each invoice line
        foreach ($facture->lines as $line) {
            // Check if line has zero VAT rate
            if ($line->tva_tx == 0) {

                // Determine tax category and exemption code
                $taxCategory = $this->determineTaxCategory($facture, $customer, $line);
                $exemptionCode = $this->determineExemptionCode($facture, $customer, $line);
                $exemptionReason = $this->getExemptionReason($exemptionCode, $line);

                // Create unique key for grouping
                $key = $exemptionCode . '_' . $taxCategory;

                if (!isset($exemptions[$key])) {
                    $exemptions[$key] = [
                        'exemption_code' => $exemptionCode,
                        'exemption_reason' => $exemptionReason,
                        'tax_category' => $taxCategory,
                        'tax_rate' => 0.00,
                        'taxable_amount' => 0.00,
                        'tax_amount' => 0.00,
                        'lines' => []
                    ];
                }

                // Add line amount to this exemption group
                $exemptions[$key]['taxable_amount'] += $line->total_ht;
                $exemptions[$key]['lines'][] = $line;
            }
        }

        return $exemptions;
    }

    /**
     * Determine appropriate tax category based on invoice context
     *
     * @param Facture $facture Invoice object
     * @param Societe $customer Customer object
     * @param FactureLigne $line Invoice line object
     * @return string Tax category code (E, AE, K, G, O, etc.)
     */
    private function determineTaxCategory($facture, $customer, $line)
    {
        // Check for extrafields that may indicate tax category
        // if (!empty($line->array_options['options_peppol_tax_category'])) {
        //     return $line->array_options['options_peppol_tax_category'];
        // }

        // if (!empty($facture->array_options['options_peppol_tax_category'])) {
        //     return $facture->array_options['options_peppol_tax_category'];
        // }
        // Auto-determine based on context

        // Check if customer is outside EU
        if (!$this->isEUCountry($customer->country_code)) {
            return 'G'; // Export outside EU
        }

        // Check if intra-community supply
        if ($this->isEUCountry($customer->country_code) &&
            !empty($customer->tva_intra) &&
            $customer->country_code != $this->getSellerCountryCode($facture)) {
            return 'K'; // Intra-Community supply
        }

        // Check for reverse charge indicator
        if (!empty($facture->array_options['options_reverse_charge'])) {
            return 'AE'; // Reverse charge
        }

        // Check if service outside scope
        if (!empty($line->array_options['options_out_of_scope'])) {
            return 'O'; // Outside scope
        }

        // Default to standard exemption
        return 'E';
    }

    /**
     * Determine appropriate VAT exemption code
     *
     * @param Facture $facture Invoice object
     * @param Societe $customer Customer object
     * @param FactureLigne $line Invoice line object
     * @return string VAT exemption code
     */
    private function determineExemptionCode($facture, $customer, $line)
    {
        // Check for explicitly set exemption code in extrafields
        // if (!empty($line->array_options['options_vat_exemption_code'])) {
        //     return $line->array_options['options_vat_exemption_code'];
        // }

        // if (!empty($facture->array_options['options_vat_exemption_code'])) {
        //     return $facture->array_options['options_vat_exemption_code'];
        // }

        // Auto-determine based on context

        // Export outside EU
        if (!$this->isEUCountry($customer->country_code)) {
            return 'VATEX-EU-G';
        }

        // Intra-community supply
        if ($this->isEUCountry($customer->country_code) &&
            !empty($customer->tva_intra) &&
            $customer->country_code != $this->getSellerCountryCode($facture)) {
            return 'VATEX-EU-IC';
        }

        // Reverse charge
        if (!empty($facture->array_options['options_reverse_charge'])) {
            return 'VATEX-EU-AE';
        }

        // Triangular transaction
        // if (!empty($facture->array_options['options_triangular_transaction'])) {
        //     return 'VATEX-EU-79-C';
        // }

        // Try to determine by product/service type using extrafields or product category
        if (!empty($line->fk_product)) {
            $product = new \Product($this->db);
            $product->fetch($line->fk_product);

            // Check product extrafields for exemption type
            if (!empty($product->array_options['options_vat_exemption_type'])) {
                return $this->getExemptionCodeByType($product->array_options['options_vat_exemption_type']);
            }
        }

        // Check line description for keywords
        $description = strtolower($line->desc);

        if (strpos($description, 'medical') !== false ||
            strpos($description, 'health') !== false ||
            strpos($description, 'hospital') !== false) {
            return 'VATEX-EU-132-1B';
        }

        if (strpos($description, 'education') !== false ||
            strpos($description, 'training') !== false ||
            strpos($description, 'formation') !== false) {
            return 'VATEX-EU-132-1I';
        }

        if (strpos($description, 'insurance') !== false ||
            strpos($description, 'assurance') !== false) {
            return 'VATEX-EU-132-1A';
        }

        // Default general exemption
        return 'VATEX-EU-132';
    }

    /**
     * Get exemption code by service/product type
     *
     * @param string $type Service/product type
     * @return string Exemption code
     */
    private function getExemptionCodeByType($type)
    {
        $mapping = [
            'medical' => 'VATEX-EU-132-1B',
            'dental' => 'VATEX-EU-132-1E',
            'hospital' => 'VATEX-EU-132-1P',
            'education' => 'VATEX-EU-132-1I',
            'training' => 'VATEX-EU-132-1K',
            'insurance' => 'VATEX-EU-132-1A',
            'financial' => 'VATEX-EU-132-1A',
            'welfare' => 'VATEX-EU-132-1G',
            'charity' => 'VATEX-EU-132-1M',
            'cultural' => 'VATEX-EU-132-1N',
            'funeral' => 'VATEX-EU-132-1N',
            'export' => 'VATEX-EU-148-A',
            'transport_intl' => 'VATEX-EU-151',
        ];

        return $mapping[$type] ?? 'VATEX-EU-132';
    }

    /**
     * Get human-readable exemption reason
     *
     * @param string $exemptionCode Exemption code
     * @param FactureLigne $line Invoice line (optional for custom reason)
     * @return string Exemption reason text
     */
    private function getExemptionReason($exemptionCode, $line = null)
    {
        // Check if custom reason is provided in line extrafields
        if ($line && !empty($line->array_options['options_vat_exemption_reason'])) {
            return $line->array_options['options_vat_exemption_reason'];
        }

        // Return standard reason from code list
        return self::VAT_EXEMPT_CODES[$exemptionCode] ?? 'Exempt from VAT';
    }

    /**
     * Check if country is EU member state
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code
     * @return bool True if EU member
     */
    private function isEUCountry($countryCode)
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'
        ];

        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * Get seller country code from invoice
     *
     * @param Facture $facture Invoice object
     * @return string Country code
     */
    private function getSellerCountryCode($facture)
    {
        global $mysoc;

        // Get from global company object
        if (!empty($mysoc->country_code)) {
            return $mysoc->country_code;
        }

        // Fallback to configuration
        global $conf;
        return $conf->global->MAIN_INFO_SOCIETE_COUNTRY ?? 'FR';
    }

    /**
     * Generate PEPPOL UBL TaxTotal structure for invoice
     *
     * @param Facture $facture Dolibarr invoice object
     * @return array TaxTotal structure ready for UBL XML generation
     */
    public function generatePeppolTaxTotal($facture)
    {
        $exemptions = $this->getVatExemptionsFromFacture($facture);

        $taxSubtotals = [];
        $totalTaxAmount = 0.00;

        // Generate tax subtotal for each exemption group
        foreach ($exemptions as $exemption) {
            $taxSubtotals[] = [
                'TaxableAmount' => [
                    'value' => number_format($exemption['taxable_amount'], 2, '.', ''),
                    'currencyID' => $facture->multicurrency_code ?: 'EUR'
                ],
                'TaxAmount' => [
                    'value' => '0.00',
                    'currencyID' => $facture->multicurrency_code ?: 'EUR'
                ],
                'TaxCategory' => [
                    'ID' => $exemption['tax_category'],
                    'Percent' => '0.00',
                    'TaxExemptionReasonCode' => $exemption['exemption_code'],
                    'TaxExemptionReason' => $exemption['exemption_reason'],
                    'TaxScheme' => [
                        'ID' => 'VAT'
                    ]
                ]
            ];
        }

        // Build complete TaxTotal structure
        $taxTotal = [
            'TaxAmount' => [
                'value' => number_format($totalTaxAmount, 2, '.', ''),
                'currencyID' => $facture->multicurrency_code ?: 'EUR'
            ],
            'TaxSubtotal' => $taxSubtotals
        ];

        return $taxTotal;
    }

    /**
     * Generate PEPPOL UBL TaxCategory for invoice line
     *
     * @param Facture $facture Invoice object
     * @param FactureLigne $line Invoice line object
     * @return array ClassifiedTaxCategory structure
     */
    public function generatePeppolLineTaxCategory($facture, $line)
    {
        // Get customer info
        $customer = new \Societe($this->db);
        $customer->fetch($facture->socid);

        // Determine tax info for this line
        $taxCategory = $this->determineTaxCategory($facture, $customer, $line);
        $exemptionCode = $this->determineExemptionCode($facture, $customer, $line);
        $exemptionReason = $this->getExemptionReason($exemptionCode, $line);

        // Build ClassifiedTaxCategory structure
        $classifiedTaxCategory = [
            'ID' => $taxCategory,
            'Percent' => number_format($line->tva_tx, 2, '.', ''),
            'TaxScheme' => [
                'ID' => 'VAT'
            ]
        ];

        // Add exemption details if VAT is zero
        if ($line->tva_tx == 0) {
            $classifiedTaxCategory['TaxExemptionReasonCode'] = $exemptionCode;
            $classifiedTaxCategory['TaxExemptionReason'] = $exemptionReason;
        }

        return $classifiedTaxCategory;
    }

    /**
     * Validate invoice for PEPPOL compliance regarding VAT exemptions
     *
     * @param Facture $facture Invoice object
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validatePeppolVatExemptions($facture)
    {
        $errors = [];
        $warnings = [];

        // Fetch lines and customer
        if (empty($facture->lines)) {
            $facture->fetch_lines();
        }

        $customer = new \Societe($this->db);
        $customer->fetch($facture->socid);

        // Check each line with zero VAT
        foreach ($facture->lines as $index => $line) {
            if ($line->tva_tx == 0) {

                $taxCategory = $this->determineTaxCategory($facture, $customer, $line);
                $exemptionCode = $this->determineExemptionCode($facture, $customer, $line);

                // Validate exemption code exists
                if (!array_key_exists($exemptionCode, self::VAT_EXEMPT_CODES)) {
                    $errors[] = "Line $index: Invalid VAT exemption code '$exemptionCode'";
                }

                // Validate tax category
                if (!array_key_exists($taxCategory, self::TAX_CATEGORIES)) {
                    $errors[] = "Line $index: Invalid tax category '$taxCategory'";
                }

                // Check specific requirements for certain exemption codes

                // Intra-community supply requires buyer VAT number
                if ($exemptionCode == 'VATEX-EU-IC' && empty($customer->tva_intra)) {
                    $errors[] = "Line $index: Intra-community supply requires customer VAT number";
                }

                // Reverse charge requires buyer VAT number
                if ($exemptionCode == 'VATEX-EU-AE' && empty($customer->tva_intra)) {
                    $errors[] = "Line $index: Reverse charge requires customer VAT number";
                }

                // Export should have non-EU customer
                if ($exemptionCode == 'VATEX-EU-G' && $this->isEUCountry($customer->country_code)) {
                    $warnings[] = "Line $index: Export exemption used for EU customer";
                }
            }
        }

        // Check invoice level requirements

        // If invoice has only exempt lines, ensure proper documentation
        $hasStandardVat = false;
        foreach ($facture->lines as $line) {
            if ($line->tva_tx > 0) {
                $hasStandardVat = true;
                break;
            }
        }

        if (!$hasStandardVat && count($facture->lines) > 0) {
            // Fully exempt invoice - check for required notes
            if (empty($facture->note_public) && empty($facture->note_private)) {
                $warnings[] = "Fully exempt invoice should include explanatory notes";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Get all available exemption codes with descriptions
     * Useful for dropdown lists in Dolibarr forms
     *
     * @return array Array of exemption codes with descriptions
     */
    public static function getExemptionCodesList()
    {
        return self::VAT_EXEMPT_CODES;
    }

    /**
     * Get all available tax categories with descriptions
     *
     * @return array Array of tax categories with descriptions
     */
    public static function getTaxCategoriesList()
    {
        return self::TAX_CATEGORIES;
    }

}


?>

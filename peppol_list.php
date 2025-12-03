<?php

/**
 * \file    peppol_list.php
 * \ingroup peppol
 * \brief   List page for PEPPOL records
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array("peppol@peppolpeppyrus"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$show_files = GETPOST('show_files', 'int');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$optioncss = GETPOST('optioncss', 'alpha');
$page = GETPOSTISSET('page') ? GETPOST('page', 'int') : 0;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');

// Initialize technical objects
$hookmanager->initHooks(array('peppollist'));

// Security check
$result = restrictedArea($user, 'peppolpeppyrus');

// List of fields to show in the list
$arrayfields = array(
    'p.rowid' => array('label' => $langs->trans("Ref"), 'checked' => 1, 'position' => 10),
    'p.fk_object' => array('label' => $langs->trans("Object"), 'checked' => 1, 'position' => 20),
    'p.status' => array('label' => $langs->trans("Status"), 'checked' => 1, 'position' => 30),
    'p.message' => array('label' => $langs->trans("Message"), 'checked' => 1, 'position' => 40),
    'p.ap_name' => array('label' => $langs->trans("AccessPoint"), 'checked' => 1, 'position' => 50),
);

/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("PeppolList");
$help_url = '';

llxHeader('', $title, $help_url);

// Build and execute select
$sql = "SELECT";
$sql .= " p.rowid,";
$sql .= " p.fk_object,";
$sql .= " p.object_typeid,";
$sql .= " p.status,";
$sql .= " p.message,";
$sql .= " p.ap_name";
$sql .= " FROM ".MAIN_DB_PREFIX."peppol as p";
$sql .= " WHERE 1 = 1";

// Add sql order
$sql .= $db->order('p.rowid', 'DESC');

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);

    $param = '';

    print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
    if ($optioncss != '') {
        print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
    }
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="formfilter" value="list">';
    print '<input type="hidden" name="action" value="list">';
    print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
    print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
    print '<input type="hidden" name="page" value="'.$page.'">';

    print_barre_liste($title, 0, $_SERVER["PHP_SELF"], $param, '', '', '', $num, 0, 'title_generic.png', 0, '', '', 0);

    print '<div class="div-table-responsive">';
    print '<table class="tagtable nobottomiftotal liste">'."\n";

    // Fields title
    print '<tr class="liste_titre">';
    print '<th class="left">'.$langs->trans("Ref").'</th>';
    print '<th class="left">'.$langs->trans("Object").'</th>';
    print '<th class="left">'.$langs->trans("Status").'</th>';
    print '<th class="left">'.$langs->trans("Message").'</th>';
    print '<th class="left">'.$langs->trans("AccessPoint").'</th>';
    print '</tr>'."\n";

    $i = 0;
    while ($i < $num) {
        $obj = $db->fetch_object($resql);

        if ($obj) {
            // Determine object type label
            $object_label = '';
            if ($obj->object_typeid == 1) {
                $object_label = $langs->trans("CustomerInvoice").' #'.$obj->fk_object;
            } elseif ($obj->object_typeid == 2) {
                $object_label = $langs->trans("SupplierInvoice").' #'.$obj->fk_object;
            } else {
                $object_label = $langs->trans("Object").' #'.$obj->fk_object;
            }

            print '<tr class="oddeven">';

            // Ref with link to detail page
            print '<td class="left">';
            print '<a href="peppol_tab.php?id='.$obj->fk_object.'">'.$obj->rowid.'</a>';
            print '</td>';

            // Object
            print '<td class="left">'.$object_label.'</td>';

            // Status
            print '<td class="left">'.dol_escape_htmltag($obj->status).'</td>';

            // Message
            print '<td class="left">'.dol_escape_htmltag($obj->message).'</td>';

            // Access Point
            print '<td class="left">'.dol_escape_htmltag($obj->ap_name).'</td>';

            print '</tr>'."\n";
        }

        $i++;
    }

    print '</table>'."\n";
    print '</div>';

    print '</form>'."\n";

    $db->free($resql);
} else {
    dol_print_error($db);
}

// End of page
llxFooter();
$db->close();

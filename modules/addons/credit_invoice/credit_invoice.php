<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/functions.php';

use WHMCS\Database\Capsule;

function credit_invoice_config() 
{
    return CreditModule::getInstance()->getConfig();
}

function credit_invoice_activate() {}
function credit_invoice_deactivate() {}

function credit_invoice_output($vars) 
{
    $action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
    
    if (!$action) {
        echo 'This module has no admin page. Open an invoice to use the module.';
        return;
    }

    $module = CreditModule::getInstance();
    $invoiceId = (int)filter_input(INPUT_POST, 'invoice', FILTER_VALIDATE_INT);
    
    if ($action === 'credit' && $invoiceId) {
        $creditNote = $module->createCreditNote($invoiceId);
        if ($creditNote) {
            header("Location: invoices.php?action=edit&id={$creditNote->id}");
            die();
        }
    }

    throw new Exception('Invalid action or invoice ID');
}
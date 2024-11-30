<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/functions.php';

function credit_invoice_config() 
{
    return CreditModule::getInstance()->getConfig();
}

function credit_invoice_activate() {}
function credit_invoice_deactivate() {}

function credit_invoice_output($vars) 
{
    $action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
    $invoiceId = (int)filter_input(INPUT_POST, 'invoice', FILTER_VALIDATE_INT);
    
    if (!$action) {
        echo 'This module has no admin page. Open an invoice to use the module.';
        return;
    }

    try {
        $module = CreditModule::getInstance();
        
        if ($action === 'credit' && $invoiceId) {
            $creditNote = $module->createCreditNote($invoiceId);
            if ($creditNote) {
                header("Location: invoices.php?action=edit&id={$creditNote->id}");
                die();
            }
        }
    } catch (Exception $e) {
        logActivity("Credit Note Error: " . $e->getMessage());
        echo "Error creating credit note: " . $e->getMessage();
        return;
    }
}
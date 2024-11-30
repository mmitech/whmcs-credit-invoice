<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/functions.php';

add_hook('AdminInvoicesControlsOutput', 1, function($vars) {
    $module = CreditModule::getInstance();
    $invoiceId = (int)$vars['invoiceid'];
    
    ob_start(); ?>
    
    <?php if ($creditId = $module->isInvoiceCredited($invoiceId)): ?>
        <a href="invoices.php?action=edit&id=<?= $creditId ?>" 
           class="button btn btn-default">
           View Credit Note #<?= htmlspecialchars($creditId) ?>
        </a>
    <?php elseif ($originalId = $module->isCreditNote($invoiceId)): ?>
        <a href="invoices.php?action=edit&id=<?= $originalId ?>" 
           class="button btn btn-default">
           View Original Invoice #<?= htmlspecialchars($originalId) ?>
        </a>
    <?php else: ?>
        <form method="POST" 
              action="addonmodules.php?module=credit_invoice" 
              style="display:inline;">
            <input type="hidden" name="invoice" value="<?= $invoiceId ?>">
            <button type="submit" 
                    name="action" 
                    value="credit"
                    class="button btn btn-default">
                Create Credit Note
            </button>
        </form>
    <?php endif;

    return ob_get_clean();
});

add_hook('ClientAreaPageViewInvoice', 1, function($vars) {
    $module = CreditModule::getInstance();
    
    return [
        'notes' => $module->formatNotes($vars['notes'] ?? '', true),
        'pagetitle' => $module->formatPageTitle(
            (int)$vars['invoiceid'], 
            $vars['pagetitle'] ?? ''
        ),
    ];
});
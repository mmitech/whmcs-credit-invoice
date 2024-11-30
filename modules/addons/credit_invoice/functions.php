<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;
use WHMCS\Carbon;
use WHMCS\Config\Setting;

class CreditModule
{
    private static $instance = null;
    protected $config;
    protected $db;

    private function __construct()
    {
        $this->db = Capsule::connection();
        $this->loadConfig();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function loadConfig(): void
    {
        $this->config = Capsule::table('tbladdonmodules')
            ->where('module', 'credit_invoice')
            ->pluck('value', 'setting')
            ->toArray();
    }

    public function getConfig(): array
    {
        $gateways = Capsule::table('tblpaymentgateways')
            ->where('setting', 'name')
            ->orderBy('order')
            ->pluck('value', 'gateway')
            ->toArray();

        return [
            'name' => 'Credit Note Manager',
            'description' => 'Create and manage credit notes from invoices',
            'author' => 'Updated for Modern WHMCS',
            'language' => 'english',
            'version' => '2.0',
            'fields' => [
                'negateInvoice' => [
                    'FriendlyName' => 'Negate invoice',
                    'Type' => 'yesno',
                    'Description' => 'Create credit notes with negative amounts',
                    'Default' => 'yes',
                ],
                'cancelInvoice' => [
                    'FriendlyName' => "Set invoice to 'Cancelled'",
                    'Type' => 'yesno',
                    'Description' => "Change status for original invoice to 'Cancelled'",
                ],
                'creditNoteNoteText' => [
                    'FriendlyName' => 'Credit Note Text',
                    'Type' => 'text',
                    'Description' => '{NUMBER} indicates invoice number or ID if missing',
                    'Default' => 'CREDIT NOTE: Cancels invoice #{NUMBER}',
                ],
                'invoiceNoteText' => [
                    'FriendlyName' => 'Invoice Note Text',
                    'Type' => 'text',
                    'Description' => '{NUMBER} indicates invoice number or ID if missing',
                    'Default' => 'Cancelled via credit note #{NUMBER}',
                ],
                'creditNotePaymentMethod' => [
                    'FriendlyName' => 'Credit Note Payment Method',
                    'Type' => 'dropdown',
                    'Options' => array_merge(['' => "-- Don't set payment method --"], $gateways),
                    'Description' => 'Mark credit notes as paid with this gateway',
                ],
                'creditNotePageTitle' => [
                    'FriendlyName' => 'Credit Note Page Title',
                    'Type' => 'text',
                    'Description' => 'Leave blank for default ({NUMBER} for invoice number)',
                ],
            ],
        ];
    }

    public function createCreditNote(int $invoiceId): ?Invoice
    {
        $invoice = Invoice::with(['items', 'snapshot'])->findOrFail($invoiceId);
        
        $creditNoteData = [
            'userid' => $invoice->userid,
            'notes' => "Refund Invoice|{$invoiceId}|DO-NOT-REMOVE",
        ];

        foreach ($invoice->items as $idx => $item) {
            $amount = (bool)$this->config['negateInvoice'] ? -$item->amount : $item->amount;
            $creditNoteData["itemdescription{$idx}"] = htmlspecialchars($item->description);
            $creditNoteData["itemamount{$idx}"] = $amount;
            $creditNoteData["itemtaxed{$idx}"] = $item->taxed;
        }

        $result = localApi('CreateInvoice', $creditNoteData);
        if ($result['result'] !== 'success') {
            return null;
        }

        $creditNote = Invoice::with(['snapshot'])->findOrFail($result['invoiceid']);
        $now = Carbon::now();
        
        $this->setupCreditNote($creditNote, $now);
        $this->updateOriginalInvoice($invoice, $creditNote->id);

        return $creditNote;
    }

    protected function setupCreditNote(Invoice $creditNote, Carbon $date): void
    {
        $creditNote->status = 'Paid';
        $creditNote->paymentmethod = $this->config['creditNotePaymentMethod'];
        $creditNote->datepaid = $date;

        if ($this->shouldUseSequentialNumbering()) {
            $this->applySequentialNumbering($creditNote, $date);
        }

        $creditNote->save();
    }

    protected function shouldUseSequentialNumbering(): bool
    {
        return (bool)Setting::getValue('SequentialInvoiceNumbering');
    }

    protected function applySequentialNumbering(Invoice $invoice, Carbon $date): void
    {
        $format = Setting::getValue('SequentialInvoiceNumberFormat');
        $currentValue = Setting::getValue('SequentialInvoiceNumberValue');
        
        $replace = [
            '{YEAR}' => $date->format('Y'),
            '{MONTH}' => $date->format('m'),
            '{DAY}' => $date->format('d'),
            '{NUMBER}' => $currentValue,
        ];

        $invoice->invoicenum = str_replace(
            array_keys($replace),
            array_values($replace),
            $format
        );

        $increment = Setting::getValue('InvoiceIncrement');
        Setting::setValue('SequentialInvoiceNumberValue', $currentValue + $increment);
    }

    protected function updateOriginalInvoice(Invoice $invoice, int $creditNoteId): void
    {
        $notes = explode(PHP_EOL, $invoice->notes);
        array_unshift($notes, "Refund Credit Note|{$creditNoteId}|DO-NOT-REMOVE");

        $invoice->status = (bool)$this->config['cancelInvoice'] ? 'Cancelled' : 'Paid';
        $invoice->notes = implode(PHP_EOL, $notes);
        $invoice->save();
    }

    public function isInvoiceCredited(int $invoiceId): ?int
    {
        $invoice = Invoice::findOrFail($invoiceId);
        if (preg_match('/Refund Credit Note\|(\d+)/', $invoice->notes, $match)) {
            return (int)$match[1];
        }
        return null;
    }

    public function isCreditNote(int $invoiceId): ?int
    {
        $invoice = Invoice::findOrFail($invoiceId);
        if (preg_match('/Refund Invoice\|(\d+)/', $invoice->notes, $match)) {
            return (int)$match[1];
        }
        return null;
    }

    public function formatNotes(string $notes, bool $html = false): string
    {
        $notes = str_replace('<br />', '', $notes);
        $notes = explode(PHP_EOL, $notes);
        
        foreach ($notes as $idx => $note) {
            if (preg_match('/Refund Invoice\|(\d+)/', $note, $match)) {
                $text = $this->config['creditNoteNoteText'];
            } elseif (preg_match('/Refund Credit Note\|(\d+)/', $note, $match)) {
                $text = $this->config['invoiceNoteText'];
            } else {
                continue;
            }

            $invoice = Invoice::find($match[1]);
            $invoiceNum = $invoice->invoicenum ?: $match[1];
            $notes[$idx] = str_replace('{NUMBER}', htmlspecialchars($invoiceNum), $text);
        }

        $return = implode(PHP_EOL, $notes);
        return $html ? nl2br($return) : $return;
    }

    public function formatPageTitle(int $invoiceId, string $pageTitle): string
    {
        if (!$this->isCreditNote($invoiceId)) {
            return $pageTitle;
        }

        if (empty($this->config['creditNotePageTitle'])) {
            return $pageTitle;
        }

        $invoice = Invoice::findOrFail($invoiceId);
        $invoiceNum = $invoice->invoicenum ?: $invoiceId;
        return str_replace('{NUMBER}', htmlspecialchars($invoiceNum), $this->config['creditNotePageTitle']);
    }
}
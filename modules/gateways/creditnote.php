<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function creditnote_MetaData()
{
    return [
        'DisplayName' => 'Credit Note',
        'APIVersion' => '2.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function creditnote_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Credit Note',
        ],
    ];
}
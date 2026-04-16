<?php

namespace App\Services\Integrations\Whatsapp;

interface WhatsappSender
{
    /**
     * @param  array<int, array{name:string|int, value:mixed}>  $params
     * @return array{status:bool,response?:array<string,mixed>,error?:string}
     */
    public function sendTemplate(string $phoneNumber, string $template, array $params = []): array;

    /**
     * @param  array<int, string>  $phoneNumbers
     * @param  array<int, array{name:string|int, value:mixed}>  $params
     * @return array{status:bool,results:array<string,array{status:bool,response?:array<string,mixed>,error?:string}>}
     */
    public function sendTemplateMultipleRecipients(array $phoneNumbers, string $template, array $params = []): array;
}


<?php

namespace App\Support\Shopee;

use RuntimeException;

class ShopeeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}

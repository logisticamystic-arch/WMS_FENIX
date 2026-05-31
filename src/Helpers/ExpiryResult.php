<?php

namespace App\Helpers;

class ExpiryResult
{
    public const OK      = 'OK';
    public const BLOCKED = 'BLOCKED';
    public const PENDING = 'PENDING';

    public function __construct(
        public readonly string  $status,
        public readonly ?int    $aprobacionId  = null,
        public readonly ?string $message       = null,
        public readonly ?string $productName   = null,
        public readonly ?string $lote          = null,
        public readonly ?int    $diasRestantes = null
    ) {}
}

<?php

namespace App\Exceptions;

class ProviderValidationException extends ProviderException
{
    /** @var array<string, mixed> */
    public array $details = [];

    public function withDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }
}

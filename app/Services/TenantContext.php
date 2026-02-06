<?php

namespace App\Services;

final class TenantContext
{
    public function __construct(
        private ?string $tenantId = null,
    ) {}

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }
}

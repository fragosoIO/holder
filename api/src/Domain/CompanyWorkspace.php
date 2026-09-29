<?php

declare(strict_types=1);

namespace App\Domain;

final class CompanyWorkspace
{
    public function __construct(
        private readonly HolderConfig $config,
    ) {}

    public function ensure(string $companyId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId) !== 1) {
            throw new HolderException('invalid_company', 'Company id is not valid.', 422);
        }

        $path = $this->config->dataDir . '/workspaces/' . $companyId;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new HolderException('workspace_unavailable', 'The company workspace could not be created.', 500);
        }

        return $path;
    }
}

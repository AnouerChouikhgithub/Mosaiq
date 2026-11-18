<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/**
 * Defensive branch: the token authenticator already refuses a member whose
 * workspace membership is not active, so this normally surfaces as a 401. It
 * keeps the endpoint's authorization rule explicit instead of relying on that.
 */
final class AiWorkspaceInactiveException extends AiGenerationException
{
    public function __construct()
    {
        parent::__construct('Forbidden.', 403, 'ai.workspace_inactive');
    }
}

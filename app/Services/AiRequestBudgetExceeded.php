<?php

namespace App\Services;

class AiRequestBudgetExceeded extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('ai_request_limit_reached');
    }
}

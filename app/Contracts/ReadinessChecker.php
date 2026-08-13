<?php

namespace App\Contracts;

interface ReadinessChecker
{
    public function isReady(): bool;
}

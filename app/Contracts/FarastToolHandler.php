<?php

namespace App\Contracts;

use App\Models\User;

interface FarastToolHandler
{
    public function handle(User $actor, array $input, array $context = []): array;
}
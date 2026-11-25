<?php

declare(strict_types=1);

namespace Leads\Domain\User;

enum Role: string
{
    case ADMIN = 'ADMIN';
    case COMERCIAL = 'COMERCIAL';
}

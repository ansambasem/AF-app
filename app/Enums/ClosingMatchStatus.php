<?php

namespace App\Enums;

/**
 * Where a transfer in a daily closing stands against the receiving bank or
 * wallet's activity. Cash payments are checked by the cash count instead.
 */
enum ClosingMatchStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Unconfirmed = 'unconfirmed';
}

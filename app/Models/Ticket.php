<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    /** @use HasFactory<\Database\Factories\TicketFactory> */
    use HasFactory;

    const CACHEKEY = [
        'STOCK' => 'CACHE_TICKET_STOCK',
    ];

    public static function cacheKey(string $key, int $ticketId): string
    {
        if (! isset(self::CACHEKEY[$key])) {
            throw new Exception("Ticket cache key [$key] not found");
        }

        return self::CACHEKEY[$key].':'.$ticketId;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TicketPreloadCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ticket:preload-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('warm up ticket cache...');
        Ticket::chunk(500, function ($tickets) {
            $tickets->each(function ($ticket) {
                $cacheKey = Ticket::cacheKey('STOCK', $ticket->id);
                Cache::set($cacheKey, $ticket->stock_qty);
                Log::info("$cacheKey => $ticket->stock_qty");
            });
        });
    }
}

<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\CreateTicketOrderJob;
use App\Jobs\ReconcileOrderJob;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Ticket;
use App\Models\User;
use App\Payments\FakePay;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function post_api_order_訂票創建訂單job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'price' => 100,
            'stock_qty' => 5,
        ]);
        // warm up stock
        Artisan::call('ticket:preload-cache');

        $attrs = [
            'ticket_id' => $ticket->id,
            'qty' => 1,
        ];

        $response = $this->json('POST', 'api/order', $attrs, [
            'Authorization' => 'Bearer '.Auth::login($user)]
        );
        Queue::assertPushed(CreateTicketOrderJob::class, 1);

        $result = $this->parse($response);
        $orderNo = $result->data->order_no;
        $this->assertTrue(Str::startswith($orderNo, 'T'));

        $response->assertStatus(202)->assertJsonStructure([
            'data' => [
                'order_no',
            ],
        ]);
    }

    #[Test]
    public function post_api_order_訂票創建訂單但超買要拒絕(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'price' => 100,
            'stock_qty' => 5,
        ]);
        // warm up stock
        Artisan::call('ticket:preload-cache');

        $attrs = [
            'ticket_id' => $ticket->id,
            'qty' => 6,
        ];

        $response = $this->json('POST', 'api/order', $attrs, [
            'Authorization' => 'Bearer '.Auth::login($user)]
        );

        $response->assertStatus(409)->assertJsonStructure([
            'message',
            'errors',
        ]);

        $res = json_decode($response->getContent());
        $this->assertEquals('Insufficient Stock', $res->message);
    }

    #[Test]
    public function CreateTicketOrderJob_成功時訂單需完成(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'price' => 100,
            'stock_qty' => 5,
        ]);
        // warm up stock
        Artisan::call('ticket:preload-cache');

        $mock = Mockery::mock(FakePay::class);
        $mock->shouldReceive('pay')
            ->once()
            ->andReturn(1);
        $this->app->instance(FakePay::class, $mock);

        $attrs = [
            'ticket_id' => $ticket->id,
            'qty' => 1,
        ];

        $response = $this->json('POST', 'api/order', $attrs, [
            'Authorization' => 'Bearer '.Auth::login($user)]
        );

        $result = $this->parse($response);
        $orderNo = $result->data->order_no;
        $this->assertTrue(Str::startswith($orderNo, 'T'));

        $response->assertStatus(202)->assertJsonStructure([
            'data' => [
                'order_no',
            ],
        ]);

        $order = Order::where('no', $orderNo)->first();
        $this->assertEquals(Order::STATUS['SUCCESS'], $order->status);
        $this->assertEquals($user->id, $order->user_id);
        $this->assertEquals($ticket->id, $order->ticket_id);
        $this->assertEquals($attrs['qty'], $order->qty);
        $this->assertEquals($attrs['qty'] * $ticket->price, $order->cost);

        $orderlogs = OrderLog::get();
        $queueLog = $orderlogs->first();
        $this->assertEquals(Order::STATUS['QUEUE'], $queueLog->status);
        $this->assertEquals($user->id, $queueLog->user_id);
        $this->assertEquals($ticket->id, $queueLog->ticket_id);
        $this->assertEquals($attrs['qty'], $queueLog->qty);
        $this->assertEquals($attrs['qty'] * $ticket->price, $queueLog->cost);
        $this->assertEquals(CreateTicketOrderJob::class, $queueLog->action_by);

        $successLog = $orderlogs->last();
        $this->assertEquals(Order::STATUS['SUCCESS'], $successLog->status);
        $this->assertEquals($user->id, $successLog->user_id);
        $this->assertEquals($ticket->id, $successLog->ticket_id);
        $this->assertEquals($attrs['qty'], $successLog->qty);
        $this->assertEquals($attrs['qty'] * $ticket->price, $successLog->cost);
        $this->assertEquals(CreateTicketOrderJob::class, $successLog->action_by);

        $ticket->refresh();
        $this->assertEquals(5 - $attrs['qty'], $ticket->stock_qty);
    }

    #[Test]
    public function CreateTicketOrderJob_API失敗時會調用delay對帳(): void
    {
        Queue::fake([ReconcileOrderJob::class]);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'price' => 100,
            'stock_qty' => 5,
        ]);
        // warm up stock
        Artisan::call('ticket:preload-cache');

        $mock = Mockery::mock(FakePay::class);
        $mock->shouldReceive('pay')
            ->once()
            ->andThrow(new Exception);
        $this->app->instance(FakePay::class, $mock);

        $attrs = [
            'ticket_id' => $ticket->id,
            'qty' => 1,
        ];

        $response = $this->json('POST', 'api/order', $attrs, [
            'Authorization' => 'Bearer '.Auth::login($user)]
        );

        $result = $this->parse($response);
        $orderNo = $result->data->order_no;
        $this->assertTrue(Str::startswith($orderNo, 'T'));

        $response->assertStatus(202)->assertJsonStructure([
            'data' => [
                'order_no',
            ],
        ]);

        $order = Order::where('no', $orderNo)->first();
        $this->assertEquals(Order::STATUS['QUEUE'], $order->status);
        $this->assertEquals($user->id, $order->user_id);
        $this->assertEquals($ticket->id, $order->ticket_id);
        $this->assertEquals($attrs['qty'], $order->qty);
        $this->assertEquals($attrs['qty'] * $ticket->price, $order->cost);

        $ticket->refresh();
        $this->assertEquals(5, $ticket->stock_qty);
        Queue::assertPushed(ReconcileOrderJob::class, 1);
    }
}

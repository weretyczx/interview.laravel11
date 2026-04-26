<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->string('no')->comment('no');
            $table->unsignedBigInteger('order_id')->comment('ref.order.id');
            $table->unsignedTinyInteger('status')->comment('狀態 0:queue,1:success,2:fail');
            $table->unsignedBigInteger('user_id')->comment('ref.user.id');
            $table->unsignedBigInteger('ticket_id')->comment('ref.ticket.id');
            $table->unsignedBigInteger('qty')->comment('購置數量');
            $table->unsignedBigInteger('cost')->comment('總花費');
            $table->string('action_by')->comment('異動者');
            $table->timestamps();

            $table->index('user_id');
            $table->index('no');
            $table->index('ticket_id');
            $table->index('order_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_logs');
    }
};

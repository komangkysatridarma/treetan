<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->string('pg_transaction_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('method', 50);
            $table->enum('status', [
                'CREATED', 'PENDING', 'EXPIRED', 'SUCCESS', 'FAILED'
            ])->default('CREATED');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('payments');
    }
};

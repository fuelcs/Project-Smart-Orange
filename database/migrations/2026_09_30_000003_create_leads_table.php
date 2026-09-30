<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('external_id');
            $table->dateTime('created_at');
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('city');
            $table->string('source');
            $table->string('utm_campaign')->nullable();
            $table->string('product');
            $table->bigInteger('budget_uah')->nullable()->comment('Amount in kopiykas (UAH)');
            $table->string('status');
            $table->string('manager')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('next_contact_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

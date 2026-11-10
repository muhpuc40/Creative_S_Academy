<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS gateway settings (driver, API URL, API key, sender ID, rate, bulk chunk).
 * Set in System > SMS, so a new API key or rate needs no redeploy. Always one row (id = 1).
 * The row starts with the old SMS_* values from .env, so nothing changes on the day this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 20)->default('log');       // log = only write to the log, bulksmsbd = send
            $table->string('url')->default('https://bulksmsbd.net/api/smsapi');
            $table->text('api_key')->nullable();                 // encrypted
            $table->string('sender_id', 50)->nullable();
            $table->decimal('rate', 8, 2)->default(0.35);        // taka per SMS part, for the cost estimate
            $table->unsignedInteger('bulk_chunk')->default(100); // numbers per API call
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        $driver = env('SMS_DRIVER', 'log');

        DB::table('sms_settings')->insert([
            'id' => 1,
            'driver' => $driver && $driver !== 'log' ? 'bulksmsbd' : 'log',
            'url' => env('SMS_URL') ?: 'https://bulksmsbd.net/api/smsapi',
            'api_key' => env('SMS_API_KEY') ? encrypt((string) env('SMS_API_KEY'), false) : null,
            'sender_id' => env('SMS_SENDER_ID') ?: null,
            'rate' => (float) env('SMS_RATE', 0.35) ?: 0.35,
            'bulk_chunk' => max(1, (int) env('SMS_BULK_CHUNK', 100)) ?: 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_settings');
    }
};

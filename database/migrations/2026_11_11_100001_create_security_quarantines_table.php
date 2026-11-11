<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_quarantines', function (Blueprint $table) {
            $table->id();
            $table->string('original_path', 1024); // absolute path the file was moved from
            $table->string('stored_path', 1024);   // absolute path inside the quarantine folder
            $table->string('display_path', 1024);  // path shown in the UI (relative to the app)
            $table->string('reason');               // why it was flagged
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_quarantines');
    }
};

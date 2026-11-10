<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('mode', 10)->default('compare');      // compare | verify
            $table->string('origin', 10)->default('web');        // web | api
            $table->string('engine', 20);
            $table->string('source_path');
            $table->string('target_path');
            $table->decimal('threshold', 5, 2);
            $table->decimal('similarity', 5, 2)->default(0);
            $table->boolean('is_match')->default(false);
            $table->boolean('passed')->default(false);
            $table->json('failure_reasons')->nullable();
            $table->json('result')->nullable();
            $table->string('forward_status', 10)->default('pending'); // pending | sent | failed | skipped
            $table->unsignedSmallInteger('forward_http_code')->nullable();
            $table->text('forward_response')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();

            $table->index(['is_match', 'created_at']);
            $table->index('forward_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparisons');
    }
};

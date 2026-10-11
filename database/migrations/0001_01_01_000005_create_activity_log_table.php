<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log (§4.8): the activitylog 5.1 table plus the kit columns. organisation_id has no foreign
 * key, so a purged organisation's rows stay until retention (§4.3). The morph indexes get table-specific
 * names: index names are unique per schema on PostgreSQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'activity_log_subject_index');
            $table->string('event')->nullable()->index();
            $table->nullableMorphs('causer', 'activity_log_causer_index');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->unsignedBigInteger('organisation_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['organisation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};

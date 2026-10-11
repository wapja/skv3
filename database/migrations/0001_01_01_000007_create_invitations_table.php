<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.5: the single source of truth for invitations (W13). The token is kept as a sha256 hash
     * for the lookup and as an encrypted copy for mails and "Copy invite link" (cleared on accept).
     * organisation_id restricts on delete: a purge force-deletes invitations first (C-8).
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};

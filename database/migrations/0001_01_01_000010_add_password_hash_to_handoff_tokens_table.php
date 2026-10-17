<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CD-3 (M8a): the HMAC of the user's password hash when the link was issued (SessionGuard::hashPasswordForCookie()).
 * A link whose value no longer matches, or that has none because it was issued before this column, is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handoff_tokens', function (Blueprint $table): void {
            $table->string('password_hash', 64)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('handoff_tokens', function (Blueprint $table): void {
            $table->dropColumn('password_hash');
        });
    }
};

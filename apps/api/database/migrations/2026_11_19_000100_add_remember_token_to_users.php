<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column Laravel's session guard needs for "remember me".
 *
 * The designed `LoginRequest` carries a `remember` flag, and the guard reads
 * and cycles this token on login and logout. Without the column the flag could
 * only be accepted and ignored, which is worse than not offering it: a staff
 * member would be told their session persists when it does not.
 *
 * Hashed-at-rest is not applicable here - the value is a random identifier
 * Laravel generates, not a credential the user knows - but it is a session
 * secret, so it stays out of logs and responses: the User model hides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('remember_token', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('remember_token');
        });
    }
};

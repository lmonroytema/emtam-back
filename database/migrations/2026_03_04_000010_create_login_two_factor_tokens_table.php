<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLoginTwoFactorTokensTable extends Migration
{
    public function up(): void
    {
        Schema::create('login_two_factor_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('token_hash');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_two_factor_tokens');
    }
}

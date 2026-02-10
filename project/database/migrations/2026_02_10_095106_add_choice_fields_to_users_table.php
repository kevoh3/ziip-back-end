<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('choice_onboarding_request_id')->nullable()->index();
            $table->string('choice_onboarding_status')->nullable();
            $table->timestamp('choice_onboarding_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'choice_onboarding_request_id',
                'choice_onboarding_status',
                'choice_onboarding_updated_at',
            ]);
        });
    }
};

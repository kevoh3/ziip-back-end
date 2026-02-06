<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('administrative_divisions', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 10)->nullable();
            $table->enum('type', ['country', 'state', 'province', 'region', 'county', 'subcounty', 'constituency', 'district', 'city', 'borough', 'ward', 'location', 'sublocation', 'village', 'custom',]);
            $table->string('name');
            $table->string('slug');
            $table->string('code', 50)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('administrative_divisions')->nullOnDelete();
            $table->unsignedInteger('depth')->default(0);
            $table->string('path', 1024);
            $table->decimal('centroid_lat', 10, 7)->nullable();
            $table->decimal('centroid_lng', 10, 7)->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Indexes for performance
            $table->index('type');
            $table->index('country_code');
            $table->index('path');
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('administrative_divisions');
    }
};

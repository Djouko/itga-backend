<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('title');
            $table->string('contract_type'); // stage, alternance, cdi, cdd, freelance
            $table->string('location_type'); // remote, hybrid, onsite
            $table->string('location_city')->nullable();
            $table->string('domain')->nullable(); // data, dev, design, engineering, marketing, other
            $table->text('description');
            $table->text('missions')->nullable();
            $table->json('required_skills')->nullable();
            $table->decimal('salary_min', 10, 2)->nullable();
            $table->decimal('salary_max', 10, 2)->nullable();
            $table->string('salary_period')->nullable(); // month, year
            $table->string('experience_level')->nullable(); // junior, mid, senior
            $table->date('deadline')->nullable();
            $table->string('status')->default('draft'); // draft, published, closed, rejected
            $table->tinyInteger('is_featured')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('applications_count')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index('company_id');
            $table->index('status');
            $table->index('contract_type');
            $table->index('location_type');
            $table->index('domain');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_offers');
    }
};

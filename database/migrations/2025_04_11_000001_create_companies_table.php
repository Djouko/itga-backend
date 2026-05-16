<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('sector')->nullable();
            $table->text('rse_commitments')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->integer('company_size')->nullable(); // 1=1-10, 2=11-50, 3=51-200, 4=201-500, 5=500+
            $table->tinyInteger('is_verified')->default(0);
            $table->tinyInteger('is_suspended')->default(0);
            $table->string('device_token')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('is_verified');
            $table->index('is_suspended');
        });
    }

    public function down()
    {
        Schema::dropIfExists('companies');
    }
};

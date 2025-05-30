<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (LibreNMS\DB\Eloquent::getDriver() !== 'sqlite') {
            DB::statement("SET TIME_ZONE='+00:00'"); // force UTC for default timestamp value
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notifications_id');
            $table->string('title')->default('');
            $table->text('body');
            $table->integer('severity')->nullable()->default(0)->index()->comment('0=ok,1=warning,2=critical');
            $table->string('source')->default('');
            $table->string('checksum', 128)->unique();
            $table->timestamp('datetime')->default('1970-01-02 00:00:00');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::drop('notifications');
    }
};

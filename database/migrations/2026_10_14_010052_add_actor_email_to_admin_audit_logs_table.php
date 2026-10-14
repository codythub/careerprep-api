<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot the actor's email so audit entries stay attributable after an admin account is deleted.
     */
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->string('actor_email')->nullable();
        });

        DB::table('admin_audit_logs')
            ->whereNotNull('actor_id')
            ->update(['actor_email' => DB::raw('(select email from users where users.id = admin_audit_logs.actor_id)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_email');
        });
    }
};

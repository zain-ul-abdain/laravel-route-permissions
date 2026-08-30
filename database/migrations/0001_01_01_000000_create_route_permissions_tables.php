<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('route-permissions.tables');

        Schema::create($tables['roles'], function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create($tables['permissions'], function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->text('description')->nullable();

            // Set by permissions:sync so the command can distinguish a
            // permission it generated from one a human added by hand, and
            // therefore prune the former without touching the latter.
            $table->boolean('from_route')->default(false);

            $table->timestamps();

            $table->index('from_route');
        });

        Schema::create($tables['role_user'], function (Blueprint $table) use ($tables) {
            $table->foreignId('role_id')->constrained($tables['roles'])->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->index('user_id');

            // Composite primary key rather than an id column: it enforces
            // "a user holds a role at most once" at the database level, which
            // makes a double-assign a no-op instead of a duplicate row.
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create($tables['permission_role'], function (Blueprint $table) use ($tables) {
            $table->foreignId('permission_id')->constrained($tables['permissions'])->cascadeOnDelete();
            $table->foreignId('role_id')->constrained($tables['roles'])->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create($tables['permission_user'], function (Blueprint $table) use ($tables) {
            $table->foreignId('permission_id')->constrained($tables['permissions'])->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->index('user_id');
            $table->primary(['permission_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $tables = config('route-permissions.tables');

        Schema::dropIfExists($tables['permission_user']);
        Schema::dropIfExists($tables['permission_role']);
        Schema::dropIfExists($tables['role_user']);
        Schema::dropIfExists($tables['permissions']);
        Schema::dropIfExists($tables['roles']);
    }
};

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
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(['actor_guid', 'created_at'], 'audit_events_actor_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasActorIndex = fn (): bool => collect(Schema::getIndexes('audit_events'))
            ->contains(fn (array $index): bool => $index['name'] === 'audit_events_actor_guid_foreign'
                && $index['columns'] === ['actor_guid']);

        // InnoDB can remove the automatic FK index when the composite index is added.
        if (! $hasActorIndex()) {
            Schema::table('audit_events', function (Blueprint $table) {
                $table->index('actor_guid', 'audit_events_actor_guid_foreign');
            });
        }

        $hasActorForeignKey = collect(Schema::getForeignKeys('audit_events'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['actor_guid']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['guid']);
        $connection = Schema::getConnection();

        if (! $hasActorIndex() || ! $hasActorForeignKey
            || ($connection->getDriverName() === 'mysql' && (int) $connection->scalar('SELECT @@foreign_key_checks') !== 1)) {
            throw new RuntimeException('Cannot remove C05 without the actor supporting index and an active foreign key.');
        }

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_events_actor_created_index');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail (SHR-11, ADR-0006). Besides the model guard, database triggers refuse UPDATE and DELETE
 * on both tables, so not even a raw query or an administrator's normal grants can rewrite history.
 */
return new class extends Migration
{
    private const TABLES = ['audit_events', 'audit_event_branches'];

    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('action', 64)->index();
            $table->string('area', 32)->index();
            // No foreign keys: referential actions would rewrite audit rows; names are snapshotted instead.
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('source', 32);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('occurred_at', 6)->index();

            $table->index(['subject_type', 'subject_id']);
        });

        // Branch facts of the subject at the time of the event, for the P-63 branch/region scopes.
        Schema::create('audit_event_branches', function (Blueprint $table) {
            $table->unsignedBigInteger('audit_event_id');
            $table->unsignedBigInteger('branch_id')->index();
            $table->primary(['audit_event_id', 'branch_id']);
        });

        foreach (self::TABLES as $table) {
            $this->createGuards($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation}");
            }
        }

        Schema::dropIfExists('audit_event_branches');
        Schema::dropIfExists('audit_events');
    }

    /**
     * @param  'audit_events'|'audit_event_branches'  $table
     */
    private function createGuards(string $table): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $operation => $event) {
            $name = "{$table}_no_{$operation}";

            DB::unprepared(match ($driver) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END",
                'mysql', 'mariadb' => "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'",
                'pgsql' => throw new RuntimeException('Add a PostgreSQL rule for the audit guard before using this driver.'),
                default => throw new RuntimeException("No audit guard for driver [{$driver}]."),
            });
        }
    }
};

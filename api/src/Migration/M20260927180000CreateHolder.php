<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M20260927180000CreateHolder implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        foreach ($this->upStatements() as $sql) {
            $b->execute($sql);
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach ($this->downStatements() as $sql) {
            $b->execute($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function upStatements(): array
    {
        return [
            'CREATE TABLE users (
                id uuid PRIMARY KEY,
                name text NOT NULL,
                email text NOT NULL UNIQUE,
                password_hash text,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE sessions (
                token text PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                expires_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE companies (
                id uuid PRIMARY KEY,
                name text NOT NULL,
                mission text NOT NULL DEFAULT \'\',
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE memberships (
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                user_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role text NOT NULL,
                PRIMARY KEY (company_id, user_id)
            )',
            'CREATE TABLE invites (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                email text NOT NULL,
                role text NOT NULL,
                token text NOT NULL UNIQUE,
                accepted_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE agents (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                name text NOT NULL,
                title text NOT NULL DEFAULT \'\',
                job_description text NOT NULL DEFAULT \'\',
                manager_id uuid REFERENCES agents (id) ON DELETE SET NULL,
                status text NOT NULL,
                pi_binary text NOT NULL DEFAULT \'pi\',
                pi_provider text NOT NULL DEFAULT \'\',
                pi_model text NOT NULL DEFAULT \'\',
                pi_thinking text NOT NULL DEFAULT \'\',
                workspace_path text NOT NULL,
                session_id text,
                pi_version text,
                monthly_budget_cents integer,
                spent_cents integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE goals (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                parent_id uuid REFERENCES goals (id) ON DELETE SET NULL,
                title text NOT NULL,
                description text NOT NULL DEFAULT \'\',
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE projects (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                name text NOT NULL,
                workspace_path text NOT NULL DEFAULT \'\',
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE tasks (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                goal_id uuid REFERENCES goals (id) ON DELETE SET NULL,
                project_id uuid REFERENCES projects (id) ON DELETE SET NULL,
                parent_id uuid REFERENCES tasks (id) ON DELETE SET NULL,
                assignee_agent_id uuid REFERENCES agents (id) ON DELETE SET NULL,
                title text NOT NULL,
                description text NOT NULL DEFAULT \'\',
                status text NOT NULL,
                checkout_run_id uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE task_comments (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                task_id uuid NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
                author_type text NOT NULL,
                author_id uuid NOT NULL,
                body text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE TABLE task_labels (
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                task_id uuid NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
                label text NOT NULL,
                PRIMARY KEY (task_id, label)
            )',
            'CREATE TABLE task_blockers (
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                task_id uuid NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
                blocker_task_id uuid NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
                PRIMARY KEY (task_id, blocker_task_id)
            )',
            'CREATE TABLE wakeups (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                agent_id uuid NOT NULL REFERENCES agents (id) ON DELETE CASCADE,
                task_id uuid REFERENCES tasks (id) ON DELETE SET NULL,
                status text NOT NULL,
                reason text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                claimed_at timestamptz
            )',
            'CREATE UNIQUE INDEX wakeups_one_pending ON wakeups (agent_id) WHERE status = \'pending\'',
            'CREATE TABLE runs (
                id uuid PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                agent_id uuid NOT NULL REFERENCES agents (id) ON DELETE CASCADE,
                task_id uuid REFERENCES tasks (id) ON DELETE SET NULL,
                wakeup_id uuid REFERENCES wakeups (id) ON DELETE SET NULL,
                status text NOT NULL,
                session_id text,
                pi_pid integer,
                worker_pid integer,
                prompt text NOT NULL DEFAULT \'\',
                cancel_requested integer NOT NULL DEFAULT 0,
                started_at timestamptz NOT NULL DEFAULT now(),
                finished_at timestamptz
            )',
            'ALTER TABLE tasks ADD CONSTRAINT tasks_checkout_run_fk FOREIGN KEY (checkout_run_id) REFERENCES runs (id) ON DELETE SET NULL',
            'CREATE TABLE run_events (
                id bigserial PRIMARY KEY,
                company_id uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
                run_id uuid NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
                event_type text NOT NULL,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )',
            'CREATE INDEX agents_company ON agents (company_id)',
            'CREATE INDEX tasks_company ON tasks (company_id, updated_at DESC)',
            'CREATE INDEX comments_task ON task_comments (task_id, created_at)',
            'CREATE INDEX events_run ON run_events (run_id, id)',
            'CREATE INDEX wakeups_pending ON wakeups (status, created_at)',
        ];
    }

    /**
     * @return list<string>
     */
    private function downStatements(): array
    {
        return [
            'DROP TABLE IF EXISTS run_events CASCADE',
            'ALTER TABLE IF EXISTS tasks DROP CONSTRAINT IF EXISTS tasks_checkout_run_fk',
            'DROP TABLE IF EXISTS runs CASCADE',
            'DROP TABLE IF EXISTS wakeups CASCADE',
            'DROP TABLE IF EXISTS task_blockers CASCADE',
            'DROP TABLE IF EXISTS task_labels CASCADE',
            'DROP TABLE IF EXISTS task_comments CASCADE',
            'DROP TABLE IF EXISTS tasks CASCADE',
            'DROP TABLE IF EXISTS projects CASCADE',
            'DROP TABLE IF EXISTS goals CASCADE',
            'DROP TABLE IF EXISTS agents CASCADE',
            'DROP TABLE IF EXISTS invites CASCADE',
            'DROP TABLE IF EXISTS memberships CASCADE',
            'DROP TABLE IF EXISTS sessions CASCADE',
            'DROP TABLE IF EXISTS companies CASCADE',
            'DROP TABLE IF EXISTS users CASCADE',
        ];
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provisioning token is written once and never again.
 *
 * The token is the only thing standing between one paid order and two servers.
 * A provider is asked to create a server *for this token*, and answers a repeat
 * by returning what it already made. That contract holds exactly as long as the
 * token stays the same — so the dangerous operation is not creating twice, it is
 * quietly changing the token and then creating once more.
 *
 * Every plausible reason to change it is wrong. A crashed worker did not undo
 * the remote call. A deleted server has already consumed its token, and a fresh
 * one would ask for a replacement nobody bought. A failed local write says
 * nothing about what the provider did. In each case the same order still means
 * the same intended machine, so it keeps the same token.
 *
 * The application refuses this too. This is the guard that still holds for a
 * query builder call or a psql prompt, which is where "just clear that stuck
 * order's uuid and let it retry" actually gets typed.
 *
 * The Phase 6 migration that created the column is left alone; this adds to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Assigning a token, and re-writing the same token, are both fine. The
        // column is nullable and unique already, so absence and duplication are
        // handled; what is missing is that a non-null value is final.
        //
        // IS DISTINCT FROM rather than <>, because a NULL on either side of <>
        // yields NULL, which a plpgsql IF treats as false — the clearing case
        // would sail straight through the check meant to stop it.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orders_reject_provisioning_uuid_change() RETURNS trigger AS $$
            BEGIN
                IF OLD.provisioning_uuid IS NOT NULL
                   AND NEW.provisioning_uuid IS DISTINCT FROM OLD.provisioning_uuid
                THEN
                    RAISE EXCEPTION 'orders: a provisioning token is assigned once and never changed'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER orders_no_provisioning_uuid_change
                BEFORE UPDATE ON orders
                FOR EACH ROW EXECUTE FUNCTION orders_reject_provisioning_uuid_change();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_provisioning_uuid_change ON orders');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS orders_reject_provisioning_uuid_change()');
    }
};

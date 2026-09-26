<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Identificativo pubblico per foto e inviti: negli URL e nelle API si usa
 * l'ULID, l'id numerico resta interno (niente conteggi o foto "vicine" da
 * indovinare). Le righe esistenti ricevono un ULID con la loro data di
 * creazione, così l'ordine degli ULID segue quello degli id.
 */
return new class extends Migration
{
    private const TABLES = ['photos', 'invites'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->ulid('ulid')->nullable()->after('id');
            });

            DB::table($table)->whereNull('ulid')->orderBy('id')->select(['id', 'created_at'])
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $time = $row->created_at ? new DateTimeImmutable($row->created_at) : null;

                        DB::table($table)->where('id', $row->id)->update([
                            'ulid' => strtolower((string) Str::ulid($time)),
                        ]);
                    }
                });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->ulid('ulid')->nullable(false)->change();
                $blueprint->unique('ulid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['ulid']);
                $blueprint->dropColumn('ulid');
            });
        }
    }
};

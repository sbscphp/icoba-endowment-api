<?php

namespace App\Console\Commands;

use App\Models\GraduationSet;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSetsFromRemoteCommand extends Command
{
    protected $signature = 'sets:sync {--dry-run : Preview changes without writing to DB}';

    protected $description = 'Sync sets table from remote platform via secure API';

    public function handle(): int
    {
        $secret = config('services.remote_sync.secret');
        $setsUrl = config('services.remote_sync.sets_url');

        if (! is_string($secret) || $secret === '' || ! is_string($setsUrl) || $setsUrl === '') {
            $this->error('REMOTE_SYNC_SECRET and REMOTE_SYNC_SETS_URL must be configured.');

            return self::FAILURE;
        }

        $this->info('Fetching sets from remote...');

        $response = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->get($setsUrl);

        if (! $response->successful()) {
            $this->error("Remote API error: {$response->status()} — {$response->body()}");
            Log::error('sets:sync failed', ['status' => $response->status(), 'body' => $response->body()]);

            return self::FAILURE;
        }

        $remoteSets = collect($response->json('data'));

        if ($remoteSets->isEmpty()) {
            $this->warn('Remote returned 0 sets. Aborting to prevent accidental wipe.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $remoteUuids = $remoteSets->pluck('uuid')->all();
        $localSets = GraduationSet::query()->get();
        $matchedLocalIds = [];

        $toInsert = [];
        $toUpdate = [];
        $toDelete = [];

        foreach ($remoteSets as $remote) {
            $local = $this->findLocalSet($localSets, $remote);

            if (! $local) {
                $toInsert[] = $remote;

                continue;
            }

            $matchedLocalIds[] = $local->id;

            if ($this->hasChanges($local, $remote)) {
                $toUpdate[] = ['local' => $local, 'remote' => $remote];
            }
        }

        foreach ($localSets as $local) {
            if (in_array($local->id, $matchedLocalIds, true)) {
                continue;
            }

            if ($local->remote_uuid && ! in_array($local->remote_uuid, $remoteUuids, true)) {
                $toDelete[] = $local;
            }
        }

        $this->info(sprintf(
            'Changes detected — Insert: %d | Update: %d | Delete: %d',
            count($toInsert),
            count($toUpdate),
            count($toDelete)
        ));

        if ($dryRun) {
            $this->warn('[Dry Run] No changes written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toInsert, $toUpdate, $toDelete): void {
            foreach ($toInsert as $remote) {
                GraduationSet::query()->create($this->mapRemoteToLocal($remote));
                $this->line("  + Inserted: {$remote['uuid']}");
            }

            foreach ($toUpdate as $change) {
                $change['local']->update($this->mapRemoteToLocal($change['remote']));
                $this->line("  ~ Updated: {$change['remote']['uuid']}");
            }

            foreach ($toDelete as $local) {
                $local->delete();
                $this->line("  - Deleted: {$local->remote_uuid}");
            }
        });

        $this->info('Sync complete.');
        Log::info('sets:sync completed', [
            'inserted' => count($toInsert),
            'updated' => count($toUpdate),
            'deleted' => count($toDelete),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function findLocalSet(Collection $localSets, array $remote): ?GraduationSet
    {
        $remoteUuid = $remote['uuid'];

        $byRemoteUuid = $localSets->first(fn (GraduationSet $set): bool => $set->remote_uuid === $remoteUuid);
        if ($byRemoteUuid) {
            return $byRemoteUuid;
        }

        return $localSets->first(fn (GraduationSet $set): bool => $set->public_id === $remoteUuid);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function mapRemoteToLocal(array $remote): array
    {
        return [
            'remote_uuid' => $remote['uuid'],
            'public_id' => $remote['uuid'],
            'name' => $remote['name'],
            'start_year' => $remote['start_year'],
            'end_year' => $remote['end_year'],
            'set_number' => $remote['set_number'],
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function hasChanges(GraduationSet $local, array $remote): bool
    {
        return $local->remote_uuid !== $remote['uuid']
            || $local->name !== $remote['name']
            || $local->start_year != $remote['start_year']
            || $local->end_year != $remote['end_year']
            || $local->set_number !== $remote['set_number'];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FaceVideoFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FinalizeStaleFaceVideos extends Command
{
    protected $signature = 'face-videos:finalize-stale {--min-age=180 : Seconds since the last chunk before an upload counts as abandoned}';

    protected $description = 'Turn abandoned partial face-video uploads into playable files (or remove them if they hold no video)';

    public function handle(FaceVideoFinalizer $finalizer): int
    {
        $minAge = (int) $this->option('min-age');
        $total = 0;

        foreach (Storage::disk('local')->directories('face-video-chunks') as $dir) {
            $userId = (int) basename($dir);
            $user = User::withoutGlobalScopes()->find($userId);
            if (!$user) continue;

            $n = $finalizer->finalizeStaleUploads($user->id, $user->tenant_id, $minAge);
            if ($n > 0) $this->line("user {$user->id}: finalized {$n} upload(s)");
            $total += $n;
        }

        $this->info("Done. {$total} stale upload(s) handled.");
        return self::SUCCESS;
    }
}

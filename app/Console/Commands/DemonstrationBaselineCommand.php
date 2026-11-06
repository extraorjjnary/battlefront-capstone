<?php

namespace App\Console\Commands;

use App\Services\DemonstrationBaseline;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DemonstrationBaselineCommand extends Command
{
    protected $signature = 'battlefront:demo-baseline {--capture=} {--verify=} {--dump=} {--revision=} {--confirm-demo} {--quiesced}';

    protected $description = 'Capture or verify a demo baseline manifest without modifying databases or storage objects';

    public function handle(DemonstrationBaseline $baseline): int
    {
        try {
            $capture = $this->option('capture');
            $verify = $this->option('verify');
            $dump = $this->option('dump');
            $revision = $this->option('revision');
            if (! $this->option('confirm-demo') || ! $this->option('quiesced')
                || (filled($capture) === filled($verify)) || ! is_string($dump) || ! is_file($dump)
                || filesize($dump) === 0 || ! is_string($revision) || ! preg_match('/\A[0-9a-f]{40}\z/', $revision)) {
                throw new RuntimeException('Select capture or verify, provide a nonempty SQL dump and full commit SHA, and acknowledge --confirm-demo --quiesced.');
            }
            $this->assertArchivePath($dump);
            $this->assertArchivePath(is_string($capture) && $capture !== '' ? $capture : (string) $verify);
            $snapshot = $baseline->inspect();
            $manifest = ['revision' => $revision, 'dump_sha256' => hash_file('sha256', $dump), 'baseline' => $snapshot];
            if (is_string($verify) && $verify !== '') {
                $expected = json_decode((string) file_get_contents($verify), true, flags: JSON_THROW_ON_ERROR);
                if ($expected !== $manifest) {
                    throw new RuntimeException('Baseline verification failed: release, dump, database, schema or objects differ.');
                }
                $this->info('Baseline verified. This does not certify stopped workers, provider closure or Cloud resource isolation.');

                return self::SUCCESS;
            }
            if (! is_string($capture) || $capture === '') {
                throw new RuntimeException('A new manifest path is required.');
            }
            $handle = @fopen($capture, 'x');
            if ($handle === false) {
                throw new RuntimeException('Manifest must be a new writable file outside the baseline storage disks.');
            }
            try {
                $bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Manifest write failed; do not use the partial archive.');
                }
            } finally {
                fclose($handle);
            }
            $this->info('Baseline manifest captured. Protect it and the SQL dump in the operator archive; no database records changed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException && ! $exception instanceof QueryException
                ? $exception->getMessage() : 'Baseline inspection failed. No database records were changed.');

            return self::FAILURE;
        }
    }

    private function assertArchivePath(string $path): void
    {
        $parent = realpath(dirname($path));
        if ($parent === false || is_link($path)) {
            throw new RuntimeException('Archive paths require an existing private directory and no symlink.');
        }
        $resolved = str_replace('\\', '/', $parent).'/'.basename($path);
        $roots = [public_path()];
        foreach (['local', 'public'] as $disk) {
            $storage = Storage::disk($disk);
            if (($storage->getConfig()['driver'] ?? null) === 'local') {
                $roots[] = $storage->path('');
            }
        }
        foreach ($roots as $root) {
            $root = realpath($root);
            if ($root !== false && str_starts_with(strtolower($resolved), strtolower(rtrim(str_replace('\\', '/', $root), '/')).'/')) {
                throw new RuntimeException('SQL and manifest files must be outside public paths and baseline storage disks.');
            }
        }
    }
}

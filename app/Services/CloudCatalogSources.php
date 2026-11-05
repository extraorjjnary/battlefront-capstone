<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class CloudCatalogSources
{
    /** @param array<string, string> $hashes */
    public function using(string $batch, array $hashes, \Closure $callback): mixed
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $batch)) {
            throw new \RuntimeException('Invalid catalog batch identifier.');
        }
        $paths = [];
        try {
            foreach ($hashes as $name => $hash) {
                if (! in_array($name, ['manifest.json', 'verified-product-details.csv', 'development-value-approvals.json'], true)
                    || ! preg_match('/^[a-fA-F0-9]{64}$/D', $hash)) {
                    throw new \RuntimeException('Every source requires its original SHA-256 fingerprint.');
                }
                $bytes = Storage::disk('private')->get("catalog-imports/$batch/$name");
                if (! is_string($bytes) || ! hash_equals(strtolower($hash), hash('sha256', $bytes))) {
                    throw new \RuntimeException('Private catalog source is missing or its fingerprint differs.');
                }
                $path = tempnam(sys_get_temp_dir(), 'battlefront-catalog-');
                if ($path === false) {
                    throw new \RuntimeException('Cannot stage the private catalog source.');
                }
                $paths[$name] = $path;
                if (! chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('Cannot stage the complete private catalog source.');
                }
            }

            return $callback($paths);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}

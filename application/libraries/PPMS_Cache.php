<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PPMS_Cache Library
 *
 * Single cache manager for the entire PPMS module.
 * All cache reads and writes go through here — never directly to CI cache.
 *
 * Three storage tiers (fastest to slowest):
 *
 *   Tier 1 — APC/APCu (microseconds)
 *     Best option. Available on most PHP 7.4+ servers.
 *     Shared across requests, survives between requests, no disk I/O.
 *
 *   Tier 2 — CI File cache (milliseconds)
 *     Falls back automatically if APCu not available.
 *     Serialized PHP files in application/cache/.
 *     Still fast — much faster than re-parsing CSVs or re-querying.
 *
 *   Tier 3 — Runtime ($this->mem, PHP array)
 *     Per-request in-memory store. Zero cost on repeat access within
 *     the same HTTP request regardless of which other tier is used.
 *
 * Cache key prefixes:
 *   ppms_csv_*       CSV parsed data      TTL: 1 hour  (invalidated by file mtime)
 *   ppms_proj_*      Project list/detail  TTL: 5 min
 *   ppms_sec_*       Section data         TTL: 1 min
 *   ppms_prog_*      Progress aggregates  TTL: 1 min
 *   ppms_ratings_*   Consecutive ratings  TTL: 1 hour  (rarely changes)
 *   ppms_ratios_*    Section D ratios     TTL: 5 min
 *
 * Invalidation:
 *   - CSV cache: auto (mtime key)
 *   - Project/section cache: explicit on save (invalidate($project_no))
 */
class PPMS_Cache
{
    /** @var CI_Controller */
    private $CI;

    /** @var array  Per-request memory (always populated on first miss) */
    private $mem = [];

    /** @var bool  Whether APCu is available */
    private $has_apcu;

    /** @var bool  Whether CI file cache is available */
    private $has_file_cache;

    /** @var string  Prefix for all PPMS cache keys to avoid collision with host app */
    const PREFIX = 'ppms_';

    // TTLs in seconds
    const TTL_CSV      = 3600;  // 1 hour   — CSV parsed data
    const TTL_PROJECTS = 300;   // 5 min    — project list / detail
    const TTL_SECTION  = 60;    // 1 min    — section data
    const TTL_PROGRESS = 60;    // 1 min    — progress aggregates
    const TTL_RATINGS  = 3600;  // 1 hour   — historical ratings (stable)
    const TTL_RATIOS   = 300;   // 5 min    — computed ratios

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->has_apcu = function_exists('apcu_fetch') && apcu_enabled();

        // Try to initialise CI file cache (graceful fail)
        try {
            // Set cache path explicitly — CI3 default is APPPATH.'cache/'
            // which is application/cache/. Must be writable.
            $cache_dir = APPPATH . 'cache/';
            if (!is_dir($cache_dir)) {
                @mkdir($cache_dir, 0755, true);
            }
            $this->CI->load->driver('cache', [
                'adapter'   => 'file',
                'backup'    => 'dummy',
                'cache_path'=> $cache_dir,
            ]);
            // Verify it actually works with a test write
            $test_key = self::PREFIX . '_init_test';
            $this->CI->cache->save($test_key, 1, 10);
            $ok = $this->CI->cache->get($test_key);
            $this->CI->cache->delete($test_key);
            $this->has_file_cache = ($ok !== false);
        } catch (Exception $e) {
            log_message('error', 'PPMS_Cache file driver failed: ' . $e->getMessage());
            $this->has_file_cache = false;
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Get a cached value. Returns null on miss.
     *
     * @param  string $key
     * @return mixed|null
     */
    public function get($key)
    {
        $full = self::PREFIX . $key;

        // Tier 1: runtime
        if (array_key_exists($full, $this->mem)) {
            return $this->mem[$full];
        }

        // Tier 2: APCu
        if ($this->has_apcu) {
            $val = apcu_fetch($full, $success);
            if ($success) {
                $this->mem[$full] = $val;
                return $val;
            }
        }

        // Tier 3: CI file cache
        if ($this->has_file_cache) {
            $val = $this->CI->cache->get($full);
            if ($val !== false) {
                $this->mem[$full] = $val;
                return $val;
            }
        }

        return null;
    }

    /**
     * Store a value in all available cache tiers.
     *
     * @param  string $key
     * @param  mixed  $value
     * @param  int    $ttl   Seconds
     */
    public function set($key, $value, $ttl = self::TTL_PROJECTS)
    {
        $full = self::PREFIX . $key;

        $this->mem[$full] = $value;

        if ($this->has_apcu) {
            apcu_store($full, $value, $ttl);
        }

        if ($this->has_file_cache) {
            $this->CI->cache->save($full, $value, $ttl);
        }
    }

    /**
     * Delete a specific key from all tiers.
     *
     * @param  string $key
     */
    public function delete($key)
    {
        $full = self::PREFIX . $key;
        unset($this->mem[$full]);
        if ($this->has_apcu)       apcu_delete($full);
        if ($this->has_file_cache) $this->CI->cache->delete($full);
    }

    /**
     * Invalidate all cache entries for a specific project.
     * Call this after any save operation.
     *
     * @param  string $project_no
     */
    public function invalidate_project($project_no)
    {
        $patterns = [
            'proj_' . $project_no,
            'sec_'  . $project_no,
            'prog_' . $project_no,
            'ratios_' . $project_no,
        ];

        foreach ($patterns as $pattern) {
            $this->delete($pattern);
        }

        // Also clear runtime keys matching project_no
        foreach (array_keys($this->mem) as $k) {
            if (strpos($k, self::PREFIX . 'proj_' . $project_no) !== false ||
                strpos($k, self::PREFIX . 'sec_'  . $project_no) !== false) {
                unset($this->mem[$k]);
            }
        }
    }

    /**
     * Invalidate all PPMS caches (call after uploading new CSVs).
     */
    public function flush_all()
    {
        $this->mem = [];

        if ($this->has_apcu) {
            // Only delete keys with our prefix
            $info = apcu_cache_info();
            foreach ($info['cache_list'] ?? [] as $entry) {
                if (strpos($entry['info'], self::PREFIX) === 0) {
                    apcu_delete($entry['info']);
                }
            }
        }

        if ($this->has_file_cache) {
            $this->CI->cache->clean();
        }
    }

    /**
     * Build a cache key for CSV data (auto-invalidates when file changes).
     * NORM_VERSION is bumped whenever normalization logic changes (e.g. aliases)
     * so stale cached rows with old values are discarded automatically.
     *
     * @param  string $filepath
     * @return string
     */
    const NORM_VERSION = 11;  // bump when CSV_reader normalization logic changes

    public function csv_key($filepath)
    {
        $mtime = file_exists($filepath) ? filemtime($filepath) : 0;
        return 'csv_' . md5($filepath) . '_' . $mtime . '_v' . self::NORM_VERSION;
    }

    /**
     * Build a section-specific cache key.
     */
    public function section_key($project_no, $section_key)
    {
        return 'sec_' . $project_no . '_' . $section_key;
    }

    /**
     * Report which cache tiers are active (useful for debugging).
     *
     * @return array
     */
    public function status()
    {
        return [
            'apcu'       => $this->has_apcu,
            'file_cache' => $this->has_file_cache,
            'runtime'    => true,
            'mem_keys'   => count($this->mem),
        ];
    }
}

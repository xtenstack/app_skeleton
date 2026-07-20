<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Read-cached access to the global `settings` table, exposed as the
 * `settings` DI service ($this->settings in any controller). Loaded once
 * per request on first access, not eagerly, since settings change rarely
 * and most requests don't need every key.
 */
class SettingsRegistry extends Injectable
{
    private ?array $cache = null;

    public function get(string $key, $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $setting = \Settings::findFirst([
            'conditions' => 'setting_key = :key:',
            'bind'       => ['key' => $key],
        ]);

        if (!$setting) {
            $setting              = new \Settings();
            $setting->setting_key = $key;
        }

        $setting->setting_value = $value;
        $setting->save();

        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];

            foreach (\Settings::find() as $setting) {
                $this->cache[$setting->setting_key] = $setting->setting_value;
            }
        }

        return $this->cache;
    }
}

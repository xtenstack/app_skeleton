<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use App_skeleton\Crypto;

/**
 * Usage: ./run crypto status
 *        ./run crypto rekey dry-run
 *        ./run crypto rekey run <stash-file>
 *
 * Moves every encrypted value on this instance from the current key in
 * .encryption_key (an empty legacy key included — see Crypto's docblock)
 * to a freshly generated one.
 *
 * Where the ciphertexts are: core's external_connections.credential, plus
 * any column an installed module declares in its module.json:
 *
 *     "encrypted_columns": [{"table": "lin_connections", "column": "access_token_enc"}]
 *
 * (primary key `id` assumed). Tables that don't exist on this instance —
 * a module installed but never migrated — are skipped.
 *
 * Order, chosen so no crash can strand the data:
 *   1. Decrypt every value with the current key. Any failure → stop,
 *      nothing changed.
 *   2. Write the new key to <stash-file> and read it back. Must be on
 *      persistent storage outside the DB backup folder (the deploy mounts
 *      ./data at /rekey-data for this).
 *   3. One DB transaction: re-encrypt every value with the new key.
 *   4. Overwrite .encryption_key IN PLACE with the new key. In place, not
 *      rename: under Docker the file is a single-file bind mount, and the
 *      running app container only sees writes to the same inode.
 *   5. Re-read every value through the normal Crypto::decrypt() path and
 *      compare SHA-256s of the plaintexts with step 1.
 * If anything fails after step 3 commits, the stash file holds the key
 * that matches the data: copy it over .encryption_key.
 *
 * Plaintexts are never printed — only counts and short SHA-256 prefixes.
 * Delete the stash file once the key is backed up somewhere safe.
 */
class CryptoTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run crypto status | rekey dry-run | rekey run <stash-file>' . PHP_EOL;
    }

    public function statusAction(): void
    {
        $raw = Crypto::readRawKey();

        echo 'Key file: ' . Crypto::keyPath() . ' — ' . ($raw === null ? 'missing/unreadable' : strlen($raw) . ' bytes')
            . ($raw !== null && strlen($raw) === Crypto::KEY_BYTES ? ' (OK)' : ' (NOT a valid key — run rekey)') . PHP_EOL;

        foreach ($this->targets() as $t) {
            echo "  {$t['table']}.{$t['column']}: " . count($this->rows($t)) . ' encrypted value(s)' . PHP_EOL;
        }
    }

    public function rekeyAction($mode = null, $stashFile = null): void
    {
        if (!in_array($mode, ['dry-run', 'run'], true) || ($mode === 'run' && !$stashFile)) {
            echo 'Usage: ./run crypto rekey dry-run | rekey run <stash-file>' . PHP_EOL;
            exit(2);
        }

        $oldKey = Crypto::readRawKey();

        if ($oldKey === null) {
            $this->fail('No readable key file at ' . Crypto::keyPath() . ' — nothing to rekey from.');
        }

        // 1. Decrypt everything with the current key.
        $plain = [];

        foreach ($this->targets() as $t) {
            foreach ($this->rows($t) as $row) {
                $value = Crypto::decryptWithKey((string) $row['v'], $oldKey);

                if ($value === null) {
                    $this->fail("Could not decrypt {$t['table']}.{$t['column']} id {$row['id']} with the current key — stopping, nothing changed.");
                }

                $plain[] = ['t' => $t, 'id' => $row['id'], 'value' => $value, 'sha' => hash('sha256', $value)];
            }
        }

        echo 'Current key: ' . strlen($oldKey) . ' bytes. ' . count($plain) . ' value(s) decrypt with it:' . PHP_EOL;

        foreach ($plain as $p) {
            echo "  {$p['t']['table']}.{$p['t']['column']} id {$p['id']}  sha256 " . substr($p['sha'], 0, 12) . PHP_EOL;
        }

        if ($mode === 'dry-run') {
            echo 'Dry run — nothing changed.' . PHP_EOL;

            return;
        }

        // 2. New key, stashed and read back before anything is re-encrypted.
        $newKey = random_bytes(Crypto::KEY_BYTES);

        if (file_exists($stashFile)) {
            $this->fail("Stash file {$stashFile} already exists — refusing to overwrite a key that may be the only copy.");
        }

        if (file_put_contents($stashFile, $newKey) !== Crypto::KEY_BYTES || !chmod($stashFile, 0600) || file_get_contents($stashFile) !== $newKey) {
            $this->fail("Could not write the new key to {$stashFile} — nothing changed.");
        }

        echo "New key stashed at {$stashFile}." . PHP_EOL;

        // 3. Re-encrypt in one transaction.
        $db = $this->getDI()->getShared('db');
        $db->begin();

        try {
            foreach ($plain as $p) {
                $ok = $db->execute(
                    "UPDATE {$p['t']['table']} SET {$p['t']['column']} = ? WHERE id = ?",
                    [Crypto::encryptWithKey($p['value'], $newKey), $p['id']]
                );

                if (!$ok || $db->affectedRows() !== 1) {
                    throw new \RuntimeException("Update of {$p['t']['table']} id {$p['id']} did not affect exactly one row");
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            @unlink($stashFile);
            $this->fail('Re-encryption failed and was rolled back (old key still valid): ' . $e->getMessage());
        }

        echo 'Re-encrypted ' . count($plain) . ' value(s) — committed.' . PHP_EOL;

        // 4. Swap the live key in place (same inode).
        $keyPath = Crypto::keyPath();
        $handle  = @fopen($keyPath, 'r+');

        if ($handle === false || !ftruncate($handle, 0) || fwrite($handle, $newKey) !== Crypto::KEY_BYTES || !fflush($handle)) {
            $this->fail("DATA IS ON THE NEW KEY but {$keyPath} could not be written. Copy {$stashFile} over {$keyPath} now.");
        }

        fclose($handle);
        @chmod($keyPath, 0640);
        clearstatcache();

        // 5. Verify through the normal path.
        $bad = 0;

        foreach ($plain as $p) {
            $row   = $db->fetchOne("SELECT {$p['t']['column']} AS v FROM {$p['t']['table']} WHERE id = ?", \Phalcon\Db\Enum::FETCH_ASSOC, [$p['id']]);
            $value = $row ? Crypto::decrypt((string) $row['v']) : null;

            if ($value === null || !hash_equals($p['sha'], hash('sha256', $value))) {
                $bad++;
                echo "  MISMATCH {$p['t']['table']} id {$p['id']}" . PHP_EOL;
            }
        }

        if ($bad > 0) {
            $this->fail("{$bad} value(s) did not verify. The stash file {$stashFile} holds the key matching the data.");
        }

        echo 'Verified: all ' . count($plain) . " value(s) decrypt with the new key and match. Key file: {$keyPath} (" . Crypto::KEY_BYTES . ' bytes).' . PHP_EOL;
        echo "Now back up {$stashFile} somewhere safe (NOT next to DB backups), then delete it." . PHP_EOL;
    }

    /** @return list<array{table: string, column: string}> */
    private function targets(): array
    {
        $targets = [['table' => 'external_connections', 'column' => 'credential']];

        foreach ($this->moduleManager->discover() as $manifest) {
            foreach ((array) ($manifest['encrypted_columns'] ?? []) as $col) {
                $targets[] = ['table' => (string) ($col['table'] ?? ''), 'column' => (string) ($col['column'] ?? '')];
            }
        }

        $valid = [];

        foreach ($targets as $t) {
            // Identifiers come from module.json, so allow plain names only.
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $t['table']) || !preg_match('/^[a-z_][a-z0-9_]*$/', $t['column'])) {
                echo "  skipping invalid encrypted_columns entry " . json_encode($t) . PHP_EOL;
                continue;
            }

            if ($this->tableExists($t['table'])) {
                $valid[] = $t;
            }
        }

        return $valid;
    }

    private function rows(array $t): array
    {
        return $this->getDI()->getShared('db')->fetchAll(
            "SELECT id, {$t['column']} AS v FROM {$t['table']} WHERE {$t['column']} IS NOT NULL AND {$t['column']} != '' ORDER BY id",
            \Phalcon\Db\Enum::FETCH_ASSOC
        );
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->getDI()->getShared('db')->tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function fail(string $message): never
    {
        fwrite(STDERR, 'crypto rekey: ' . $message . PHP_EOL);
        exit(1);
    }
}

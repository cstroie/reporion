<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use DateTimeZone;
use FFI;
use PDO;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Service\Backup\RsyncBackup;
use Reporion\Service\Backup\RsyncConfig;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Library\Extractor;
use Reporion\Service\TagDictionary;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\TemplateHeadings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * bin/reporion doctor (docs/deploy-lighttpd.md "Checks"). Every check here
 * traces back to something that actually broke this session and had no
 * automated way to catch it before now: see docs/BUILD_LOG.md.
 *
 * Two checks are explicitly best-effort and say so in their own output,
 * rather than claiming a guarantee they can't provide:
 *
 *  - The FFI check only proves FFI works from THIS process (bin/reporion
 *    doctor runs as plain CLI, which trusts FFI::cdef() regardless of
 *    ffi.enable: confirmed empirically, see Support\Fsync). It cannot see
 *    whether the web SAPI (PHP-FPM, or php -S) has ffi.enable=1 set; that
 *    is a live-server-only property with no CLI-observable proxy.
 *  - The "docroot" check is a live HTTP request to site.base_url +
 *    "/.git/config" and "/composer.json" and only means anything if base_url is
 *    the deployment's real, reachable URL, not the example.ro placeholder
 *    conf/local.php.example ships with.
 */
final class DoctorCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?string $localConfigFile = null,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Checks the configuration, permissions, SQLite, PHP extensions and the deployment, and prints PASS, WARN or FAIL for each.',
            usage: '',
            details: 'Exit 1 only on FAIL; a WARN never fails the run.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $checks = [
            $this->checkPhpVersion(),
            $this->checkExtensions(),
            $this->checkArgon2(),
            $this->checkFts5(),
            $this->checkOwnerAccountExists(),
            $this->checkGroupReferences(),
            $this->checkSessionSecret(),
            $this->checkLocalConfigPermissions(),
            $this->checkDataWritable(),
            $this->checkAuditWritable(),
            $this->checkFfiFromThisProcess(),
            $this->checkTimezone(),
            $this->checkDataYamlReadable(),
            $this->checkAiSettingsShape(),
            $this->checkAiCorePrompts(),
            $this->checkTemplateShape(),
            $this->checkExamTemplates(),
            $this->checkLocalConfigIsStrict(),
            $this->checkDocrootExposure(),
            $this->checkFrontControllerReachable(),
            $this->checkIntegrityVerified(),
            $this->checkStateDatabase(),
            $this->checkRsyncBackup(),
            $this->checkLibraryTools(),
            $this->checkLibraryLookup(),
        ];

        $failed = false;
        foreach ($checks as $check) {
            $tag = match ($check->status) {
                CheckStatus::Pass => 'PASS',
                CheckStatus::Warn => 'WARN',
                CheckStatus::Fail => 'FAIL',
            };
            $line = "[{$tag}] {$check->label}";
            if ($check->detail !== '') {
                $line .= ': ' . $check->detail;
            }
            $output->line($line);
            $failed = $failed || $check->status === CheckStatus::Fail;
        }

        return $failed ? 1 : 0;
    }

    private function checkPhpVersion(): CheckResult
    {
        return PHP_VERSION_ID >= 80100
            ? CheckResult::pass('PHP version', PHP_VERSION)
            : CheckResult::fail('PHP version', PHP_VERSION . ': 8.1+ required (D23)');
    }

    private function checkExtensions(): CheckResult
    {
        $required = ['pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'zlib', 'dom', 'xml', 'iconv', 'gd', 'ffi'];
        $missing = array_values(array_filter($required, static fn (string $ext): bool => !\extension_loaded($ext)));

        return $missing === []
            ? CheckResult::pass('PHP extensions', implode(', ', $required))
            : CheckResult::fail('PHP extensions', 'missing: ' . implode(', ', $missing));
    }

    private function checkArgon2(): CheckResult
    {
        return \defined('PASSWORD_ARGON2ID')
            ? CheckResult::pass('Argon2id password hashing')
            : CheckResult::fail('Argon2id password hashing', 'this PHP was built without Argon2 (PASSWORD_ARGON2ID): accounts cannot be created or signed in (D35)');
    }

    private function checkFts5(): CheckResult
    {
        try {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE VIRTUAL TABLE t USING fts5(x)');

            return CheckResult::pass('SQLite FTS5');
        } catch (Throwable $e) {
            return CheckResult::fail('SQLite FTS5', 'CREATE VIRTUAL TABLE ... USING fts5 failed: ' . $e->getMessage());
        }
    }

    /**
     * D35 superseded auth.owner_password_hash: this now checks the thing
     * that actually determines whether anyone can sign in: at least one
     * active owner account in data/users/. Constructing FlatFileUserStore
     * does no I/O by itself (unlike Index\Sqlite), so this is safe to run
     * unconditionally, the same as every other check here.
     */
    private function checkOwnerAccountExists(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::fail('At least one active owner account exists', $this->missingDataDetail($dataDir));
        }

        $users = new FlatFileUserStore($dataDir);
        foreach ($users->all() as $user) {
            if ($user->isOwner && $user->active) {
                return CheckResult::pass('At least one active owner account exists');
            }
        }

        return CheckResult::fail(
            'At least one active owner account exists',
            'no active owner in data/users/: create one with: '
                . 'bin/reporion user:create --username=<u> --password-hash="$(php -r \'echo password_hash("…", PASSWORD_ARGON2ID);\')" --owner'
                . ' (quote --password-hash: an unquoted argon2id hash contains $ characters the shell will try to expand)'
        );
    }

    /**
     * D47: an account naming a group with no record in data/groups/: harmless (it resolves to
     * nothing), but almost certainly an owner's slip: a deleted group still ticked, or a typo
     * in user:create --group. Named here so it gets cleaned up.
     */
    private function checkGroupReferences(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::pass('Every group an account names exists');
        }

        $known = array_map(static fn (\Reporion\Auth\Group $g): string => $g->name, (new \Reporion\Auth\FlatFileGroupStore($dataDir))->all());
        $missing = [];
        foreach ((new FlatFileUserStore($dataDir))->all() as $user) {
            foreach (array_diff($user->groups, $known) as $group) {
                $missing[] = $user->username . ' → @' . $group;
            }
        }

        return $missing === []
            ? CheckResult::pass('Every group an account names exists')
            : CheckResult::warn('Every group an account names exists', 'no such group (it gives nothing): ' . implode(', ', $missing) . ': untick it in Admin → Users or create the group');
    }

    private function checkSessionSecret(): CheckResult
    {
        $secret = (string) ($this->config['auth']['session_secret'] ?? '');

        return $secret !== ''
            ? CheckResult::pass('Session secret configured')
            : CheckResult::fail('Session secret configured', 'auth.session_secret is empty in conf/local.php');
    }

    /**
     * conf/local.php holds auth.session_secret (and any API keys): readable
     * by the web user and the owner, by nobody else. Skipped when the file
     * is not there (a config assembled another way).
     */
    private function checkLocalConfigPermissions(): CheckResult
    {
        $label = 'conf/local.php not world-readable';
        $file = $this->localConfigFile ?? \dirname(__DIR__, 2) . '/conf/local.php';
        if (!is_file($file)) {
            return CheckResult::pass($label, 'no conf/local.php');
        }
        $mode = (int) (fileperms($file) & 0777);

        return ($mode & 0004) === 0
            ? CheckResult::pass($label, sprintf('mode %04o', $mode))
            : CheckResult::warn($label, sprintf('mode %04o: the session secret is readable by every local user: chmod 640 conf/local.php', $mode));
    }

    private function missingDataDetail(string $dataDir): string
    {
        return 'paths.data does not exist: ' . $dataDir
            . ': create it, owned by the web user and group-writable (INSTALL.md, "Create data/")';
    }

    private function checkDataWritable(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::fail('data/ writable', $this->missingDataDetail($dataDir));
        }

        // A real write, not just is_writable(): tonight's own permission
        // mismatch (costin vs www-data group ownership) would have passed
        // an is_writable() check on the directory while still failing an
        // actual file creation, depending on which process asks.
        $probe = $dataDir . '/.doctor-write-test-' . bin2hex(random_bytes(4));
        $canWrite = @file_put_contents($probe, 'x') !== false;
        if ($canWrite) {
            @unlink($probe);
        }

        return $canWrite
            ? CheckResult::pass('data/ writable')
            : CheckResult::fail('data/ writable', 'could not create a file in ' . $dataDir . ' as the current process user');
    }

    /**
     * The audit log fails quietly by design (Audit\AuditLog: a completed
     * write must not turn into an error), so this is where a directory the
     * web user cannot append to shows up. Created if missing, then a real
     * write, same reasoning as checkDataWritable().
     */
    private function checkAuditWritable(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::fail('data/audit writable', $this->missingDataDetail($dataDir));
        }

        $dir = (string) ($this->config['paths']['audit'] ?? ($dataDir . '/audit'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return CheckResult::fail('data/audit writable', 'cannot create ' . $dir);
        }

        $probe = $dir . '/.doctor-write-test-' . bin2hex(random_bytes(4));
        $canWrite = @file_put_contents($probe, 'x') !== false;
        if ($canWrite) {
            @unlink($probe);
        }

        // The file AuditLog appends to may exist, owned by another user: a new file in the
        // directory is writable while every line to that one is dropped
        $current = $dir . '/' . date('Y-m') . '.ndjson';
        if ($canWrite && is_file($current) && !is_writable($current)) {
            return CheckResult::fail('data/audit writable', 'the current audit file is not writable as the current process user: audit lines would be dropped');
        }

        return $canWrite
            ? CheckResult::pass('data/audit writable')
            : CheckResult::fail('data/audit writable', 'could not create a file in ' . $dir . ' as the current process user: audit lines would be dropped');
    }

    private function checkFfiFromThisProcess(): CheckResult
    {
        try {
            $ffi = FFI::cdef('int fsync(int fd);', 'libc.so.6');
            unset($ffi);

            return CheckResult::pass(
                'FFI usable from this process',
                'CLI only: does NOT prove the web SAPI has ffi.enable=1; see docs/deploy-lighttpd.md'
            );
        } catch (Throwable $e) {
            return CheckResult::fail('FFI usable from this process', $e->getMessage());
        }
    }

    private function checkTimezone(): CheckResult
    {
        $tz = (string) ($this->config['site']['timezone'] ?? '');
        if ($tz === '') {
            return CheckResult::fail('Timezone configured', 'site.timezone is empty: Admin → Settings');
        }

        return \in_array($tz, DateTimeZone::listIdentifiers(), true)
            ? CheckResult::pass('Timezone configured', $tz)
            : CheckResult::fail('Timezone configured', "'{$tz}' is not a valid timezone identifier");
    }

    /**
     * data/settings.yaml and data/tags.yaml read as empty when they do not
     * parse (the site keeps working on its fallbacks) and the admin screens
     * refuse to save over them: this is where the owner hears of it.
     */
    private function checkDataYamlReadable(): CheckResult
    {
        $label = 'data/settings.yaml and data/tags.yaml parse';
        $data = (string) ($this->config['paths']['data'] ?? '');
        $broken = [];
        foreach ([InstanceSettings::FILE, TagDictionary::FILE] as $name) {
            $file = $data . '/' . $name;
            if ($data === '' || !is_file($file)) {
                continue;
            }
            try {
                Yaml::parse((string) file_get_contents($file));
            } catch (ParseException $e) {
                $broken[] = 'data/' . $name . ' (line ' . $e->getParsedLine() . ')';
            }
        }

        return $broken === []
            ? CheckResult::pass($label)
            : CheckResult::fail($label, 'not valid YAML: ' . implode(', ', $broken) . ': read as empty, and Admin will not save over it until fixed');
    }

    /**
     * Reports whose exams name no template (D48: each exam has its own; without one it gets no
     * checklist or references). Unsigned reports only: a signed one is not edited for it (D3).
     * A warning, never a failure (D7). Reads the files, not the index (invariant 1).
     */
    private function checkExamTemplates(): CheckResult
    {
        $label = 'Report exams with their own template';
        $root = (string) ($this->config['paths']['data'] ?? '') . '/pages/reports';
        if (!is_dir($root)) {
            return CheckResult::pass($label);
        }
        $reports = 0;
        $without = 0;
        $signed = $this->signedPaths();
        foreach (self::pageFiles($root, 'reports') as $path => $file) {
            if (isset($signed[$path])) {
                continue;
            }
            try {
                [$frontmatter] = DocumentFormat::parse((string) file_get_contents($file));
            } catch (Throwable) {
                continue; // an unparseable page is integrity:verify's to report
            }
            ++$reports;
            foreach (Exams::of($frontmatter) as $exam) {
                if (MetaText::text($exam['template'] ?? null) === '') {
                    ++$without;
                    break;
                }
            }
        }

        return $without === 0
            ? CheckResult::pass($label)
            : CheckResult::warn($label, $without . ' of ' . $reports . ' unsigned reports have an exam with no template: no checklist or references for it; pick one in the editor\'s Metadata view');
    }

    /**
     * The page files under $root (data/pages/{ns}, D54: `{slug}.md` each),
     * colon path => file. Read straight from disk (invariant 1).
     *
     * @return iterable<string, string>
     */
    private static function pageFiles(string $root, string $ns): iterable
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.md')) {
                yield $ns . ':' . str_replace('/', ':', substr($item->getPathname(), \strlen($root) + 1, -3)) => $item->getPathname();
            }
        }
    }

    /** @return array<string, true> the live signed pages, from data/reporion.sqlite (read only) */
    private function signedPaths(): array
    {
        $file = (string) ($this->config['paths']['state'] ?? (($this->config['paths']['data'] ?? '') . '/reporion.sqlite'));
        if (!is_file($file)) {
            return [];
        }
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $paths = $pdo->query("SELECT path FROM page WHERE status = 'signed' AND trashed_at IS NULL")?->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_fill_keys(array_map('strval', $paths), true);
    }

    /**
     * Template pages (data/pages/templates, snippets left out) in the shape
     * D45 asks: one `#` (the template's name), one `##` (the exam), in that
     * order. A warning, never a failure: the shape is advice, and a template
     * of the old shape still starts a report.
     */
    private function checkTemplateShape(): CheckResult
    {
        $label = 'Template pages in the D45 shape';
        $root = (string) ($this->config['paths']['data'] ?? '') . '/pages/templates';
        if (!is_dir($root)) {
            return CheckResult::pass($label);
        }
        $total = 0;
        $off = 0;
        foreach (self::pageFiles($root, 'templates') as $path => $file) {
            if (str_starts_with($path, 'templates:snippets:')) {
                continue;
            }
            try {
                [$frontmatter, $body] = DocumentFormat::parse((string) file_get_contents($file));
                ++$total;
                $off += TemplateHeadings::problems($frontmatter, $body) === [] ? 0 : 1;
            } catch (\Throwable) {
                // an unparseable page is integrity:verify's to report
            }
        }

        return $off === 0
            ? CheckResult::pass($label)
            : CheckResult::warn($label, $off . ' of ' . $total . ' templates are not one `#` name + one `##` exam: they still work (the exam falls back to the title); see FORMATS "A template page"');
    }

    /**
     * The flat AI keys (`ai.endpoint`, `ai.model`, …) and the `ai.profiles`
     * map are no longer read: only `ai.servers` and `ai.prompt_profile` are.
     * Either left in conf/local.php or data/settings.yaml means the
     * assistant is not configured the way the file suggests.
     */
    private function checkAiSettingsShape(): CheckResult
    {
        $label = 'AI settings in the current shape';
        $old = ['endpoint', 'model', 'model_lite', 'model_expert', 'api_key', 'temperature', 'top_p', 'max_tokens', 'profiles', 'allow_egress_to'];
        $data = (string) ($this->config['paths']['data'] ?? '');
        $stored = $data !== '' ? ((new InstanceSettings($data))->load()['ai'] ?? []) : [];
        $found = [];
        foreach (['conf/local.php' => $this->config['ai'] ?? [], 'data/settings.yaml' => $stored] as $where => $ai) {
            foreach (\is_array($ai) ? array_keys(array_intersect_key($ai, array_flip($old))) : [] as $key) {
                $found[] = $where . ': ai.' . $key;
            }
        }

        return $found === []
            ? CheckResult::pass($label)
            : CheckResult::warn($label, 'ignored old keys (' . implode(', ', $found) . '): set the servers again in Admin → AI and remove them');
    }

    /**
     * With the assistant set up, the profile in use should hold the core
     * shipped prompts (DefaultPrompts::CORE): without them the rail is
     * empty and Summarize / Suggest tags are not offered. Read from the
     * page directories, like the template checks.
     */
    private function checkAiCorePrompts(): CheckResult
    {
        $label = 'The assistant\'s profile has the core prompts';
        $ai = AiConfig::fromConfig($this->config);
        if (!$ai->isConfigured()) {
            return CheckResult::pass($label . ' (assistant not set up)');
        }
        $data = (string) ($this->config['paths']['data'] ?? '');
        $missing = [];
        foreach (DefaultPrompts::CORE as $id) {
            $dir = $data . '/pages/' . str_replace(':', '/', DefaultPrompts::pathOf($ai->promptProfile, $id));
            if (!is_file($dir . '/current.md')) {
                $missing[] = $id;
            }
        }

        return $missing === []
            ? CheckResult::pass($label)
            : CheckResult::warn($label, 'ai:profiles:' . $ai->promptProfile . ' lacks ' . implode(', ', $missing) . ': Admin → AI, or bin/reporion ai:prompts-install --profile=' . $ai->promptProfile);
    }

    /**
     * conf/local.php is for the data directory and the session secret
     * alone. Any other section it still carries is read as a fallback
     * under data/settings.yaml, but belongs in Admin → Settings.
     */
    private function checkLocalConfigIsStrict(): CheckResult
    {
        $label = 'conf/local.php holds only paths and the session secret';
        $file = $this->localConfigFile ?? \dirname(__DIR__, 2) . '/conf/local.php';
        $local = is_file($file) ? require $file : [];
        $extra = \is_array($local) ? array_values(array_diff(array_keys($local), ['auth', 'paths'])) : [];

        return $extra === []
            ? CheckResult::pass($label)
            : CheckResult::warn($label, 'still carries ' . implode(', ', $extra) . ': fallbacks only; set them in Admin → Settings and remove them from the file');
    }

    /**
     * Nothing outside public/ may be served: data/, conf/, plugins/ and .git/
     * sit beside it, so a docroot set one level too high exposes them all.
     * One probe for each tell-tale file of the repository root.
     */
    private function checkDocrootExposure(): CheckResult
    {
        $label = 'Docroot is public/ only (data/, conf/, .git/ not served)';
        $baseUrl = (string) ($this->config['site']['base_url'] ?? '');
        if ($baseUrl === '') {
            return CheckResult::warn($label, 'site.base_url is empty, skipped');
        }

        // Status 200 alone is ambiguous: the app's own /{path} route matches
        // ANY path and answers 200 or 404 as readily as a broken docroot, so
        // the body decides. "[core]" is the signature of a real .git/config;
        // the package name is the signature of the repository's composer.json.
        $probes = [
            '/.git/config' => static fn (int $status, string $body): bool => $status === 200 && str_contains($body, '[core]'),
            '/composer.json' => static fn (int $status, string $body): bool => $status === 200 && str_contains($body, '"reporion/reporion"'),
        ];
        $seen = [];
        $leaked = [];
        foreach ($probes as $path => $leaks) {
            $result = $this->httpGet(rtrim($baseUrl, '/') . $path);
            if ($result === null) {
                return CheckResult::warn($label, 'could not reach ' . $baseUrl . ': best-effort check skipped');
            }
            $seen[] = "{$path} → HTTP {$result[0]}";
            if ($leaks($result[0], $result[1])) {
                $leaked[] = $path;
            }
        }

        return $leaked === []
            ? CheckResult::pass($label, implode(', ', $seen))
            : CheckResult::fail($label, implode(', ', $leaked) . ' served as plain text: docroot is not restricted to public/ (D23)');
    }

    private function checkFrontControllerReachable(): CheckResult
    {
        $baseUrl = (string) ($this->config['site']['base_url'] ?? '');
        if ($baseUrl === '') {
            return CheckResult::warn('Front controller reachable', 'site.base_url is empty, skipped');
        }

        $result = $this->httpGet(rtrim($baseUrl, '/') . '/');
        if ($result === null) {
            return CheckResult::warn('Front controller reachable', 'could not reach ' . $baseUrl);
        }

        // A 5xx (PHP-FPM down, ffi.enable missing) or 4xx is not "reachable"
        if ((int) $result[0] >= 400) {
            return CheckResult::fail('Front controller reachable', "GET / → HTTP {$result[0]}");
        }

        return CheckResult::pass('Front controller reachable', "GET / → HTTP {$result[0]}");
    }

    /**
     * The archive integrity check (integrity:verify, roadmap phase 23) ran
     * lately and found nothing: read from its kept run reports
     * (data/maintenance/runs/). A warning, never a failure: the check
     * itself is the cron job's, doctor only reminds.
     */
    private function checkIntegrityVerified(): CheckResult
    {
        $label = 'Archive integrity verified in the last 8 days';
        $files = glob((string) ($this->config['paths']['data'] ?? '') . '/maintenance/runs/*.json') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $run = json_decode((string) file_get_contents($file), true);
            if (!\is_array($run) || ($run['task'] ?? null) !== 'integrity:verify') {
                continue;
            }
            $finished = strtotime((string) ($run['finished'] ?? $run['started'] ?? '')) ?: 0;
            $age = (int) floor((time() - $finished) / 86400);
            if ((int) ($run['exit'] ?? 1) !== 0) {
                return CheckResult::warn($label, 'the last run, ' . $age . ' day(s) ago, found ' . (int) ($run['summary']['problems'] ?? 0) . ' problem(s): Admin → Maintenance');
            }
            if ($age > 8) {
                return CheckResult::warn($label, 'last run ' . $age . ' days ago: schedule bin/reporion integrity:verify (cron)');
            }

            return CheckResult::pass($label, 'last run ' . $age . ' day(s) ago, all intact');
        }

        return CheckResult::warn($label, 'never run: bin/reporion integrity:verify');
    }

    /**
     * The periodic rsync backup (D49), only when its schedule is on: the
     * tools and the key are there, and a snapshot is not much older than the
     * interval. A warning: the cron line is the owner's to install.
     */
    /**
     * data/reporion.sqlite (D54 §9): permanent, not a cache, so a missing one
     * beside page files is a failure (doctor never creates an empty one over
     * them: that is db:recover's or a restore's), and SQLite's quick check
     * must pass. Read only.
     */
    private function checkStateDatabase(): CheckResult
    {
        $label = 'State database (data/reporion.sqlite) present and sound';
        $data = (string) ($this->config['paths']['data'] ?? '');
        $file = (string) ($this->config['paths']['state'] ?? $data . '/reporion.sqlite');
        if (!is_file($file)) {
            $pages = $data . '/pages';
            $hasPages = is_dir($pages) && (new \FilesystemIterator($pages, \FilesystemIterator::SKIP_DOTS))->valid();

            return $hasPages
                ? CheckResult::fail($label, 'missing while data/pages/ holds pages: restore it from a backup (data/snapshot/reporion.sqlite or an archive), else bin/reporion db:recover')
                : CheckResult::pass($label, 'not created yet: the first page write creates it');
        }
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $found = array_map('strval', $pdo->query('PRAGMA quick_check')?->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return CheckResult::fail($label, 'does not open: ' . $e->getMessage());
        }

        return $found === ['ok']
            ? CheckResult::pass($label)
            : CheckResult::fail($label, 'SQLite finds it damaged (' . implode('; ', \array_slice($found, 0, 3)) . '): restore it from a backup, then bin/reporion db:recover --dry-run');
    }

    private function checkRsyncBackup(): CheckResult
    {
        $label = 'Rsync backup is current';
        $data = (string) ($this->config['paths']['data'] ?? '');
        $config = RsyncConfig::fromSettings((new InstanceSettings($data))->load());
        if (!$config->enabled) {
            return CheckResult::pass($label, 'schedule off (Admin → Settings → Backup)');
        }
        if (!$config->configured()) {
            return CheckResult::warn($label, 'on, but host, user and directory are not set');
        }
        $daemon = $config->transport === 'daemon';
        if (RsyncBackup::binary('rsync') === '' || (!$daemon && RsyncBackup::binary('ssh') === '')) {
            return CheckResult::warn($label, $daemon ? 'rsync is not installed' : 'rsync or ssh is not installed');
        }
        if (!$daemon && $config->sshKey !== '' && !is_readable($config->sshKey)) {
            return CheckResult::warn($label, 'the SSH key file cannot be read by this user');
        }
        if ($daemon && $config->passwordFile !== '' && !is_readable($config->passwordFile)) {
            return CheckResult::warn($label, 'the rsync password file cannot be read by this user');
        }
        $state = RsyncBackup::state($data);
        if ($state['last_success'] === 0) {
            return CheckResult::warn($label, 'no snapshot yet: bin/reporion backup:rsync, then the cron line (Admin → Maintenance)');
        }
        $age = (int) floor((time() - $state['last_success']) / 3600);
        if ($age > $config->intervalHours * 2 + 1) {
            return CheckResult::warn($label, 'last snapshot ' . $age . ' h ago (every ' . $config->intervalHours . ' h): is the cron line installed?');
        }

        return CheckResult::pass($label, 'last snapshot ' . $age . ' h ago');
    }

    /** The programs the library reads files with (docs/library.md): a missing one only narrows what it takes */
    private function checkLibraryTools(): CheckResult
    {
        $label = 'Library can read uploaded files';
        $tools = \is_array($this->config['library']['tools'] ?? null) ? array_filter($this->config['library']['tools'], 'is_string') : [];
        $found = (new Extractor(sys_get_temp_dir(), $tools))->check();
        $cannot = [];
        if (!$found['pdftotext'] || !$found['pdfinfo']) {
            $cannot[] = 'PDF (install poppler-utils)';
        }
        if (!$found['pandoc']) {
            $cannot[] = 'Word, OpenDocument, RTF, HTML and EPUB (install pandoc)';
        }
        if (!$found['soffice']) {
            // antiword / catdoc read the old binary files as text when LibreOffice is not there
            $light = array_keys(array_filter(['.doc' => $found['antiword'] || $found['catdoc'], '.xls' => $found['xls2csv'], '.ppt' => $found['catppt']]));
            $cannot[] = $light === []
                ? '.doc and office slides and sheets (install LibreOffice, or antiword and catdoc for .doc, .xls and .ppt as text)'
                : 'office slides and sheets but ' . implode(', ', $light) . ' as text (install LibreOffice)';
        }
        // Scans (phase 8): rendered by pdftoppm, read by tesseract in Romanian and English
        $extractor = new Extractor(sys_get_temp_dir(), $tools);
        $missingLanguages = $found['tesseract'] ? $extractor->missingOcrLanguages() : null;
        if (!$found['tesseract'] || !$found['pdftoppm']) {
            $cannot[] = 'scanned PDFs (install tesseract-ocr tesseract-ocr-ron tesseract-ocr-eng' . ($found['pdftoppm'] ? '' : ' and poppler-utils') . ')';
        } elseif ($missingLanguages !== null && $missingLanguages !== []) {
            $cannot[] = 'scanned PDFs in every language (tesseract lacks ' . implode(', ', $missingLanguages) . ': install tesseract-ocr-' . implode(' tesseract-ocr-', $missingLanguages) . ')';
        }
        if ($cannot !== []) {
            return CheckResult::warn($label, 'not ' . implode('; ', $cannot));
        }

        return CheckResult::pass($label, $found['pdftohtml'] ? 'every type, scans by OCR' : 'every type, scans by OCR (no pdftohtml: titles are guessed from the text only)');
    }

    /** The online lookups (Admin → Settings → Library) need PHP to open an https address; without it every lookup only says it could not be reached */
    private function checkLibraryLookup(): CheckResult
    {
        $label = 'Library online lookups (CrossRef, PubMed) and web clip';
        $on = array_keys(array_filter(['lookups' => $this->config['library']['lookup'] ?? false, 'web clip' => $this->config['library']['web_clip'] ?? false], static fn (mixed $v): bool => $v === true));
        if ($on === []) {
            return CheckResult::pass($label, 'off');
        }
        $what = implode(' and ', $on) . ' on';
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return CheckResult::warn($label, $what . ', but PHP\'s allow_url_fopen is off: nothing can be fetched');
        }
        if (!\in_array('https', stream_get_wrappers(), true)) {
            return CheckResult::warn($label, $what . ', but PHP has no https support (the openssl extension): nothing can be fetched');
        }

        return CheckResult::pass($label, $what);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function httpGet(string $url): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || !isset($http_response_header[0])) {
            return null;
        }

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) !== 1) {
            return null;
        }

        return [(int) $m[1], $body];
    }
}

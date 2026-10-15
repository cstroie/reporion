<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use DateTimeZone;
use FFI;
use PDO;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Service\InstanceSettings;
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
            $this->checkTemplateShape(),
            $this->checkExamTemplates(),
            $this->checkLocalConfigIsStrict(),
            $this->checkDocrootExposure(),
            $this->checkFrontControllerReachable(),
            $this->checkIntegrityVerified(),
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
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->getFilename() !== 'current.md' || str_contains($item->getPath(), '/rev')) {
                continue;
            }
            try {
                [$frontmatter] = DocumentFormat::parse((string) file_get_contents($item->getPathname()));
            } catch (Throwable) {
                continue; // an unparseable page is integrity:verify's to report
            }
            $meta = \is_file($item->getPath() . '/meta.json') ? json_decode((string) file_get_contents($item->getPath() . '/meta.json'), true) : null;
            if (\is_array($meta) && ($meta['status'] ?? null) === 'signed') {
                continue;
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
        $walk = static function (string $dir) use (&$walk, &$total, &$off): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'rev' || $entry === 'snippets' || !is_dir($dir . '/' . $entry)) {
                    continue;
                }
                $file = $dir . '/' . $entry . '/current.md';
                if (is_file($file)) {
                    try {
                        [$frontmatter, $body] = DocumentFormat::parse((string) file_get_contents($file));
                        ++$total;
                        $off += TemplateHeadings::problems($frontmatter, $body) === [] ? 0 : 1;
                    } catch (\Throwable) {
                        // an unparseable page is integrity:verify's to report
                    }
                }
                $walk($dir . '/' . $entry);
            }
        };
        $walk($root);

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

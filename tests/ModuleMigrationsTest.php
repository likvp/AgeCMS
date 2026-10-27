<?php
declare(strict_types=1);

require_once __DIR__ . '/support/ModuleFixture.php';

use AgeCMS\{Database, ModuleMigrator, SkillRegistry};

/** Module migrations (E1): once, atomic, signed, only on the module's own tables. */
final class ModuleMigrationsTest extends TestCase
{
    private function boot(string $module): array
    {
        SkillRegistry::reset();
        Database::reset();
        SkillRegistry::boot();
        return SkillRegistry::modules()[$module];
    }

    private function has(string $table): bool
    {
        return Database::value('SELECT COUNT(*) FROM sqlite_master WHERE name = ?', [$table]) > 0;
    }

    private function log(): string
    {
        return (string)@file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
    }

    public function testMigrationsRunOnceAndNewFilesRunLater(): void
    {
        $this->freshSite();
        ModuleFixture::make('mg_once', [], [
            'migrations/001_items.sql' => "-- items of the module\nCREATE TABLE mod_mg_once_items (id INTEGER PRIMARY KEY, title TEXT NOT NULL);\nINSERT INTO mod_mg_once_items (title) VALUES ('a;b');\n/* second note */\nCREATE INDEX mod_mg_once_title ON mod_mg_once_items (title);",
        ]);
        $this->assertSame('ok', $this->boot('mg_once')['status']);
        $this->assertTrue($this->has('mod_mg_once_items'));
        $this->assertSame('a;b', Database::value('SELECT title FROM mod_mg_once_items'), 'a semicolon inside a value does not split the statement');
        $this->assertSame(1, ModuleMigrator::version('mg_once'));

        // A second start would fail on CREATE TABLE if the file ran again.
        $this->assertSame('ok', $this->boot('mg_once')['status']);
        $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM mod_mg_once_items'));
        $this->assertSame(1, ModuleMigrator::version('mg_once'));

        file_put_contents(AGECMS_MODULES . '/mg_once/migrations/002_more.sql', 'ALTER TABLE mod_mg_once_items ADD COLUMN note TEXT NOT NULL DEFAULT "";');
        ModuleFixture::resign('mg_once');
        $this->assertSame('ok', $this->boot('mg_once')['status']);
        $this->assertSame(2, ModuleMigrator::version('mg_once'));
        $this->assertContains('note', (string)Database::value("SELECT sql FROM sqlite_master WHERE name = 'mod_mg_once_items'"));
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM module_migrations WHERE module = 'mg_once' AND file = 'migrations/001_items.sql'"));
        $this->assertSame(0, ModuleMigrator::version('telegram_leads'), 'the schema state is kept per module, apart from the core schema');
    }

    public function testAFailureRollsEverythingBackDisablesTheModuleAndIsNotRetried(): void
    {
        $this->freshSite();
        ModuleFixture::make('mg_fail', [], [
            'migrations/001_a.sql' => 'CREATE TABLE mod_mg_fail_a (id INTEGER PRIMARY KEY);',
            'migrations/002_b.sql' => 'CREATE TABLE mod_mg_fail_b (id INTEGER PRIMARY KEY); NOT VALID SQL;',
        ]);
        $info = $this->boot('mg_fail');
        $this->assertSame('blocked', $info['status']);
        $this->assertContains('migration failed', $info['reason']);
        $this->assertSame(false, $this->has('mod_mg_fail_a'), 'the first file is rolled back with the second');
        $this->assertSame(false, $this->has('mod_mg_fail_b'));
        $this->assertSame(0, ModuleMigrator::version('mg_fail'));
        $this->assertContains('module.migration_failed', $this->log());
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM module_state WHERE module = 'mg_fail'"));

        $again = $this->boot('mg_fail');
        $this->assertContains('migration failed earlier', $again['reason']);
        $this->assertSame(1, substr_count($this->log(), 'module.migration_failed'), 'not tried again on every request');

        // A fixed module is signed again: the new manifest allows a new attempt.
        file_put_contents(AGECMS_MODULES . '/mg_fail/migrations/002_b.sql', 'CREATE TABLE mod_mg_fail_b (id INTEGER PRIMARY KEY);');
        ModuleFixture::resign('mg_fail');
        $this->assertSame('ok', $this->boot('mg_fail')['status']);
        $this->assertTrue($this->has('mod_mg_fail_a') && $this->has('mod_mg_fail_b'));
        $this->assertSame(2, ModuleMigrator::version('mg_fail'));
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM module_state WHERE module = 'mg_fail'"));
    }

    public function testMigrationsCannotTouchTheCore(): void
    {
        $this->freshSite();
        $this->createPage('keep');
        $bad = [
            'drop' => 'DROP TABLE pages;',
            'insert' => "INSERT INTO settings (key, value) VALUES ('evil', '1');",
            'update' => "UPDATE pages SET title = 'x';",
            'delete' => 'DELETE FROM users;',
            'alter' => 'ALTER TABLE pages ADD COLUMN evil TEXT;',
            'other' => 'CREATE TABLE evil_table (id INTEGER);',
            'wrongprefix' => 'CREATE TABLE mod_other_x (id INTEGER);',
            'index' => 'CREATE INDEX mod_mg_idx_i ON pages (title);',
            'trigger' => 'CREATE TRIGGER mod_mg_trigger_t AFTER INSERT ON pages BEGIN DELETE FROM pages; END;',
            'attach' => "ATTACH DATABASE '/tmp/x.db' AS other;",
            'pragma' => 'PRAGMA writable_schema = 1;',
            'temp' => 'CREATE TEMP TABLE mod_mg_temp_t (id INTEGER);',
            'tx' => 'COMMIT;',
            'schema' => 'DROP TABLE main.pages;',
            'comment' => "/* CREATE TABLE mod_mg_comment_t (id INTEGER); */ DROP TABLE users;",
            'hidden' => "CREATE TABLE mod_mg_hidden_t (id INTEGER); DROP TABLE leads;",
        ];
        foreach ($bad as $key => $sql) {
            $name = 'mg_' . $key;
            $sql = str_replace(['mod_mg_idx_i', 'mod_mg_trigger_t', 'mod_mg_temp_t', 'mod_mg_comment_t', 'mod_mg_hidden_t'], ["mod_{$name}_i", "mod_{$name}_t", "mod_{$name}_tt", "mod_{$name}_c", "mod_{$name}_h"], $sql);
            ModuleFixture::make($name, [], ['migrations/001_bad.sql' => $sql]);
            $info = $this->boot($name);
            $this->assertSame('blocked', $info['status'], $key);
            $this->assertContains('migration failed', $info['reason'], $key);
        }
        $this->assertTrue($this->has('pages') && $this->has('users') && $this->has('leads') && $this->has('settings'));
        $this->assertSame('Page keep', Database::value("SELECT title FROM pages WHERE slug = 'keep'"));
        $this->assertSame(false, $this->has('evil_table'));
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM settings WHERE key = 'evil'"));
        $this->assertSame(false, $this->has('mod_mg_hidden_h'), 'a statement after a harmless one is checked as well');
    }

    public function testASignedMigrationThatChangedAfterItRanBlocksTheModule(): void
    {
        $this->freshSite();
        ModuleFixture::make('mg_edit', [], ['migrations/001_t.sql' => 'CREATE TABLE mod_mg_edit_t (id INTEGER PRIMARY KEY);']);
        $this->assertSame('ok', $this->boot('mg_edit')['status']);

        file_put_contents(AGECMS_MODULES . '/mg_edit/migrations/001_t.sql', 'CREATE TABLE mod_mg_edit_t (id INTEGER PRIMARY KEY, extra TEXT);');
        ModuleFixture::resign('mg_edit');
        $info = $this->boot('mg_edit');
        $this->assertSame('blocked', $info['status']);
        $this->assertContains('was changed afterwards', $info['reason']);
    }

    public function testOnlyFilesCoveredByTheSignatureAreRun(): void
    {
        $this->freshSite();
        ModuleFixture::make('mg_sig', [], ['migrations/001_t.sql' => 'CREATE TABLE mod_mg_sig_t (id INTEGER PRIMARY KEY);']);
        // Dropped in after signing: not listed in the manifest, so it never runs.
        file_put_contents(AGECMS_MODULES . '/mg_sig/migrations/002_extra.sql', 'CREATE TABLE mod_mg_sig_extra (id INTEGER PRIMARY KEY);');
        $this->assertSame('ok', $this->boot('mg_sig')['status']);
        $this->assertTrue($this->has('mod_mg_sig_t'));
        $this->assertSame(false, $this->has('mod_mg_sig_extra'));
        $this->assertSame(1, ModuleMigrator::version('mg_sig'));

        // A listed file that changed after signing blocks the module before anything runs.
        ModuleFixture::make('mg_tamper', [], ['migrations/001_t.sql' => 'CREATE TABLE mod_mg_tamper_t (id INTEGER PRIMARY KEY);']);
        file_put_contents(AGECMS_MODULES . '/mg_tamper/migrations/001_t.sql', 'DROP TABLE pages;');
        $info = $this->boot('mg_tamper');
        $this->assertSame('blocked', $info['status']);
        $this->assertContains('File changed after signing', $info['reason']);
        $this->assertTrue($this->has('pages'));
        $this->assertSame(false, $this->has('mod_mg_tamper_t'));

        // A manifest that lists a migration outside migrations/ or without a hash is refused.
        ModuleFixture::make('mg_path', [], [], ['migrations' => ['Module.php']]);
        $manifest = json_decode((string)file_get_contents(AGECMS_MODULES . '/mg_path/manifest.json'), true);
        $manifest['migrations'] = ['Module.php'];
        file_put_contents(AGECMS_MODULES . '/mg_path/manifest.json', json_encode($manifest));
        $this->assertSame('blocked', $this->boot('mg_path')['status'], 'the signature no longer matches the edited manifest');
    }

    public function testASiteWithoutMigrationsGetsNoBookkeepingTables(): void
    {
        $this->freshSite();
        SkillRegistry::reset();
        SkillRegistry::boot();
        $this->assertSame(false, $this->has('module_migrations'), 'nothing is created for modules that have no migrations');
        $this->assertSame(false, $this->has('module_state'));
    }
}

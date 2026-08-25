<?php

declare(strict_types=1);

/**
 * Conservative content migration for Patriam's Rules installation.
 *
 * The legacy module has no upgrade hook. This class is therefore shared by onEnable() and the
 * module's CLI migrator. Existing rows are changed only when every shipped vendor field still
 * matches the 1.8.6 sample. Custom rows are retained, even when they use the same display name as
 * one of the Patriam defaults.
 */
final class Rules_Migration
{
    private const VENDOR_MESSAGE = '<div style="text-align: center;"><strong><span style="font-size:18px">Welcome to Skyfall&#39;s rules page!</span></strong><br />Click on the tabs above to see the different sections of the rules.<br /><br /><strong>Note:</strong>&nbsp;You can change this message and the rules lists on the tabs above in StaffCP -&gt; Rules. All of the rules lists are fully customizable via a text editor, so you can create unlimited rules, include any type of punishments, and format it all however you want.<br /><br />Useful links:</div>';

    private const VENDOR_BEDWARS_RULES = '&lt;div style=&quot;text-align: center;&quot;&gt;&lt;strong&gt;&lt;span style=&quot;font-size:18px&quot;&gt;Bedwars Server Rules:&lt;/span&gt;&lt;/strong&gt;&lt;/div&gt;&lt;br /&gt;1. No hacking or unfair advantages of any kind.&lt;br /&gt;&lt;br /&gt;2. No cross teaming in any bedwars mode.&lt;br /&gt;&lt;br /&gt;3. No team griefing&lt;br /&gt;&lt;br /&gt;&lt;span style=&quot;color:#c0392b&quot;&gt;&lt;strong&gt;Punishment:&lt;/strong&gt;&lt;/span&gt; Breaking any of these rules will result in a temporary ban for 30 days.';

    private const VENDOR_CHAT_RULES = '&lt;div style=&quot;text-align: center;&quot;&gt;&lt;strong&gt;&lt;span style=&quot;font-size:18px&quot;&gt;Chat Rules:&lt;/span&gt;&lt;/strong&gt;&lt;/div&gt;&lt;br /&gt;1. No swearing&lt;br /&gt;&lt;br /&gt;2. No bullying, put-downs, or other harassment&lt;br /&gt;&lt;br /&gt;3. No spamming&lt;br /&gt;&lt;br /&gt;&lt;span style=&quot;color:#c0392b&quot;&gt;&lt;strong&gt;Punishment:&lt;/strong&gt;&lt;/span&gt; Breaking any of these rules can result in a temporary/permanent mute';

    private const PATRIAM_MESSAGE = '<div style="text-align: center;"><strong><span style="font-size:18px">Patriam Community Rules</span></strong><br />These rules apply across Patriam and its community spaces. Select a category for its current policy.<br /><br />The summaries are editable in StaffCP as the server develops. Use the buttons below to report a player or appeal a punishment.</div>';

    /** @var array<string,string> */
    private const TABLES = [
        'settings' => 'rules_settings',
        // The misspelling is part of the released database contract and must remain compatible.
        'categories' => 'rules_catagories',
        'buttons' => 'rules_buttons',
    ];

    /**
     * Inspect or apply the current Patriam content migration.
     *
     * @return array{ready:bool,applied:bool,actions:array<int,array<string,mixed>>,remaining:array<int,array<string,mixed>>}
     */
    public static function migrate(bool $apply = false): array
    {
        $db = DB::getInstance();
        self::assertBaseTables($db);

        if (!$apply) {
            $actions = self::plan(self::databaseSnapshot($db));
            return [
                'ready' => !$actions,
                'applied' => false,
                'actions' => $actions,
                'remaining' => $actions,
            ];
        }

        $db->beginTransaction();
        try {
            // Re-plan only after locking every current row and insertion gap. A StaffCP edit made
            // after a dry-run preview must either happen before this snapshot and be preserved by
            // plan(), or wait until the verified migration commits. Never apply a stale row-id plan.
            self::lockBaseRows($db);
            $actions = self::plan(self::databaseSnapshot($db));
            if (!$actions) {
                $db->commitTransaction();
                return [
                    'ready' => true,
                    'applied' => false,
                    'actions' => [],
                    'remaining' => [],
                ];
            }

            self::applyActions($db, $actions);
            $remaining = self::plan(self::databaseSnapshot($db));
            if ($remaining) {
                throw new RuntimeException(
                    'Rules migration verification found ' . count($remaining) . ' pending action(s).'
                );
            }
            $db->commitTransaction();
        } catch (Throwable $exception) {
            $db->rollBackTransaction();
            throw $exception;
        }

        return [
            'ready' => true,
            'applied' => true,
            'actions' => $actions,
            'remaining' => [],
        ];
    }

    /**
     * Lock all existing rows and the surrounding primary-key ranges before the apply snapshot.
     *
     * NamelessMC's singleton DB uses the standard nl2_ prefix. These tables are created through
     * DB::createTable(), which uses InnoDB, so the full ordered locking reads also prevent a
     * concurrent category/button insert from racing an ensure action.
     */
    private static function lockBaseRows($db): void
    {
        foreach (self::TABLES as $table) {
            $query = $db->query("SELECT id FROM nl2_$table ORDER BY id FOR UPDATE");
            if ($query === false || $query->error()) {
                throw new RuntimeException("Could not lock nl2_$table for the Rules migration.");
            }
        }
    }

    /**
     * Build a deterministic migration plan from a database-shaped snapshot.
     *
     * This public pure seam keeps the destructive-scope contract independently testable without a
     * NamelessMC installation or database connection.
     *
     * @param array{settings?:array<int,array<string,mixed>>,categories?:array<int,array<string,mixed>>,buttons?:array<int,array<string,mixed>>} $snapshot
     * @return array<int,array<string,mixed>>
     */
    public static function plan(array $snapshot): array
    {
        $working = self::normaliseSnapshot($snapshot);
        $actions = [];

        self::replaceExactSample(
            $working['settings'],
            [
                'name' => 'rules_message',
                'value' => self::VENDOR_MESSAGE,
            ],
            [
                'name' => 'rules_message',
                'value' => self::PATRIAM_MESSAGE,
            ],
            ['name'],
            'settings',
            "replace the exact Skyfall introduction",
            $actions
        );
        self::ensureByFields(
            $working['settings'],
            ['name' => 'rules_message'],
            ['name' => 'rules_message', 'value' => self::PATRIAM_MESSAGE],
            'settings',
            'add the Patriam introduction because no rules_message row exists',
            $actions
        );

        $categories = self::categoryDefaults();
        self::replaceExactSample(
            $working['categories'],
            [
                'name' => 'Bedwars',
                'icon' => '<i class="fas fa-bed"></i>',
                'rules' => self::VENDOR_BEDWARS_RULES,
            ],
            $categories[0],
            ['name'],
            'categories',
            "replace the exact Bedwars sample with Community",
            $actions
        );
        self::replaceExactSample(
            $working['categories'],
            [
                'name' => 'Chat',
                'icon' => '<i class="fas fa-comments"></i>',
                'rules' => self::VENDOR_CHAT_RULES,
            ],
            $categories[1],
            ['name'],
            'categories',
            "replace the exact vendor Chat sample",
            $actions
        );

        foreach ($categories as $category) {
            self::ensureByFields(
                $working['categories'],
                ['name' => $category['name']],
                $category,
                'categories',
                "add missing Patriam category '{$category['name']}'",
                $actions
            );
        }

        self::removeExactRows(
            $working['buttons'],
            [
                'name' => 'Bans',
                'link' => 'https://www.lemoncloud.org/bans/',
            ],
            'buttons',
            'remove the exact vendor Bans button',
            $actions
        );

        foreach (self::buttonMigrations() as $buttonMigration) {
            self::replaceExactSample(
                $working['buttons'],
                $buttonMigration['vendor'],
                $buttonMigration['target'],
                ['name', 'link'],
                'buttons',
                $buttonMigration['summary'],
                $actions
            );
            self::ensureByFields(
                $working['buttons'],
                $buttonMigration['target'],
                $buttonMigration['target'],
                'buttons',
                "add canonical Patriam button '{$buttonMigration['target']['name']}'",
                $actions
            );
        }

        return $actions;
    }

    /**
     * Database-free contract tests used by the deployed CLI entry point.
     *
     * @return array<int,string> failure descriptions
     */
    public static function selfTest(): array
    {
        $failures = [];

        $fresh = ['settings' => [], 'categories' => [], 'buttons' => []];
        $freshActions = self::plan($fresh);
        self::check(
            count(array_filter($freshActions, static fn (array $action): bool => $action['operation'] === 'insert')) === 11,
            'a fresh installation does not plan exactly one message, eight categories and two buttons',
            $failures
        );
        self::check(
            self::plan(self::simulate($fresh, $freshActions)) === [],
            'the fresh-install plan is not idempotent',
            $failures
        );

        $vendor = self::vendorSnapshot();
        $vendorActions = self::plan($vendor);
        $migratedVendor = self::simulate($vendor, $vendorActions);
        self::check(
            self::plan($migratedVendor) === [],
            'the exact vendor migration is not idempotent',
            $failures
        );
        self::check(
            !self::containsExact($migratedVendor['categories'], [
                'name' => 'Bedwars',
                'icon' => '<i class="fas fa-bed"></i>',
                'rules' => self::VENDOR_BEDWARS_RULES,
            ]),
            'the exact Bedwars sample survives migration',
            $failures
        );
        self::check(
            !self::containsExact($migratedVendor['buttons'], [
                'name' => 'Bans',
                'link' => 'https://www.lemoncloud.org/bans/',
            ]),
            'the exact vendor Bans button survives migration',
            $failures
        );

        $collision = self::vendorSnapshot();
        $collision['categories'][] = [
            'id' => 90,
            'name' => 'Community',
            'icon' => '<i class="fas fa-heart"></i>',
            'rules' => '&lt;p&gt;Custom Community policy&lt;/p&gt;',
        ];
        $collision['buttons'][] = [
            'id' => 91,
            'name' => 'Player Report',
            'link' => '/cases/new/report',
        ];
        $collisionActions = self::plan($collision);
        $migratedCollision = self::simulate($collision, $collisionActions);
        self::check(
            !self::targetsId($collisionActions, 'categories', 90)
                && !self::targetsId($collisionActions, 'buttons', 91),
            'an existing target row was mutated while removing its exact vendor duplicate',
            $failures
        );
        self::check(
            !self::containsExact($migratedCollision['categories'], [
                'name' => 'Bedwars',
                'icon' => '<i class="fas fa-bed"></i>',
                'rules' => self::VENDOR_BEDWARS_RULES,
            ]) && !self::containsExact($migratedCollision['buttons'], [
                'name' => 'Player Report',
                'link' => 'https://hypixel.net/forums/report-rule-breakers.37/',
            ]),
            'an exact vendor duplicate was retained beside its existing target',
            $failures
        );
        self::check(
            self::plan($migratedCollision) === [],
            'the existing-target collision plan is not idempotent',
            $failures
        );

        $custom = [
            'settings' => [[
                'id' => 101,
                'name' => 'rules_message',
                'value' => '<p>Custom introduction</p>',
            ]],
            'categories' => [
                [
                    'id' => 201,
                    'name' => 'Bedwars',
                    'icon' => '<i class="fas fa-bed"></i>',
                    'rules' => '&lt;p&gt;Custom Bedwars policy&lt;/p&gt;',
                ],
                [
                    'id' => 202,
                    'name' => 'Chat',
                    'icon' => '<i class="fas fa-comment"></i>',
                    'rules' => '&lt;p&gt;Custom Chat policy&lt;/p&gt;',
                ],
            ],
            'buttons' => [
                ['id' => 301, 'name' => 'Bans', 'link' => '/custom-bans'],
                ['id' => 302, 'name' => 'Player Report', 'link' => '/custom-report'],
                ['id' => 303, 'name' => 'Ban Appeal', 'link' => '/custom-appeal'],
            ],
        ];
        $customActions = self::plan($custom);
        $migratedCustom = self::simulate($custom, $customActions);

        foreach ([
            ['collection' => 'settings', 'id' => 101],
            ['collection' => 'categories', 'id' => 201],
            ['collection' => 'categories', 'id' => 202],
            ['collection' => 'buttons', 'id' => 301],
            ['collection' => 'buttons', 'id' => 302],
            ['collection' => 'buttons', 'id' => 303],
        ] as $protected) {
            self::check(
                !self::targetsId($customActions, $protected['collection'], $protected['id']),
                "a customized {$protected['collection']} row was targeted for mutation",
                $failures
            );
        }
        self::check(
            self::containsExact($migratedCustom['buttons'], [
                'name' => 'Player Report',
                'link' => '/cases/new/report',
            ]) && self::containsExact($migratedCustom['buttons'], [
                'name' => 'Ban Appeal',
                'link' => '/cases/new/appeal',
            ]),
            'canonical case buttons were not added beside customized buttons',
            $failures
        );
        self::check(
            self::plan($migratedCustom) === [],
            'the customized-row preservation plan is not idempotent',
            $failures
        );

        return $failures;
    }

    /** @return array<int,array{name:string,icon:string,rules:string}> */
    private static function categoryDefaults(): array
    {
        return [
            self::category(
                'Community',
                'fas fa-users',
                'Treat other community members with respect. Harassment, hate speech, threats, impersonation, and deliberate disruption are not permitted.'
            ),
            self::category(
                'Chat',
                'fas fa-comments',
                'Keep public and private communication civil and readable. Do not spam, advertise unrelated services, evade moderation, or share another person\'s private information.'
            ),
            self::category(
                'Roleplay',
                'fas fa-theater-masks',
                'Keep roleplay collaborative and consistent with Patriam\'s setting. Do not powergame, metagame, or force outcomes on another player.'
            ),
            self::category(
                'Building & Land',
                'fas fa-hammer',
                'Build within your rights and the server\'s setting. Do not grief, bypass claims, or damage another player\'s work outside permitted conflict.'
            ),
            self::category(
                'Gameplay & Fair Play',
                'fas fa-balance-scale',
                'Play through intended mechanics. Cheats, harmful exploits, duplication, and automation which bypasses normal play are not permitted.'
            ),
            self::category(
                'Economy & Trading',
                'fas fa-coins',
                'Trade honestly and honour agreed exchanges. Scams, real-money trading, alternate-account abuse, and manipulation of server systems are not permitted.'
            ),
            self::category(
                'PvP & Conflict',
                'fas fa-shield-alt',
                'PvP, raids, sieges, wars, and theft are allowed only where current Patriam rules permit them. Do not evade conflict limits or protections.'
            ),
            self::category(
                'Accounts & Security',
                'fas fa-user-shield',
                'You are responsible for your account. Do not share access, evade punishments with alternate accounts, impersonate staff, or seek another player\'s credentials.'
            ),
        ];
    }

    /** @return array{name:string,icon:string,rules:string} */
    private static function category(string $name, string $icon, string $summary): array
    {
        $html = '<div style="text-align: center;"><strong><span style="font-size:18px">'
            . $name
            . '</span></strong></div><br />'
            . $summary;

        return [
            'name' => $name,
            'icon' => '<i class="' . $icon . '"></i>',
            'rules' => htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }

    /**
     * @return array<int,array{vendor:array{name:string,link:string},target:array{name:string,link:string},summary:string}>
     */
    private static function buttonMigrations(): array
    {
        return [
            [
                'vendor' => [
                    'name' => 'Player Report',
                    'link' => 'https://hypixel.net/forums/report-rule-breakers.37/',
                ],
                'target' => [
                    'name' => 'Player Report',
                    'link' => '/cases/new/report',
                ],
                'summary' => 'replace the exact vendor Player Report button',
            ],
            [
                'vendor' => [
                    'name' => 'Ban Appeal',
                    'link' => 'https://hypixel.net/forums/ban-appeal.36/',
                ],
                'target' => [
                    'name' => 'Ban Appeal',
                    'link' => '/cases/new/appeal',
                ],
                'summary' => 'replace the exact vendor Ban Appeal button',
            ],
        ];
    }

    private static function assertBaseTables($db): void
    {
        $missing = [];
        foreach (self::TABLES as $table) {
            if (!$db->showTables($table)) {
                $missing[] = $table;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Rules base tables are missing: ' . implode(', ', $missing) . '. Install the module first.'
            );
        }
    }

    /** @return array{settings:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>,buttons:array<int,array<string,mixed>>} */
    private static function databaseSnapshot($db): array
    {
        $snapshot = [];
        foreach (self::TABLES as $collection => $table) {
            $query = $db->get($table, ['id', '<>', 0]);
            if ($query === false) {
                throw new RuntimeException("Could not inspect nl2_$table.");
            }

            $snapshot[$collection] = array_map(
                static fn (object $row): array => (array) $row,
                $query->results()
            );
        }

        return self::normaliseSnapshot($snapshot);
    }

    /** @param array<int,array<string,mixed>> $actions */
    private static function applyActions($db, array $actions): void
    {
        foreach ($actions as $action) {
            $table = self::TABLES[$action['collection']];
            switch ($action['operation']) {
                case 'insert':
                    if (!$db->insert($table, $action['fields'])) {
                        throw new RuntimeException("Could not {$action['summary']}.");
                    }
                    break;

                case 'update':
                    if (!$db->update($table, (int) $action['id'], $action['fields'])) {
                        throw new RuntimeException("Could not {$action['summary']}.");
                    }
                    break;

                case 'delete':
                    if ($db->delete($table, (int) $action['id']) === false) {
                        throw new RuntimeException("Could not {$action['summary']}.");
                    }
                    break;

                default:
                    throw new LogicException('Unknown Rules migration operation.');
            }
        }
    }

    /**
     * Replace every exact vendor row. If the target identity already exists, delete only the exact
     * sample instead of manufacturing a duplicate or overwriting the customized target.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $sample
     * @param array<string,mixed> $target
     * @param array<int,string> $identityFields
     * @param array<int,array<string,mixed>> $actions
     */
    private static function replaceExactSample(
        array &$rows,
        array $sample,
        array $target,
        array $identityFields,
        string $collection,
        string $summary,
        array &$actions
    ): void {
        while (($sampleIndex = self::findExactIndex($rows, $sample)) !== null) {
            $sampleId = (int) $rows[$sampleIndex]['id'];
            $targetExists = self::findByFields($rows, $target, $identityFields, $sampleId) !== null;

            if ($targetExists) {
                $actions[] = self::action('delete', $collection, $sampleId, [], $summary);
                array_splice($rows, $sampleIndex, 1);
                continue;
            }

            $actions[] = self::action('update', $collection, $sampleId, $target, $summary);
            $rows[$sampleIndex] = ['id' => $sampleId] + $target;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $match
     * @param array<string,mixed> $fields
     * @param array<int,array<string,mixed>> $actions
     */
    private static function ensureByFields(
        array &$rows,
        array $match,
        array $fields,
        string $collection,
        string $summary,
        array &$actions
    ): void {
        if (self::containsIdentity($rows, $match)) {
            return;
        }

        $actions[] = self::action('insert', $collection, null, $fields, $summary);
        $rows[] = ['id' => self::nextSyntheticId($rows)] + $fields;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $sample
     * @param array<int,array<string,mixed>> $actions
     */
    private static function removeExactRows(
        array &$rows,
        array $sample,
        string $collection,
        string $summary,
        array &$actions
    ): void {
        while (($index = self::findExactIndex($rows, $sample)) !== null) {
            $id = (int) $rows[$index]['id'];
            $actions[] = self::action('delete', $collection, $id, [], $summary);
            array_splice($rows, $index, 1);
        }
    }

    /** @return array<string,mixed> */
    private static function action(
        string $operation,
        string $collection,
        ?int $id,
        array $fields,
        string $summary
    ): array {
        return [
            'operation' => $operation,
            'collection' => $collection,
            'id' => $id,
            'fields' => $fields,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $expected
     */
    private static function findExactIndex(array $rows, array $expected): ?int
    {
        foreach ($rows as $index => $row) {
            if (self::rowMatches($row, $expected, false)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $target
     * @param array<int,string> $fields
     */
    private static function findByFields(
        array $rows,
        array $target,
        array $fields,
        ?int $excludeId = null
    ): ?int {
        $expected = array_intersect_key($target, array_fill_keys($fields, true));
        foreach ($rows as $index => $row) {
            if ($excludeId !== null && (int) $row['id'] === $excludeId) {
                continue;
            }
            if (self::rowMatches($row, $expected, true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $expected */
    private static function rowMatches(array $row, array $expected, bool $caseInsensitiveNames): bool
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            if ($caseInsensitiveNames && $field === 'name') {
                if (strcasecmp((string) $row[$field], (string) $value) !== 0) {
                    return false;
                }
                continue;
            }
            if ((string) $row[$field] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $expected */
    private static function containsIdentity(array $rows, array $expected): bool
    {
        return self::findByFields($rows, $expected, array_keys($expected)) !== null;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $expected */
    private static function containsExact(array $rows, array $expected): bool
    {
        return self::findExactIndex($rows, $expected) !== null;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function nextSyntheticId(array $rows): int
    {
        $minimum = 0;
        foreach ($rows as $row) {
            $minimum = min($minimum, (int) ($row['id'] ?? 0));
        }

        return $minimum - 1;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{settings:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>,buttons:array<int,array<string,mixed>>}
     */
    private static function normaliseSnapshot(array $snapshot): array
    {
        $normalised = [];
        foreach (array_keys(self::TABLES) as $collection) {
            $rows = [];
            foreach (($snapshot[$collection] ?? []) as $row) {
                $row = (array) $row;
                if (!array_key_exists('id', $row)) {
                    throw new InvalidArgumentException("Rules $collection snapshot row has no id.");
                }
                $row['id'] = (int) $row['id'];
                $rows[] = $row;
            }
            usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            $normalised[$collection] = $rows;
        }

        return $normalised;
    }

    /** @return array{settings:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>,buttons:array<int,array<string,mixed>>} */
    private static function vendorSnapshot(): array
    {
        return [
            'settings' => [[
                'id' => 1,
                'name' => 'rules_message',
                'value' => self::VENDOR_MESSAGE,
            ]],
            'categories' => [
                [
                    'id' => 1,
                    'name' => 'Bedwars',
                    'icon' => '<i class="fas fa-bed"></i>',
                    'rules' => self::VENDOR_BEDWARS_RULES,
                ],
                [
                    'id' => 2,
                    'name' => 'Chat',
                    'icon' => '<i class="fas fa-comments"></i>',
                    'rules' => self::VENDOR_CHAT_RULES,
                ],
            ],
            'buttons' => [
                [
                    'id' => 1,
                    'name' => 'Player Report',
                    'link' => 'https://hypixel.net/forums/report-rule-breakers.37/',
                ],
                [
                    'id' => 2,
                    'name' => 'Bans',
                    'link' => 'https://www.lemoncloud.org/bans/',
                ],
                [
                    'id' => 3,
                    'name' => 'Ban Appeal',
                    'link' => 'https://hypixel.net/forums/ban-appeal.36/',
                ],
            ],
        ];
    }

    /**
     * Apply a plan to memory for contract testing only.
     *
     * @param array<string,mixed> $snapshot
     * @param array<int,array<string,mixed>> $actions
     * @return array{settings:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>,buttons:array<int,array<string,mixed>>}
     */
    private static function simulate(array $snapshot, array $actions): array
    {
        $snapshot = self::normaliseSnapshot($snapshot);
        foreach ($actions as $action) {
            $collection = $action['collection'];
            if ($action['operation'] === 'insert') {
                $snapshot[$collection][] = [
                    'id' => self::nextSyntheticId($snapshot[$collection]),
                ] + $action['fields'];
                continue;
            }

            foreach ($snapshot[$collection] as $index => $row) {
                if ((int) $row['id'] !== (int) $action['id']) {
                    continue;
                }

                if ($action['operation'] === 'delete') {
                    array_splice($snapshot[$collection], $index, 1);
                } elseif ($action['operation'] === 'update') {
                    $snapshot[$collection][$index] = ['id' => (int) $row['id']] + $action['fields'];
                }
                break;
            }
        }

        return self::normaliseSnapshot($snapshot);
    }

    /** @param array<int,array<string,mixed>> $actions */
    private static function targetsId(array $actions, string $collection, int $id): bool
    {
        foreach ($actions as $action) {
            if ($action['collection'] === $collection
                && $action['operation'] !== 'insert'
                && (int) $action['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,string> $failures */
    private static function check(bool $condition, string $failure, array &$failures): void
    {
        if (!$condition) {
            $failures[] = $failure;
        }
    }
}

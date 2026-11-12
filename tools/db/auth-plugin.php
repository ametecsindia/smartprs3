<?php

declare(strict_types=1);

/**
 * HOW TO SAY "CREATE THIS ACCOUNT" ON THE SERVER YOU ARE ACTUALLY TALKING TO.
 *
 * Two servers answer to `mysql:` and they disagree about this exact statement:
 *
 *   MySQL 8      CREATE USER 'x'@'h' IDENTIFIED WITH caching_sha2_password BY 'pw'
 *   MariaDB      CREATE USER 'x'@'h' IDENTIFIED BY 'pw'
 *
 * MariaDB rejects `IDENTIFIED WITH <plugin> BY` outright — it spells the same idea
 * `IDENTIFIED VIA <plugin> USING ...` — so a tool that names the plugin
 * unconditionally works on one and is a syntax error on the other. And a tool that
 * names it on NEITHER is how this project got here: `IDENTIFIED BY` alone leaves
 * the plugin to the server default, and a Laragon whose my.ini still says
 * `default_authentication_plugin = mysql_native_password` against MySQL 8.4 —
 * which REMOVED that plugin — creates an account the server cannot authenticate.
 * CREATE USER succeeds, the setup script reports success, and the application
 * fails much later with SQLSTATE[HY000] [1524].
 *
 * So: name the plugin where naming it is both possible and necessary, and stay
 * quiet where the server's own default is the right answer. Asked of the server,
 * every time, rather than remembered.
 *
 * Found by running the tool. It was written against MySQL, tested on MariaDB, and
 * failed on line one of the first real execution — which is the argument for
 * running a setup tool once against a live server before shipping it, rather than
 * reading it twice.
 */

/**
 * The `IDENTIFIED ...` clause this server accepts.
 *
 * @param PDO $pdo an admin connection
 * @return array{clause: string, plugin: string} the SQL fragment, and the plugin
 *     it results in, for reporting. `plugin` is 'server default' when the server
 *     chooses — which is only returned when that default is known to be loadable.
 */
function smartprs_identified_clause(PDO $pdo, string $password): array
{
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $quoted = $pdo->quote($password);

    // MariaDB: the default plugin is present and works, and the MySQL spelling for
    // naming one is a syntax error here. Nothing to choose.
    if (stripos($version, 'mariadb') !== false) {
        return ['clause' => 'IDENTIFIED BY ' . $quoted, 'plugin' => 'server default (MariaDB)'];
    }

    $active = $pdo->query("SELECT PLUGIN_NAME FROM information_schema.PLUGINS "
        . "WHERE PLUGIN_TYPE = 'AUTHENTICATION' AND PLUGIN_STATUS = 'ACTIVE'")
        ->fetchAll(PDO::FETCH_COLUMN);

    // caching_sha2_password first: the MySQL 8 default, and the only one of the two
    // that still exists in 8.4.
    foreach (['caching_sha2_password', 'mysql_native_password'] as $candidate) {
        if (in_array($candidate, $active, true)) {
            return [
                'clause' => sprintf('IDENTIFIED WITH %s BY %s', $candidate, $quoted),
                'plugin' => $candidate,
            ];
        }
    }

    // REFUSED, NOT DEFAULTED. Falling back to `IDENTIFIED BY` here would be
    // reintroducing the exact defect this file exists for, in the branch that
    // handles it.
    throw new RuntimeException(
        "This MySQL server ({$version}) loads neither caching_sha2_password nor "
        . "mysql_native_password, so there is no authentication method to create an account with.\n"
        . 'Active authentication plugins: ' . (implode(', ', $active) ?: 'none') . "\n"
        . 'Refused rather than left to the server default: a default this tool cannot name is how '
        . 'an account gets created with a plugin the server will not honour, which fails later and '
        . 'somewhere else.');
}

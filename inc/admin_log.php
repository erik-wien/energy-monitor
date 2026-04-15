<?php
/**
 * inc/admin_log.php — Data access for the admin Log tab.
 *
 * Reads auth_log via MySQLi (auth DB). Do not use PDO here — auth_log
 * and auth_accounts live in the auth database, not the Energie data DB.
 */

/**
 * Return a paginated, filtered slice of auth_log rows joined with auth_accounts
 * for username lookup.
 *
 * $filters keys (all optional, all strings):
 *   app      — exact match on origin
 *   context  — exact match on context
 *   user     — substring match on auth_accounts.username
 *   from     — 'YYYY-MM-DD' inclusive lower bound on logTime
 *   to       — 'YYYY-MM-DD' inclusive upper bound on logTime
 *   q        — substring match on activity
 *   fail     — truthy → context LIKE '%fail%'
 *
 * @return array{rows: list<array>, total: int}
 */
function admin_log_list(mysqli $con, int $page, int $perPage, array $filters): array
{
    $where  = [];
    $types  = '';
    $params = [];

    if (!empty($filters['app'])) {
        $where[] = 'l.origin = ?';
        $types  .= 's';
        $params[] = $filters['app'];
    }
    if (!empty($filters['context'])) {
        $where[] = 'l.context = ?';
        $types  .= 's';
        $params[] = $filters['context'];
    }
    if (!empty($filters['user'])) {
        $where[] = 'a.username LIKE ?';
        $types  .= 's';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['user']) . '%';
    }
    if (!empty($filters['from'])) {
        $where[] = 'l.logTime >= ?';
        $types  .= 's';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 'l.logTime < (? + INTERVAL 1 DAY)';
        $types  .= 's';
        $params[] = $filters['to'] . ' 00:00:00';
    }
    if (!empty($filters['q'])) {
        $where[] = 'l.activity LIKE ?';
        $types  .= 's';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
    }
    if (!empty($filters['fail'])) {
        $where[] = "l.context LIKE '%fail%'";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $offset   = ($page - 1) * $perPage;

    $tableLog      = AUTH_DB_PREFIX . 'auth_log';
    $tableAccounts = AUTH_DB_PREFIX . 'auth_accounts';

    $sql = "SELECT l.id, l.logTime, l.origin, l.context, l.activity,
                   INET_NTOA(l.ipAdress) AS ip, a.username
            FROM {$tableLog} l
            LEFT JOIN {$tableAccounts} a ON a.id = l.idUser
            {$whereSql}
            ORDER BY l.logTime DESC, l.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $con->prepare($sql);
    $typesWithPage = $types . 'ii';
    $paramsWithPage = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($typesWithPage, ...$paramsWithPage);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'       => (int) $r['id'],
            'logTime'  => $r['logTime'],
            'origin'   => $r['origin'],
            'context'  => $r['context'],
            'activity' => $r['activity'],
            'ip'       => $r['ip'],
            'username' => $r['username'],
        ];
    }
    $stmt->close();

    $countSql = "SELECT COUNT(*) FROM {$tableLog} l
                 LEFT JOIN {$tableAccounts} a ON a.id = l.idUser
                 {$whereSql}";
    $cstmt = $con->prepare($countSql);
    if ($params) {
        $cstmt->bind_param($types, ...$params);
    }
    $cstmt->execute();
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['rows' => $rows, 'total' => (int) $total];
}

/**
 * Distinct non-empty `origin` values in auth_log, sorted. Drives the App filter.
 *
 * @return list<string>
 */
function admin_log_distinct_apps(mysqli $con): array
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $out   = [];
    if ($res = $con->query("SELECT DISTINCT origin FROM {$table} WHERE origin <> '' ORDER BY origin")) {
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        $res->free();
    }
    return $out;
}

/**
 * Distinct non-empty `context` values in auth_log, sorted. Drives the Kontext filter.
 *
 * @return list<string>
 */
function admin_log_distinct_contexts(mysqli $con): array
{
    $table = AUTH_DB_PREFIX . 'auth_log';
    $out   = [];
    if ($res = $con->query("SELECT DISTINCT context FROM {$table} WHERE context <> '' ORDER BY context")) {
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        $res->free();
    }
    return $out;
}
